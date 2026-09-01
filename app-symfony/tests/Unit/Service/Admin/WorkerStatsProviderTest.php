<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\WorkerCapability;
use App\Enum\WorkerLivenessStatus;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Admin\WorkerStatsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты сборщика списка воркеров (registry-07): застаб-репозиторий, без
 * БД. Покрывает TTL-based `stale` (тот же TTL, что реальный GC —
 * {@see \App\Service\Worker\WorkerCapabilityGcService}), подсчёт пар и
 * порядок сортировки (workerType asc, затем instanceId asc).
 */
final class WorkerStatsProviderTest extends TestCase
{
    private function stubCap(
        string $workerType,
        string $instanceId,
        \DateTimeImmutable $lastSeen,
        WorkerLivenessStatus $status,
        array $capabilities = [],
        ?array $metrics = null,
        ?string $host = null,
    ): WorkerCapability {
        $cap = $this->createStub(WorkerCapability::class);
        $cap->method('getWorkerType')->willReturn($workerType);
        $cap->method('getInstanceId')->willReturn($instanceId);
        $cap->method('getLastSeen')->willReturn($lastSeen);
        $cap->method('getStatus')->willReturn($status);
        $cap->method('getCapabilities')->willReturn($capabilities + [
            'workerType' => $workerType,
            'instanceId' => $instanceId,
            'matrix'     => [],
        ]);
        $cap->method('getMetrics')->willReturn($metrics);
        $cap->method('getHost')->willReturn($host);

        return $cap;
    }

    private function provider(array $capabilities, int $ttlHours = 168): WorkerStatsProvider
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn($capabilities);

