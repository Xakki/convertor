<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Worker;

use App\Enum\WorkerLivenessStatus;
use App\Service\Worker\WorkerCapabilityGcService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Живой прогон long-TTL GC против реальной тест-БД (convertor-test).
 *
 * CNV-71-04: `__seed__`-строки и их спец-обработка удалены — GC теперь
 * удаляет ЛЮБУЮ строку старше TTL по `last_seen`, без исключений по
 * instance_id (кроме известного junk-набора {@see WorkerCapabilityGcService::JUNK_INSTANCE_IDS}).
 *
 * Использует workerType `gc-test-fixture`, не пересекающийся ни с одним
 * реальным worker-type из WorkerType — чтобы не
 * задеть реальные ряды, которые могут уже сидеть в convertor-test.
 */
final class WorkerCapabilityGcServiceTest extends KernelTestCase
{
    private const TEST_WORKER_TYPE = 'gc-test-fixture';

    private Connection $conn;

    /** @var list<array{workerType: string, instanceId: string}> строки к подчистке после теста */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->toRemove as $row) {
            $this->conn->executeStatement(
                'DELETE FROM worker_capabilities WHERE worker_type = :wt AND instance_id = :id',
                ['wt' => $row['workerType'], 'id' => $row['instanceId']],
            );
        }
        $this->toRemove = [];

        parent::tearDown();
    }

    public function testStaleRowIsDeleted(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'stale-instance', (new \DateTimeImmutable())->modify('-10 years'));

        $result = $this->gc()->run();

        self::assertGreaterThanOrEqual(1, $result['deleted']);
        self::assertFalse(
            $this->rowExists(self::TEST_WORKER_TYPE, 'stale-instance'),
            'a row older than TTL must be deleted',
        );
    }

    public function testFreshRowSurvivesGc(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'fresh-instance', new \DateTimeImmutable());

        $this->gc()->run();

        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, 'fresh-instance'),
            'a fresh (recently-seen) instance must survive GC — TTL not yet elapsed',
        );
    }

    public function testManualTtlDeletesOnlyRowsOlderThanItsThreshold(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'manual-stale', (new \DateTimeImmutable())->modify('-2 hours'));
        $this->insertRow(self::TEST_WORKER_TYPE, 'manual-fresh', (new \DateTimeImmutable())->modify('-30 minutes'));

        $this->gc()->run(1);

        self::assertFalse($this->rowExists(self::TEST_WORKER_TYPE, 'manual-stale'));
        self::assertTrue($this->rowExists(self::TEST_WORKER_TYPE, 'manual-fresh'));
    }

    public function testDefaultTtlRemainsIndependentFromManualOverride(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'scheduled-survivor', (new \DateTimeImmutable())->modify('-2 hours'));

        $this->gc()->run();

        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, 'scheduled-survivor'),
            'запланированный проход использует настроенный long TTL, а не ручное переопределение',
        );
    }

    /**
     * `status` never influences the GC decision — only `last_seen` age does
     * (routing itself no longer reads `worker_capabilities` at all since
     * CNV-71-02, so there is no routing-side counterpart to this guarantee
     * anymore — this test covers the GC/diagnostics side only). Proven both
     * directions: `disconnected`-but-fresh survives, `alive`-but-ancient is
     * still deleted — status is not a shortcut past the TTL check either way.
     */
    public function testDisconnectedButFreshInstanceSurvivesGc(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'disconnected-fresh', new \DateTimeImmutable(), WorkerLivenessStatus::Disconnected);

        $this->gc()->run();

        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, 'disconnected-fresh'),
            'a fresh instance survives GC regardless of status — disconnected is not a fast-track to deletion',
        );
    }

    public function testAliveButAncientInstanceIsDeleted(): void
    {
        $this->insertRow(
            self::TEST_WORKER_TYPE,
            'alive-ancient',
            (new \DateTimeImmutable())->modify('-10 years'),
            WorkerLivenessStatus::Alive,
        );

        $this->gc()->run();

        self::assertFalse(
            $this->rowExists(self::TEST_WORKER_TYPE, 'alive-ancient'),
            'an ancient instance is deleted regardless of status — alive is not a shield against TTL expiry',
        );
    }

    /**
     * registry-09 / CNV-36: junk `test:worker` удаляется на каждом GC-проходе
     * независимо от TTL; свежий соседний ряд того же workerType не задет.
     */
    public function testJunkTestWorkerInstanceIsAlwaysDeleted(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'test:worker', new \DateTimeImmutable());
        $this->insertRow(self::TEST_WORKER_TYPE, 'fresh-sibling', new \DateTimeImmutable());

        $result = $this->gc()->run();

        self::assertGreaterThanOrEqual(1, $result['deleted']);
        self::assertFalse(
            $this->rowExists(self::TEST_WORKER_TYPE, 'test:worker'),
            'junk instance_id test:worker must be deleted on every GC pass, regardless of last_seen age',
        );
        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, 'fresh-sibling'),
            'junk purge must not affect other fresh rows in the same workerType',
        );
    }

    private function gc(): WorkerCapabilityGcService
    {
        return static::getContainer()->get(WorkerCapabilityGcService::class);
    }

    private function insertRow(
        string $workerType,
        string $instanceId,
        \DateTimeImmutable $lastSeen,
        ?WorkerLivenessStatus $status = null,
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO worker_capabilities (worker_type, instance_id, capabilities, last_seen, status) '
            . 'VALUES (:wt, :id, :caps, :ls, :status)',
            [
                'wt'   => $workerType,
                'id'   => $instanceId,
                'caps' => json_encode([
                    'workerType'  => $workerType,
                    'instanceId'  => $instanceId,
                    'isAi'        => false,
                    'streams'     => [],
                    'routingKeys' => [],
                    'matrix'      => [],
                ], JSON_THROW_ON_ERROR),
                'ls'     => $lastSeen->format('Y-m-d H:i:s'),
                'status' => ($status ?? WorkerLivenessStatus::Alive)->value,
            ],
        );
        $this->toRemove[] = ['workerType' => $workerType, 'instanceId' => $instanceId];
    }

    private function rowExists(string $workerType, string $instanceId): bool
    {
        return $this->conn->fetchOne(
            'SELECT id FROM worker_capabilities WHERE worker_type = :wt AND instance_id = :id',
            ['wt' => $workerType, 'id' => $instanceId],
        ) !== false;
    }
}
