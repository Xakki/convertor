<?php

declare(strict_types=1);

namespace App\Service\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Long-TTL GC воркер-capability строк (registry-06). Удаляет ряды
 * `worker_capabilities`, чей `last_seen` старше настраиваемого TTL.
 *
 * Normal worker admission is durable: short liveness status and silence do not
 * affect submit; only this long-TTL deletion eventually removes admission.
 * API model publication, validation, and admission deliberately use fresh alive
 * rows through `ApiModelAvailability`, independently of this GC policy.
 */
final class WorkerCapabilityGcService
{
    /**
     * Известные junk instance_id — удаляются на каждом GC-проходе независимо от TTL
     * (registry-09 / CNV-36).
     *
     * @var list<string>
     */
    private const JUNK_INSTANCE_IDS = [
        'test:worker',
    ];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly int $ttlHours,
    ) {
    }

    /**
     * Один проход GC. Возвращает число удалённых строк.
     *
     * @return array{deleted: int}
     */
    public function run(?int $ttlHours = null): array
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);
        $conn = $em->getConnection();

        $ttlHours ??= $this->ttlHours;

        // registry-07 review: threshold-формула вынесена в WorkerLivenessTtl —
        // тот же callable использует WorkerStatsProvider (admin-страница), чтобы
        // предсказание "устарел" никогда не разошлось с моментом реального удаления.
        $threshold = WorkerLivenessTtl::staleThreshold($ttlHours);

        $deleted = (int) $conn->executeStatement(
            'DELETE FROM worker_capabilities WHERE last_seen < :threshold',
            ['threshold' => $threshold->format('Y-m-d H:i:s')],
        );

        foreach (self::JUNK_INSTANCE_IDS as $junkInstanceId) {
            $deleted += (int) $conn->executeStatement(
                'DELETE FROM worker_capabilities WHERE instance_id = :junkInstanceId',
                ['junkInstanceId' => $junkInstanceId],
            );
        }

        $this->logger->info('worker_capabilities GC: проход завершён', [
            'deleted'  => $deleted,
            'ttlHours' => $ttlHours,
        ]);

        return ['deleted' => $deleted];
    }
}
