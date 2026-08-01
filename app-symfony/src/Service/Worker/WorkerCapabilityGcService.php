<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Conversion\ConversionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Long-TTL GC воркер-capability строк (registry-06). Удаляет ряды
 * `worker_capabilities`, чей `last_seen` старше настраиваемого TTL —
 * НИКОГДА seed-строки (`instance_id = '__seed__'`, registry-03 seed
 * migration). Это hard acceptance criterion: seed-строки не устаревают сами
 * по себе (ни один реальный воркер не пушит liveness под instanceId
 * `__seed__`), поэтому без явного исключения их `last_seen` ВСЕГДА выглядит
 * древним — GC снёс бы их на первом же прогоне. Снос seed сделал бы пустую
 * БД достижимой в обычной эксплуатации: гарантия D1 «БД никогда не пуста»
 * (registry-05: пустой/недоступный результат `buildRoutingPairs()` → честная
 * пустая матрица БЕЗ фолбэка) молча аннулируется — вместе с ней исчезают
 * `/formats` и submit до первой живой регистрации.
 *
 * Liveness (registry-06 push-эндпоинт) НЕ гейтит роутинг (эпик, Decisions:
 * «Eviction = long-TTL GC, NOT short liveness gating») — GC чистит только
 * явно мёртвые записи (не обновлялись дольше TTL), живые/недавно виденные
 * инстансы не трогает независимо от их liveness-статуса.
 *
 * Деградация `/formats`/submit при удалении последнего живого инстанса
 * workerType (репорт registry-06):
 *   - Если для этого workerType ЕСТЬ seed-строка (сегодня — все 6
 *     зарегистрированных в registry-03 типов: document/image/audio/video/
 *     data/ai) — матрица деградирует к статичному seed-снапшоту, НЕ к пустой:
 *     {@see \App\Service\Conversion\ConversionRegistry::buildMatrixFromCapabilities()}
 *     объединяет ВСЕ оставшиеся ряды (включая seed), поэтому seed-пары
 *     продолжают обслуживать submit/formats как временный fallback.
 *   - Если для workerType НЕТ seed-строки (гипотетический будущий тип, не
 *     покрытый registry-03) — его пары исчезают из матрицы ПОЛНОСТЬЮ:
 *     `/formats` их не покажет, submit отдаст честный 400. Это НЕ регрессия —
 *     ровно то поведение, которое registry-05 сделало намеренным (честная
 *     пустая/уменьшенная матрица вместо скрытого фолбэка).
 */
final class WorkerCapabilityGcService
{
    /**
     * Литерал ЗАДУБЛИРОВАН намеренно — тот же паттерн, что
     * `WorkerController::RESERVED_SEED_INSTANCE_ID` и
     * `migrations/Version20260722150301.php::SEED_INSTANCE_ID`. При
     * переименовании синхронизировать все три места вручную.
     */
    private const SEED_INSTANCE_ID = '__seed__';

    /**
     * Известные junk instance_id — удаляются на каждом GC-проходе независимо от TTL
     * (registry-09 / CNV-36). Seed (`__seed__`) сюда НЕ включать.
     *
     * @var list<string>
     */
    private const JUNK_INSTANCE_IDS = [
        'test:worker',
    ];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ConversionRegistry $conversionRegistry,
        private readonly LoggerInterface $logger,
        private readonly int $ttlHours,
    ) {
    }

    /**
     * Один проход GC. Возвращает число удалённых строк.
     *
     * @return array{deleted: int}
     */
    public function run(): array
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);
        $conn = $em->getConnection();

        // registry-07 review: threshold-формула вынесена в WorkerLivenessTtl —
        // тот же callable использует WorkerStatsProvider (admin-страница), чтобы
        // предсказание "устарел" никогда не разошлось с моментом реального удаления.
        $threshold = WorkerLivenessTtl::staleThreshold($this->ttlHours);

        $deleted = (int) $conn->executeStatement(
            'DELETE FROM worker_capabilities WHERE instance_id != :seedInstanceId AND last_seen < :threshold',
            [
                'seedInstanceId' => self::SEED_INSTANCE_ID,
                'threshold'      => $threshold->format('Y-m-d H:i:s'),
            ],
        );

        foreach (self::JUNK_INSTANCE_IDS as $junkInstanceId) {
            $deleted += (int) $conn->executeStatement(
                'DELETE FROM worker_capabilities WHERE instance_id = :junkInstanceId',
                ['junkInstanceId' => $junkInstanceId],
            );
        }

        if ($deleted > 0) {
            // Не ждать до часа (cache.app TTL, registry-05 ConversionRegistry::
            // buildMatrix()) — удалённые пары должны пропасть из /formats сразу
            // же, а не после естественного истечения кеша.
            $this->conversionRegistry->invalidateMatrix();
        }

        $this->logger->info('worker_capabilities GC: проход завершён', [
            'deleted'  => $deleted,
            'ttlHours' => $this->ttlHours,
        ]);

        return ['deleted' => $deleted];
    }
}
