<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\ConversionRequestDTO;
use App\Entity\Example;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Repository\ConversionRepository;
use App\Repository\ExampleRepository;
use App\Repository\UserRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Examples\ExampleCatalog;
use App\Service\Examples\ExampleDefinition;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Прогоняет курируемый набор примеров ({@see ExampleCatalog}) через РЕАЛЬНЫЙ
 * пайплайн конвертации ({@see ConversionManager} → Messenger → воркеры), затем
 * копирует каждый результат в стабильный S3-префикс `examples/<category>/…`
 * бакета результатов (home-04, решение D3). Отдельного кода конвертации нет —
 * та же цепочка, что и обычный `POST /api/v1/convert`.
 *
 * Результат в `examples/` НЕ привязан к строке FileStorage, поэтому 24-часовой
 * FileCleanupService (чистит по `FileStorage.expiresAt`) его не удаляет — пример
 * живёт постоянно, пока команду не перезапустят (copy перезапишет объект).
 *
 * Карточка admin-managed-examples: команда ТАКЖЕ (1) загружает копию
 * sample-файла в S3 (`…-source.<from>`, тот же `examples/`-префикс — раньше
 * source отдавался с локального диска, теперь единый код с admin-промо) и
 * (2) upsert'ит строку {@see Example} (по `resultKey` — идемпотентно, повторный
 * запуск обновляет существующую строку, а не плодит дубликаты). `ExampleCatalog`
 * остаётся ИСТОЧНИКОМ для сидинга; публичный `ExampleController` читает уже
 * ТОЛЬКО из таблицы `examples`.
 *
 * Запуск — через `make seed-examples` (docker-only): команда должна выполняться
 * при поднятых воркерах и ws-gateway, иначе конвертации не завершатся (timeout).
 */
#[AsCommand(
    name: 'app:examples:seed',
    description: 'Прогнать курируемые примеры через реальный пайплайн и сложить результаты в examples/ S3',
)]
final class SeedExamplesCommand extends Command
{
    /** Sentinel-владелец seed-конвертаций (pro-план: без квотных лимитов на обычные конвертации). */
    private const SEED_USER_EMAIL = 'examples-seed@convertor.local';

    public function __construct(
        private readonly ExampleCatalog $catalog,
        private readonly ConversionManager $conversionManager,
        private readonly ConversionRepository $conversionRepository,
        private readonly UserRepository $userRepository,
        private readonly ExampleRepository $exampleRepository,
        private readonly EntityManagerInterface $em,
        private readonly S3Storage $s3,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Прогнать только указанные категории (через запятую), напр. --only=image,audio',
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Максимум секунд ожидания завершения одной конвертации',
                '120',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $timeout = (int) $input->getOption('timeout');
        if ($timeout < 1) {
            $io->error('--timeout должен быть положительным целым (секунды).');

            return Command::INVALID;
        }

        $definitions = $this->selectDefinitions($input->getOption('only'), $io);
        if ($definitions === null) {
            return Command::INVALID;
        }

        $user = $this->resolveSeedUser();

        $required = $this->catalog->requiredCategories();
        $rows     = [];
        $failed   = [];

        // sortOrder = позиция в ПОЛНОМ каталоге (не в отфильтрованном --only
        // подмножестве) — порядок витрины стабилен независимо от того, какие
        // категории прогонялись в конкретном запуске.
        $fullOrder = array_map(static fn (ExampleDefinition $d): string => $d->slug() . '@' . $d->category, $this->catalog->all());

        foreach ($definitions as $def) {
            $io->section(sprintf('%s: %s → %s', $def->category, $def->from, $def->to));

            $sortOrder = array_search($def->slug() . '@' . $def->category, $fullOrder, true);
            $sortOrder = $sortOrder === false ? 0 : $sortOrder;

            try {
                $key    = $this->seedOne($def, $user, $timeout, (int) $sortOrder, $io);
                $rows[] = [$def->category, "{$def->from} → {$def->to}", 'OK', $key];
                $io->success('Пример готов: ' . $key);
            } catch (\Throwable $e) {
                $rows[]   = [$def->category, "{$def->from} → {$def->to}", 'FAIL', $e->getMessage()];
                $failed[] = $def->category;
                $io->warning('Не удалось: ' . $e->getMessage());
            }
        }

        $io->section('Итог');
        $io->table(['Категория', 'Пара', 'Статус', 'S3-ключ / ошибка'], $rows);

        // Провал ОБЯЗАТЕЛЬНОЙ категории (AC) — ошибка команды; провал бонусной
        // (напр. markup) — только предупреждение, витрина её просто не покажет.
        $requiredFailed = array_values(array_intersect($failed, $required));
        if ($requiredFailed !== []) {
            $io->error('Провалены обязательные категории: ' . implode(', ', $requiredFailed));

            return Command::FAILURE;
        }

        if ($failed !== []) {
            $io->warning('Бонусные категории не собрались (не критично): ' . implode(', ', array_unique($failed)));
        }

        return Command::SUCCESS;
    }

