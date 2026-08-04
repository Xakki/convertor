<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\WorkerCapability;
use App\Enum\WorkerLivenessStatus;
use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * registry-02: upsert() ключуется по составному (workerType, instanceId) и
 * реализован нативным `INSERT ... ON DUPLICATE KEY UPDATE` (без find-then-update,
 * без TOCTOU-окна). Требует тест-БД convertor-test.
 */
final class WorkerCapabilityRepositoryTest extends KernelTestCase
{
    /** @var list<WorkerCapability> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $cap) {
                $managed = $em->contains($cap) ? $cap : $em->find(WorkerCapability::class, $cap->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

    public function testUpsertCreatesNewRowForUnknownKey(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $cap              = $repo->upsert('test-wc-image', 'host-a', ['isAi' => false, 'matrix' => ['jpg' => ['png']]]);
        $this->toRemove[] = $cap;

        self::assertSame('test-wc-image', $cap->getWorkerType());
        self::assertSame('host-a', $cap->getInstanceId());
        self::assertSame(['isAi' => false, 'matrix' => ['jpg' => ['png']]], $cap->getCapabilities());
    }

    public function testUpsertOnSameKeyUpdatesInPlaceInsteadOfDuplicating(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $first            = $repo->upsert('test-wc-image', 'host-a', ['isAi' => false, 'matrix' => ['jpg' => ['png']]]);
        $this->toRemove[] = $first;

        $second = $repo->upsert('test-wc-image', 'host-a', ['isAi' => false, 'matrix' => ['jpg' => ['png', 'webp']]]);

        self::assertSame($first->getId(), $second->getId(), 're-register of the same (workerType, instanceId) must update the same row, not insert a new one');
        self::assertSame(['isAi' => false, 'matrix' => ['jpg' => ['png', 'webp']]], $second->getCapabilities());

        $all = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(WorkerCapability::class)
            ->findBy(['workerType' => 'test-wc-image']);
        self::assertCount(1, $all, 'must not duplicate the row on re-register');
    }

    public function testTwoDifferentInstanceIdsOfSameWorkerTypeCoexist(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $a                = $repo->upsert('test-wc-ai', 'host-a', ['isAi' => true, 'matrix' => ['mp3' => ['txt']]]);
        $b                = $repo->upsert('test-wc-ai', 'host-b', ['isAi' => true, 'matrix' => ['wav' => ['txt']]]);
        $this->toRemove[] = $a;
        $this->toRemove[] = $b;

        self::assertNotSame($a->getId(), $b->getId());

        $all = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(WorkerCapability::class)
            ->findBy(['workerType' => 'test-wc-ai']);
        self::assertCount(2, $all, 'two distinct instanceId of the same workerType must coexist as two rows');
    }

    /**
     * registry-03: seed-миграция заливает строки с instance_id='__seed__'. Реальный
     * register того же worker_type (другой instanceId — Python-воркер никогда не
     * шлёт '__seed__') должен апсертиться отдельной строкой рядом с seed, не падая
     * на составном UNIQUE(worker_type, instance_id).
     */
    public function testRealRegisterUpsertsAlongsideSeedRowWithoutUniqueViolation(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $seed             = $repo->upsert('test-wc-seeded', '__seed__', ['isAi' => false, 'matrix' => ['jpg' => ['png']], 'version' => 'seed']);
        $live             = $repo->upsert('test-wc-seeded', 'host-a:worker-1', ['isAi' => false, 'matrix' => ['jpg' => ['png', 'webp']], 'version' => '1.2.3']);
        $this->toRemove[] = $seed;
        $this->toRemove[] = $live;

        self::assertNotSame($seed->getId(), $live->getId());
        self::assertSame('__seed__', $seed->getInstanceId());
        self::assertSame('host-a:worker-1', $live->getInstanceId());

        $all = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(WorkerCapability::class)
            ->findBy(['workerType' => 'test-wc-seeded']);
        self::assertCount(2, $all, 'live register must coexist with the seed row, not collide on the composite unique key');

        // Повторный upsert по тому же seed-ключу (напр. повторный прогон seed-миграции)
        // по-прежнему обновляет seed-строку in place, не трогая live-строку.
        $reSeed = $repo->upsert('test-wc-seeded', '__seed__', ['isAi' => false, 'matrix' => ['jpg' => ['png']], 'version' => 'seed']);
        self::assertSame($seed->getId(), $reSeed->getId());

        $allAfter = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(WorkerCapability::class)
            ->findBy(['workerType' => 'test-wc-seeded']);
        self::assertCount(2, $allAfter, 're-seeding must not duplicate rows');
    }