        return new WorkerStatsProvider($repo, $ttlHours);
    }

    public function testTtlHoursEchoedInResponse(): void
    {
        $data = $this->provider([], 72)->collect();
        self::assertSame(72, $data['ttlHours']);
        self::assertSame([], $data['workers']);
    }

    public function testFreshInstanceIsNotStale(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertFalse($row['stale']);
        self::assertSame('alive', $row['status']);
    }

    public function testOldInstanceIsStale(): void
    {
        $old = (new \DateTimeImmutable())->modify('-1000 hours');
        $cap = $this->stubCap('image', 'host-a:1', $old, WorkerLivenessStatus::Alive, []);

        $row = $this->provider([$cap], 168)->collect()['workers'][0];

        self::assertTrue($row['stale'], 'older than the 168h TTL must be stale');
    }

    public function testPairCountSumsAllTargetsAcrossFromKeys(): void
    {
        $cap = $this->stubCap('data', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, [
            'matrix' => [
                'csv'  => ['json', 'yaml'],
                'json' => ['csv'],
            ],
        ]);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame(3, $row['pairCount']);
        self::assertSame(['csv' => ['json', 'yaml'], 'json' => ['csv']], $row['matrix']);
    }

    public function testImageAndVersionAreExposedWhenPresent(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, [
            'image'   => 'harbor.example/worker-image:1.2.3',
            'version' => '1.2.3',
        ]);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame('harbor.example/worker-image:1.2.3', $row['image']);
        self::assertSame('1.2.3', $row['version']);
    }

    public function testMissingImageAndVersionAreNull(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertNull($row['image']);
        self::assertNull($row['version']);
    }

    public function testProvenanceIsExposedAndLegacyPayloadDefaultsSafely(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, [
            'provenance' => [
                'appVersion' => '1.2.3',
                'build' => '42',
                'revision' => 'abcdef',
                'sourceState' => 'clean',
                'imageRepository' => 'harbor.example/worker-image',
            ],
        ]);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame([
            'appVersion' => '1.2.3',
            'build' => '42',
            'revision' => 'abcdef',
            'sourceState' => 'clean',
            'imageRepository' => 'harbor.example/worker-image',
        ], $row['provenance']);

        $legacyRow = $this->provider([
            $this->stubCap('image', 'legacy:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive),
        ])->collect()['workers'][0];
        self::assertSame([
            'appVersion' => null,
            'build' => null,
            'revision' => null,
            'sourceState' => null,
            'imageRepository' => null,
        ], $legacyRow['provenance']);
    }

    public function testIsAiStreamsRoutingKeysAndMatrixCategoriesAreExposed(): void
    {
        $cap = $this->stubCap('ai', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, [
            'isAi'              => true,
            'streams'           => ['ai'],
            'routingKeys'       => ['ai'],
            'matrix_categories' => ['mp3' => 'audio', 'wav' => 'audio'],
        ]);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertTrue($row['isAi']);
        self::assertSame(['ai'], $row['streams']);
        self::assertSame(['ai'], $row['routingKeys']);
        self::assertSame(['mp3' => 'audio', 'wav' => 'audio'], $row['matrix_categories']);
    }

    public function testExecutionKindAndPublicSettingsAreExposed(): void
    {
        $settings = [
            'model' => [
                'default' => 'fast',
                'choices' => [['value' => 'fast', 'label' => 'Fast']],
            ],
        ];
        $cap = $this->stubCap('api', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, [
            'executionKind' => 'api',
            'settings'      => $settings,
        ]);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame('api', $row['executionKind']);
        self::assertSame($settings, $row['settings']);
    }

    public function testMissingIsAiStreamsRoutingKeysAndMatrixCategoriesDefaultToEmpty(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertFalse($row['isAi']);
        self::assertSame([], $row['streams']);
        self::assertSame([], $row['routingKeys']);
        self::assertSame([], $row['matrix_categories']);
    }

    public function testMetricsArePassedThroughFromTheEntityWhenPresent(): void
    {
        $cap = $this->stubCap(
            'image',
            'host-a:1',
            new \DateTimeImmutable(),
            WorkerLivenessStatus::Alive,
            metrics: ['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1],
        );

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame(['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1], $row['metrics']);
    }

    public function testMetricsAreNullWhenTheWorkerNeverPushedAny(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertNull($row['metrics']);
    }

    public function testHostIsPassedThroughFromTheEntityWhenPresent(): void
    {
        $cap = $this->stubCap(
            'image',
            'host-a:1',
            new \DateTimeImmutable(),
            WorkerLivenessStatus::Alive,
            host: 'xbook-remote',
        );

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame('xbook-remote', $row['host']);
    }

    public function testHostIsNullWhenTheWorkerNeverSentOne(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertNull($row['host']);
    }

    /**
     * CNV-61: `inflight` lives inside the SAME `metrics` JSON blob at the
     * entity/DB layer, but `toRow()` must surface it as a separate top-level
     * field — the `metrics` object presented to consumers stays exactly
     * {cpu, mem, load}, as it was before CNV-61.
     */
    public function testInflightIsExposedAsTopLevelFieldAndStrippedFromMetrics(): void
    {
        $cap = $this->stubCap(
            'image',
            'host-a:1',
            new \DateTimeImmutable(),
            WorkerLivenessStatus::Alive,
            metrics: ['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1, 'inflight' => 2],
        );

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertSame(2, $row['inflight']);
        self::assertSame(['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1], $row['metrics']);
    }

    public function testInflightIsNullWhenNeverReported(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertNull($row['inflight']);
    }

    /** Sort: workerType asc, then instanceId asc. */
    public function testSortsByWorkerTypeThenInstanceId(): void
    {
        $now = new \DateTimeImmutable();

        $capabilities = [
            $this->stubCap('image', 'host-b:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('document', 'host-b:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('image', 'host-c:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('document', 'host-a:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('image', 'host-a:1', $now, WorkerLivenessStatus::Alive),
        ];

        $rows = $this->provider($capabilities)->collect()['workers'];

        $order = array_map(
            static fn (array $r): string => $r['workerType'] . '/' . $r['instanceId'],
            $rows,
        );

        self::assertSame([
            'document/host-a:1',
            'document/host-b:1',
            'image/host-a:1',
            'image/host-b:1',
            'image/host-c:1',
        ], $order);
    }

    /**
     * CNV-61 per-host aggregate: real hosts sorted ascending, the legacy
     * `host IS NULL` bucket sorted LAST.
     */
    public function testCollectHostsSortsByNameWithNullBucketLast(): void
    {
        $now = new \DateTimeImmutable();

        $capabilities = [
            $this->stubCap('image', 'a1', $now, WorkerLivenessStatus::Alive, host: 'ubook'),
            $this->stubCap('image', 'a2', $now, WorkerLivenessStatus::Alive, host: null),
            $this->stubCap('image', 'a3', $now, WorkerLivenessStatus::Alive, host: 'ahost'),
        ];

        $hosts = $this->provider($capabilities)->collectHosts()['hosts'];

        self::assertSame(['ahost', 'ubook', null], array_column($hosts, 'host'));
    }

    /**
     * status/stale counts and lastSeen roll up per host over ALL its workers
     * (registry-09 `status`, {@see \App\Service\Worker\WorkerLivenessTtl} `stale`) —
     * reused verbatim from `toRow()`, not re-derived.
     */
    public function testCollectHostsAggregatesStatusCountsStaleAndFreshestLastSeen(): void
    {
        // Relative, not a pinned calendar date — a fixed date would silently
        // cross the 168h TTL threshold itself a week after being written.
        $fresh = (new \DateTimeImmutable())->modify('-1 hour');
        $old   = (new \DateTimeImmutable())->modify('-1000 hours');

        $capabilities = [
            $this->stubCap('image', 'w1', $fresh, WorkerLivenessStatus::Alive, host: 'ubook'),
            $this->stubCap('document', 'w2', $old, WorkerLivenessStatus::Disconnected, host: 'ubook'),
        ];

        $host = $this->provider($capabilities, 168)->collectHosts()['hosts'][0];

        self::assertSame('ubook', $host['host']);
        self::assertSame(2, $host['workers']);
        self::assertSame(1, $host['alive']);
        self::assertSame(1, $host['disconnected']);
        self::assertSame(0, $host['unknown']);
        self::assertSame(1, $host['stale'], 'only the old row crosses the 168h TTL');
        self::assertSame($fresh->format(\DateTimeInterface::ATOM), $host['lastSeen'], 'freshest lastSeen on the host');
    }

    /**
     * `inflight` sums across workers (unknown treated as 0), but
     * `inflightKnown` is false only when NOT A SINGLE worker on the host
     * reported one — the UI must render "—", not a misleading 0.
     */
    public function testCollectHostsSumsInflightAndFlagsUnknownWhenNoneReported(): void
    {
        $now = new \DateTimeImmutable();

        $withInflight = $this->stubCap('image', 'w1', $now, WorkerLivenessStatus::Alive, host: 'ubook', metrics: ['cpu' => null, 'mem' => null, 'load' => null, 'inflight' => 2]);
        $withoutAny   = $this->stubCap('document', 'w2', $now, WorkerLivenessStatus::Alive, host: 'ubook');

        $known = $this->provider([$withInflight, $withoutAny])->collectHosts()['hosts'][0];
        self::assertSame(2, $known['inflight']);
        self::assertTrue($known['inflightKnown']);

        $unknownOnly = $this->provider([$withoutAny])->collectHosts()['hosts'][0];
        self::assertSame(0, $unknownOnly['inflight']);
        self::assertFalse($unknownOnly['inflightKnown'], 'no worker reported inflight — must not read as "0 in flight"');
    }

    /**
     * cpu/mem/load = {avg, max} over workers that ACTUALLY reported metrics;
     * a worker with no metrics is excluded from the average, not counted as 0.
     * images/versions are distinct+sorted, hasAi is true if ANY worker on the
     * host has that flag.
     */
    public function testCollectHostsAveragesMetricsAndCollectsDistinctImagesVersionsAndFlags(): void
    {
        $now = new \DateTimeImmutable();

        $a = $this->stubCap('ai', 'w1', $now, WorkerLivenessStatus::Alive, [
            'isAi'    => true,
            'image'   => 'harbor.example/worker-ai:1.2',
            'version' => '1.2',
        ], metrics: ['cpu' => 10.0, 'mem' => 20.0, 'load' => 0.5], host: 'ubook');
        $b = $this->stubCap('image', 'w2', $now, WorkerLivenessStatus::Alive, [
            'image'   => 'harbor.example/worker-ai:1.3',
            'version' => '1.3',
        ], host: 'ubook');
        $c = $this->stubCap('document', 'w3', $now, WorkerLivenessStatus::Alive, [
            'image' => 'harbor.example/worker-ai:1.2',
        ], metrics: ['cpu' => 30.0, 'mem' => 40.0, 'load' => 1.5], host: 'ubook');

        $host = $this->provider([$a, $b, $c])->collectHosts()['hosts'][0];

        self::assertSame(['avg' => 20.0, 'max' => 30.0], $host['cpu']);
        self::assertSame(['avg' => 30.0, 'max' => 40.0], $host['mem']);
        self::assertSame(['avg' => 1.0, 'max' => 1.5], $host['load']);
        self::assertSame(['harbor.example/worker-ai:1.2', 'harbor.example/worker-ai:1.3'], $host['images']);
        self::assertSame(['1.2', '1.3'], $host['versions']);
        self::assertTrue($host['hasAi']);
    }

    /**
     * CNV-61 unit pin: host-level cpu/mem/load stay on the EXACT SAME 0..1
     * fraction scale as the per-worker `metrics` (see
     * `workers/common/ws_client.py::_load_snapshot` — cpu/mem via cgroup,
     * load = getloadavg()/ncpu clamped 0..1). `avgMax()` must NOT rescale to
     * percent — a single worker reporting a known fraction must round-trip
     * through `collectHosts()` unchanged (mod rounding precision), otherwise
     * the host aggregate and the per-worker row would disagree by 100x once
     * the Twig formatters multiply by 100 for display.
     */
    public function testCollectHostsKeepsCpuMemLoadOnTheSameFractionScaleAsPerWorkerMetrics(): void
    {
        $cap = $this->stubCap(
            'image',
            'w1',
            new \DateTimeImmutable(),
            WorkerLivenessStatus::Alive,
            metrics: ['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.125],
            host: 'ubook',
        );

        $host = $this->provider([$cap])->collectHosts()['hosts'][0];

        self::assertSame(['avg' => 0.42, 'max' => 0.42], $host['cpu'], 'a single worker at cpu=0.42 (42%) must surface as 0.42, not 42.0');
        self::assertSame(['avg' => 0.31, 'max' => 0.31], $host['mem']);
        self::assertSame(['avg' => 0.125, 'max' => 0.125], $host['load']);
    }

    public function testCollectHostsMetricsAreNullWhenNoWorkerReportedAny(): void
    {
        $cap = $this->stubCap('image', 'w1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive, host: 'ubook');

        $host = $this->provider([$cap])->collectHosts()['hosts'][0];

        self::assertNull($host['cpu']);
        self::assertNull($host['mem']);
        self::assertNull($host['load']);
        self::assertSame([], $host['images']);
        self::assertSame([], $host['versions']);
        self::assertFalse($host['hasAi']);
    }

    public function testCollectHostsTtlHoursEchoedInResponse(): void
    {
        $data = $this->provider([], 72)->collectHosts();
        self::assertSame(72, $data['ttlHours']);
        self::assertSame([], $data['hosts']);
    }
}
