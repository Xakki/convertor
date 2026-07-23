<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\WorkerCapability;
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
}
