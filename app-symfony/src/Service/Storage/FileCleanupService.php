<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\Conversion;
use App\Entity\User;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Авто-удаление устаревших файлов и связанных строк БД (задача
 * file-cleanup-24h-cron). Единый источник логики: один проход удаляет вместе
 *  - входной S3-объект (бакет `${S3_BUCKET_PREFIX}-inputs`),
 *  - выходной S3-объект, если он есть (бакет `${S3_BUCKET_PREFIX}-results`),
 *  - строки БД `Conversion` + её `FileStorage` (input/output).
 *
 * Порог хранения — ПЕР-КОНВЕРСИЯ, разрешается по приоритету осей (первая ось с
 * настроенным значением > 0 выигрывает):
 *   1. status   — statusRetentionHours[status->value]     (по умолч. failed/expired=24)
 *   2. tariff   — tariffRetentionHours[tariff]            (guest=24, free=0→skip, paid=720)
 *   3. category — categoryRetentionHours[category->value] (video=48)
 *   4. fallback — defaultRetentionHours (FILE_RETENTION_HOURS, 240)
 * Значение <= 0 = «ось не настроена, пропустить». `max(1, …)` применяется ТОЛЬКО
 * к финально разрешённому значению. Тариф из User: isGuest→'guest';
 * иначе plan==='free'→'free'; иначе→'paid'.
 *
 * Статус `Processing` в очистку не попадает (гейт запроса) — задача в работе,
 * зависшие Processing вскрывает отдельный алерт (ConversionRepository::findStuck).
 *
 * Отбор по `Conversion.createdAt` — надёжный сигнал (в отличие от nullable
 * `FileStorage.expiresAt`). Запускается по расписанию (Symfony Scheduler, ежечасно).
 *
 * Устойчивость к сбоям S3: если объект уже удалён / удаление упало — логируем и
 * продолжаем, строку БД всё равно вычищаем (иначе «мёртвая» строка копится вечно).
 * Trade-off: при транзиентной ошибке S3 объект утечёт (указателя на него больше
 * нет) — приемлемо для v1; более аккуратный вариант — пропускать строку до
 * следующего прогона.
 */
final class FileCleanupService
{
    /**
     * @param array<string, int> $statusRetentionHours   ключ = ConversionStatus->value
     * @param array<string, int> $tariffRetentionHours   ключ = 'guest'|'free'|'paid'
     * @param array<string, int> $categoryRetentionHours ключ = FileCategory->value
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ConversionRepository $conversions,
        private readonly S3Storage $storage,
        private readonly LoggerInterface $logger,
        private readonly int $defaultRetentionHours,
        private readonly array $statusRetentionHours = [],
        private readonly array $tariffRetentionHours = [],
        private readonly array $categoryRetentionHours = [],
        private readonly int $batchSize = 100,
    ) {
    }

    /**
     * Один прогон очистки. `now` фиксируется один раз. Порог хранения — свой у
     * каждой строки (resolveRetentionHours), поэтому единого `createdAt < X` мало:
     * идём грубым пре-фильтром (createdAt < now - минимальный настроенный порог) +
     * КУРСОР по id. Пропущенные (ещё не «доросшие» по своему порогу) строки не
     * ломают прогресс — курсор двигается по id вперёд даже для них, поэтому цикл
     * не зациклится (в отличие от re-query по createdAt).
     *
     * EM берём из ManagerRegistry на входе (как ConversionResultPersister): если
     * прошлый тик уронил flush() и закрыл EM, следующий тик получит свежий EM.
     *
     * @return array{deleted: int, bytesFreed: int}
     */
    public function run(): array
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        $now          = new \DateTimeImmutable();
        $minThreshold = $now->modify('-' . $this->minConfiguredRetention() . ' hours');

        $deleted    = 0;
        $bytesFreed = 0;
        $afterId    = 0;