    /**
     * Разбирает --only в список определений (или весь каталог). Неизвестная
     * категория → null (команда вернёт INVALID).
     *
     * @return list<ExampleDefinition>|null
     */
    private function selectDefinitions(mixed $only, SymfonyStyle $io): ?array
    {
        $all = $this->catalog->all();

        if (! is_string($only) || trim($only) === '') {
            return $all;
        }

        $wanted    = array_filter(array_map('trim', explode(',', $only)));
        $available = array_values(array_unique(array_map(static fn (ExampleDefinition $d): string => $d->category, $all)));

        $unknown = array_diff($wanted, $available);
        if ($unknown !== []) {
            $io->error(sprintf(
                'Неизвестные категории: %s. Доступные: %s',
                implode(', ', $unknown),
                implode(', ', $available),
            ));

            return null;
        }

        return array_values(array_filter($all, static fn (ExampleDefinition $d): bool => in_array($d->category, $wanted, true)));
    }

    /**
     * Гоняет один пример через реальный пайплайн, копирует результат+исходник в
     * `examples/` и upsert'ит строку {@see Example}. Возвращает итоговый S3-ключ
     * результата. Бросает исключение при отсутствии сэмпла, провале/timeout
     * конвертации.
     */
    private function seedOne(ExampleDefinition $def, User $user, int $timeout, int $sortOrder, SymfonyStyle $io): string
    {
        $samplePath = $this->projectDir . '/resources/seed-examples/' . $def->sampleFile;
        if (! is_file($samplePath)) {
            throw new \RuntimeException("Нет файла-сэмпла: {$samplePath}");
        }

        // Копируем сэмпл во временный файл с корректным расширением: UploadedFile
        // (test-режим) деривит source-формат из имени, а исходник в репозитории
        // не трогаем. storeInput() только читает файл (fopen), не перемещает.
        $tmp = tempnam(sys_get_temp_dir(), 'seed_') . '.' . $def->from;
        if (! copy($samplePath, $tmp)) {
            throw new \RuntimeException("Не удалось скопировать сэмпл во временный файл: {$samplePath}");
        }

        try {
            $file = new UploadedFile($tmp, $def->sampleFile, null, null, true);
            $dto  = new ConversionRequestDTO($user, $file, $def->to, false, true);

            $conversion = $this->conversionManager->createConversion($dto);
            $id         = $conversion->getId();
            $io->writeln("  conversion #{$id} поставлена в очередь, ждём воркер…");

            $status = $this->awaitTerminal($id, $timeout);

            if ($status !== ConversionStatus::Completed) {
                throw new \RuntimeException("Конвертация #{$id} завершилась статусом '{$status->value}'");
            }

            $fresh = $this->conversionRepository->find($id);
            $out   = $fresh?->getOutputFile();
            if ($out === null) {
                throw new \RuntimeException("Конвертация #{$id} completed, но outputFile отсутствует");
            }

            $resultsBucket = $this->s3->resultsBucket();

            // Серверная копия результата → стабильный examples/-префикс.
            $this->s3->copyObject(
                $resultsBucket,
                $out->getStoragePath(),
                $resultsBucket,
                $def->s3Key(),
                $def->mime(),
            );

            // admin-managed-examples: копия sample-файла В S3 (был только на
            // диске) — единый код отдачи source и для seed-, и для admin-промо-
            // примеров (см. класс-докблок ExampleController::source()).
            $sourceHandle = fopen($samplePath, 'rb');
            if ($sourceHandle === false) {
                throw new \RuntimeException("Не удалось открыть сэмпл для загрузки в S3: {$samplePath}");
            }

            try {
                $this->s3->putObject($resultsBucket, $def->sourceS3Key(), $sourceHandle, $def->sourceMime());
            } finally {
                fclose($sourceHandle);
            }

            $stat = $this->s3->objectStat($resultsBucket, $def->s3Key());
            $size = $stat['size'] ?? 0;

            $this->upsertExampleRow($def, $size, $sortOrder);

            return $def->s3Key();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Upsert строки {@see Example} по `resultKey` (идемпотентно — повторный
     * `make seed-examples` обновляет, не дублирует). `sourceFilename` остаётся
     * РАВЕН `def->sampleFile` (напр. `document.txt`) — публичный `source_url`
     * не меняется относительно дохардкод-контракта, хотя S3-ключ исходника
     * теперь другой (`…-source.<from>`, см. {@see ExampleDefinition::sourceS3Key()}).
     */
    private function upsertExampleRow(ExampleDefinition $def, int $size, int $sortOrder): void
    {
        $example = $this->exampleRepository->findOneByResultKey($def->s3Key()) ?? new Example();

        $example
            ->setCategory($def->category)
            ->setFromFormat($def->from)
            ->setToFormat($def->to)
            ->setFilename($def->objectName())
            ->setMime($def->mime())
            ->setSize($size)
            ->setPreviewable($def->previewable)
            ->setSourceFormat($def->from)
            ->setSourceMime($def->sourceMime())
            ->setSourceFilename($def->sampleFile)
            ->setResultKey($def->s3Key())
            ->setSourceKey($def->sourceS3Key())
            ->setSortOrder($sortOrder);

        $this->exampleRepository->save($example, true);
    }

    /**
     * Опрашивает DB-строку (единственный надёжный оракул: ws-gateway УДАЛЯЕТ
     * conv:status при XACK — Redis-хеш не годится) до терминального статуса или
     * timeout. em->refresh() перечитывает строку из БД, т.к. её обновляет ДРУГОЙ
     * процесс (InternalWorkerController → ConversionResultPersister).
     */
    private function awaitTerminal(int $id, int $timeout): ConversionStatus
    {
        $deadline = microtime(true) + $timeout;
        $terminal = [ConversionStatus::Completed, ConversionStatus::Failed, ConversionStatus::Expired];

        $conversion = $this->conversionRepository->find($id)
            ?? throw new \RuntimeException("Конвертация #{$id} не найдена сразу после создания");

        while (microtime(true) < $deadline) {
            $this->em->refresh($conversion);
            $status = $conversion->getStatus();

            if (in_array($status, $terminal, true)) {
                return $status;
            }

            usleep(1_000_000); // 1s между опросами
        }

        throw new \RuntimeException("Таймаут ожидания завершения конвертации #{$id} ({$timeout}с)");
    }

    /**
     * Находит/создаёт sentinel-пользователя примеров (pro-план: обычные
     * конвертации без суточного лимита). Владелец нужен, т.к.
     * ConversionManager::createConversion пишет Conversion от имени User.
     */
    private function resolveSeedUser(): User
    {
        $user = $this->userRepository->findOneBy(['email' => self::SEED_USER_EMAIL]);
        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->setEmail(self::SEED_USER_EMAIL);
        $user->setFirstName('Examples Seed');
        $user->setPlan('pro');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