    /**
     * Phase 1 "дешёвые победы": updateLiveness() persists `metrics`
     * (cpu/mem/load) alongside last_seen/status, matching the gateway's wire
     * payload ({@see \App\Controller\Api\InternalWorkerController::liveness()}).
     */
    public function testUpdateLivenessPersistsMetrics(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $cap              = $repo->upsert('test-wc-metrics', 'host-a', ['isAi' => false, 'matrix' => []]);
        $this->toRemove[] = $cap;
        self::assertNull($cap->getMetrics(), 'precondition: no metrics until the first liveness push');

        $result = $repo->updateLiveness([[
            'workerType' => 'test-wc-metrics',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:00Z'),
            'metrics'    => ['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1],
        ]]);

        self::assertSame(1, $result['updated']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        // CNV-61: `inflight` lives in the same blob, but a push that omits it
        // must NOT fabricate a stored `inflight: null` — the shape stays
        // exactly {cpu,mem,load} when only cpu/mem/load were pushed.
        self::assertSame(['cpu' => 0.42, 'mem' => 0.31, 'load' => 0.1], $reloaded->getMetrics());
    }

    /**
     * A batch entry that omits `metrics` entirely (wire contract makes it
     * optional) must persist `null`, not fabricate zeros.
     */
    public function testUpdateLivenessWithoutMetricsPersistsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $cap              = $repo->upsert('test-wc-no-metrics', 'host-a', ['isAi' => false, 'matrix' => []]);
        $this->toRemove[] = $cap;

        $repo->updateLiveness([[
            'workerType' => 'test-wc-no-metrics',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:00Z'),
        ]]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getMetrics());
    }

    /**
     * CNV-61 review, finding #3: `metrics` (cpu/mem/load) и `inflight` — два
     * независимых компонента одного JSON-блоба, обновляются read-modify-write
     * мержем. Direction A: первый пуш несёт полные metrics, ВТОРОЙ несёт
     * ТОЛЬКО inflight — cpu/mem/load из первого пуша должны сохраниться, не
     * стереться null'ами.
     */
    public function testUpdateLivenessInflightOnlyPushPreservesPreviouslyStoredMetrics(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $em   = static::getContainer()->get(EntityManagerInterface::class);

        $cap              = $repo->upsert('test-wc-merge-a', 'host-a', ['isAi' => false, 'matrix' => []]);
        $this->toRemove[] = $cap;

        $repo->updateLiveness([[
            'workerType' => 'test-wc-merge-a',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:00Z'),
            'metrics'    => ['cpu' => 0.4, 'mem' => 0.3, 'load' => 0.2],
        ]]);

        $repo->updateLiveness([[
            'workerType' => 'test-wc-merge-a',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:05Z'),
            'inflight'   => 9,
        ]]);

        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            ['cpu' => 0.4, 'mem' => 0.3, 'load' => 0.2, 'inflight' => 9],
            $reloaded->getMetrics(),
            'an inflight-only push must not wipe out previously stored cpu/mem/load',
        );
    }

    /**
     * CNV-61 review, finding #3, direction B: первый пуш несёт ТОЛЬКО
     * inflight, второй — ТОЛЬКО metrics — ранее сохранённый inflight должен
     * сохраниться, не потеряться из-за отсутствия ключа во втором пуше.
     */
    public function testUpdateLivenessMetricsOnlyPushPreservesPreviouslyStoredInflight(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $em   = static::getContainer()->get(EntityManagerInterface::class);

        $cap              = $repo->upsert('test-wc-merge-b', 'host-a', ['isAi' => false, 'matrix' => []]);
        $this->toRemove[] = $cap;

        $repo->updateLiveness([[
            'workerType' => 'test-wc-merge-b',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:00Z'),
            'inflight'   => 5,
        ]]);

        $repo->updateLiveness([[
            'workerType' => 'test-wc-merge-b',
            'instanceId' => 'host-a',
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable('2099-01-01T00:00:05Z'),
            'metrics'    => ['cpu' => 0.6, 'mem' => 0.5, 'load' => 0.4],
        ]]);

        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            ['cpu' => 0.6, 'mem' => 0.5, 'load' => 0.4, 'inflight' => 5],
            $reloaded->getMetrics(),
            'a metrics-only push must not wipe out a previously stored inflight',
        );
    }

    /**
     * registry-08: явный host/node-идентификатор — отдельный столбец,
     * персистится через 4-й (опциональный) параметр upsert().
     */
    public function testUpsertPersistsHost(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $cap              = $repo->upsert('test-wc-host', 'host-a', ['isAi' => false, 'matrix' => []], 'xbook-remote');
        $this->toRemove[] = $cap;

        self::assertSame('xbook-remote', $cap->getHost());
    }

    /**
     * Без host (старый Python-билд, register без ключа `host`) — null, не
     * фабрикуется какое-то значение.
     */
    public function testUpsertWithoutHostPersistsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $cap              = $repo->upsert('test-wc-no-host', 'host-a', ['isAi' => false, 'matrix' => []]);
        $this->toRemove[] = $cap;

        self::assertNull($cap->getHost());
    }

    /**
     * Повторный register с ДРУГИМ host (напр. воркер переехал/обновился) должен
     * обновить host in place, не оставлять старое значение из-за CASE...ELSE
     * склейки (host здесь всегда есть в батче upsert() — ELSE-ветка касается
     * только updateLiveness()'а).
     */
    public function testUpsertUpdatesHostOnReRegister(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);

        $first            = $repo->upsert('test-wc-host-update', 'host-a', ['isAi' => false, 'matrix' => []], 'old-host');
        $this->toRemove[] = $first;
        self::assertSame('old-host', $first->getHost());

        $second = $repo->upsert('test-wc-host-update', 'host-a', ['isAi' => false, 'matrix' => []], 'new-host');

        self::assertSame($first->getId(), $second->getId());
        self::assertSame('new-host', $second->getHost());
    }
}