        while (true) {
            $batch = $this->conversions->findExpiredCandidates($minThreshold, $afterId, $this->batchSize);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $conversion) {
                // Курсор двигаем ДО проверки возраста — иначе пропущенная строка
                // вернётся в следующей пачке и цикл зациклится.
                $afterId = $conversion->getId();

                $resolved     = max(1, $this->resolveRetentionHours($conversion));
                $rowThreshold = $now->modify('-' . $resolved . ' hours');
                if ($conversion->getCreatedAt() >= $rowThreshold) {
                    continue; // ещё не устарела по своему порогу
                }

                $bytesFreed += $this->purge($em, $conversion);
                $deleted++;
            }

            // Doctrine топологически упорядочивает удаления: Conversion (ссылается
            // на FileStorage) удаляется раньше самих FileStorage — FK не нарушается.
            $em->flush();
            $em->clear();
        }

        $this->logger->info('Авто-очистка устаревших конвертаций завершена', [
            'deleted'    => $deleted,
            'bytesFreed' => $bytesFreed,
        ]);

        return ['deleted' => $deleted, 'bytesFreed' => $bytesFreed];
    }

    /**
     * Минимальный из всех настроенных (> 0) порогов по всем осям — граница грубого
     * пре-фильтра. Любой per-row порог >= этого минимума, поэтому строка, годная к
     * удалению, гарантированно проходит пре-фильтр (ничего валидного не отсекаем).
     */
    private function minConfiguredRetention(): int
    {
        $values = array_merge(
            [$this->defaultRetentionHours],
            array_values($this->statusRetentionHours),
            array_values($this->tariffRetentionHours),
            array_values($this->categoryRetentionHours),
        );

        $positive = array_filter($values, static fn (int $h): bool => $h > 0);

        return $positive === [] ? max(1, $this->defaultRetentionHours) : max(1, min($positive));
    }

    /**
     * Разрешение порога по приоритету осей (первая ось со значением > 0 выигрывает).
     * Возвращает «сырое» значение; `max(1, …)` навешивает вызывающий код.
     */
    private function resolveRetentionHours(Conversion $conversion): int
    {
        $byStatus = $this->statusRetentionHours[$conversion->getStatus()->value] ?? 0;
        if ($byStatus > 0) {
            return $byStatus;
        }

        $byTariff = $this->tariffRetentionHours[$this->resolveTariff($conversion->getUser())] ?? 0;
        if ($byTariff > 0) {
            return $byTariff;
        }

        $byCategory = $this->categoryRetentionHours[$conversion->getCategory()->value] ?? 0;
        if ($byCategory > 0) {
            return $byCategory;
        }

        return $this->defaultRetentionHours;
    }

    private function resolveTariff(User $user): string
    {
        if ($user->isGuest()) {
            return 'guest';
        }

        return $user->getPlan() === 'free' ? 'free' : 'paid';
    }

    /**
     * Удаляет S3-объекты (input + output, если есть) и помечает строки БД к
     * удалению. Возвращает освобождённый объём в байтах (по метаданным FileStorage).
     */
    private function purge(EntityManagerInterface $em, Conversion $conversion): int
    {
        $bytes     = 0;
        $inputFile = $conversion->getInputFile();

        $this->deleteObject($this->storage->inputsBucket(), $inputFile->getStoragePath(), $conversion->getId());
        $bytes += $inputFile->getSizeBytes();

        $outputFile = $conversion->getOutputFile();
        if ($outputFile !== null) {
            $this->deleteObject($this->storage->resultsBucket(), $outputFile->getStoragePath(), $conversion->getId());
            $bytes += $outputFile->getSizeBytes();
        }

        // Conversion — первым (child FK), затем его FileStorage-строки.
        $em->remove($conversion);
        $em->remove($inputFile);
        if ($outputFile !== null) {
            $em->remove($outputFile);
        }

        return $bytes;
    }

    /**
     * Устойчивое удаление S3-объекта: любой сбой не прерывает прогон, строка БД
     * всё равно будет вычищена вызывающим кодом.
     */
    private function deleteObject(string $bucket, string $key, int $conversionId): void
    {
        try {
            $this->storage->deleteObject($bucket, $key);
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось удалить S3-объект при авто-очистке; строка БД будет удалена', [
                'bucket'       => $bucket,
                'key'          => $key,
                'conversionId' => $conversionId,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
