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
 * БД. Покрывает seed-флаг/статус, TTL-based `stale` (тот же TTL, что реальный
 * GC — {@see \App\Service\Worker\WorkerCapabilityGcService}), подсчёт пар и
 * порядок сортировки (seed первой в своей группе workerType).
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

    public function testSeedRowIsFlaggedIsSeedWithUnknownStatusRegardlessOfAge(): void
    {
        $ancient = (new \DateTimeImmutable())->modify('-10 years');
        $cap     = $this->stubCap('document', '__seed__', $ancient, WorkerLivenessStatus::Unknown);

        $data = $this->provider([$cap])->collect();

        self::assertCount(1, $data['workers']);
        $row = $data['workers'][0];
        self::assertTrue($row['isSeed']);
        self::assertSame('unknown', $row['status']);
        self::assertTrue($row['stale'], 'raw stale computation is still honest — UI decides how to present it');
    }

    public function testFreshNonSeedInstanceIsNotStale(): void
    {
        $cap = $this->stubCap('image', 'host-a:1', new \DateTimeImmutable(), WorkerLivenessStatus::Alive);

        $row = $this->provider([$cap])->collect()['workers'][0];

        self::assertFalse($row['isSeed']);
        self::assertFalse($row['stale']);
        self::assertSame('alive', $row['status']);
    }

    public function testOldNonSeedInstanceIsStale(): void
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

    /** Sort: workerType asc, then seed-row first within its group, then instanceId asc. */
    public function testSortsSeedFirstWithinWorkerTypeGroupThenByInstanceId(): void
    {
        $now = new \DateTimeImmutable();

        $capabilities = [
            $this->stubCap('image', 'host-b:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('document', 'host-a:1', $now, WorkerLivenessStatus::Alive),
            $this->stubCap('image', '__seed__', $now, WorkerLivenessStatus::Unknown),
            $this->stubCap('document', '__seed__', $now, WorkerLivenessStatus::Unknown),
            $this->stubCap('image', 'host-a:1', $now, WorkerLivenessStatus::Alive),
        ];

        $rows = $this->provider($capabilities)->collect()['workers'];

        $order = array_map(
            static fn (array $r): string => $r['workerType'] . '/' . $r['instanceId'],
            $rows,
        );

        self::assertSame([
            'document/__seed__',
            'document/host-a:1',
            'image/__seed__',
            'image/host-a:1',
            'image/host-b:1',
        ], $order);
    }
}
