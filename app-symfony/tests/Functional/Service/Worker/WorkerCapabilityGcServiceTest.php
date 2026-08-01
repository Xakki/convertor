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
 * Central hard acceptance criterion (registry-06): seed-строки
 * (`instance_id='__seed__'`) НИКОГДА не удаляются GC, даже с заведомо
 * древним `last_seen` — их `last_seen` устанавливается один раз при
 * registry-03 seed-миграции и никогда не обновляется реальным
 * liveness-пушем (ни один живой воркер не владеет `__seed__`), поэтому по
 * возрасту `last_seen` seed-строки ВСЕГДА выглядят кандидатом на удаление.
 * Явное исключение `instance_id != '__seed__'` в
 * {@see WorkerCapabilityGcService::run()} — единственное, что их защищает;
 * этот тест доказывает, что исключение реально работает против живой БД, а
 * не только по чтению кода.
 *
 * Использует workerType `gc-test-fixture`, не пересекающийся ни с одним
 * реальным registry-03 seed-типом (document/image/audio/video/data/ai) —
 * иначе "до"-состояние теста было бы неотличимо от прод-seed-данных,
 * которые уже сидят в convertor-test с registry-03.
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

    public function testAncientSeedRowSurvivesGc(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, '__seed__', (new \DateTimeImmutable())->modify('-10 years'));

        $this->gc()->run();

        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, '__seed__'),
            'seed row (instance_id=__seed__) must NEVER be deleted by GC, regardless of age',
        );
    }

    public function testStaleNonSeedRowIsDeleted(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'stale-instance', (new \DateTimeImmutable())->modify('-10 years'));

        $result = $this->gc()->run();

        self::assertGreaterThanOrEqual(1, $result['deleted']);
        self::assertFalse(
            $this->rowExists(self::TEST_WORKER_TYPE, 'stale-instance'),
            'a non-seed row older than TTL must be deleted',
        );
    }

    public function testFreshNonSeedRowSurvivesGc(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'fresh-instance', new \DateTimeImmutable());

        $this->gc()->run();

        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, 'fresh-instance'),
            'a fresh (recently-seen) instance must survive GC — TTL not yet elapsed',
        );
    }

    /**
     * Both a stale seed row AND a stale non-seed row in the SAME batch —
     * proves the exclusion is per-row (`instance_id != '__seed__'` in the
     * WHERE clause), not an accidental all-or-nothing skip triggered by the
     * mere presence of a seed row in the table.
     */
    public function testMixedBatchDeletesOnlyNonSeedStaleRow(): void
    {
        $ancient = (new \DateTimeImmutable())->modify('-10 years');
        $this->insertRow(self::TEST_WORKER_TYPE, '__seed__', $ancient);
        $this->insertRow(self::TEST_WORKER_TYPE, 'stale-sibling', $ancient);

        $this->gc()->run();

        self::assertTrue($this->rowExists(self::TEST_WORKER_TYPE, '__seed__'), 'seed row must survive');
        self::assertFalse($this->rowExists(self::TEST_WORKER_TYPE, 'stale-sibling'), 'stale non-seed sibling must be deleted');
    }

    /**
     * `status` never influences the GC decision — only `last_seen` age and the
     * seed exclusion do (see the defensive comment in
     * {@see \App\Service\Conversion\ConversionRegistry::buildMatrixFromCapabilities()}
     * for the routing-side counterpart of this same guarantee). Proven both
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
     * независимо от TTL; seed-сibling в том же workerType выживает.
     */
    public function testJunkTestWorkerInstanceIsAlwaysDeleted(): void
    {
        $this->insertRow(self::TEST_WORKER_TYPE, 'test:worker', new \DateTimeImmutable());
        $this->insertRow(self::TEST_WORKER_TYPE, '__seed__', (new \DateTimeImmutable())->modify('-10 years'));

        $result = $this->gc()->run();

        self::assertGreaterThanOrEqual(1, $result['deleted']);
        self::assertFalse(
            $this->rowExists(self::TEST_WORKER_TYPE, 'test:worker'),
            'junk instance_id test:worker must be deleted on every GC pass, regardless of last_seen age',
        );
        self::assertTrue(
            $this->rowExists(self::TEST_WORKER_TYPE, '__seed__'),
            'seed row must survive junk purge',
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
