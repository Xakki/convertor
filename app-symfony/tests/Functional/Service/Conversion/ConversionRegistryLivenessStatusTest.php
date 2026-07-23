<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Conversion;

use App\Enum\WorkerLivenessStatus;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Hard acceptance criterion (registry-06): liveness does NOT gate routing. A
 * `disconnected` instance (gateway saw the WS drop) keeps serving its
 * declared pairs until long-TTL GC actually removes the row — `status` is a
 * pure monitoring signal, never a routing input. See the defensive comment
 * at {@see ConversionRegistry::buildMatrixFromCapabilities()} for why this
 * needs an explicit test: a `status` column is exactly the kind of thing a
 * future change reaches for as a routing filter.
 */
final class ConversionRegistryLivenessStatusTest extends KernelTestCase
{
    private const WORKER_TYPE = 'data';

    private const INSTANCE_ID = 'liveness-routing-test';

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'DELETE FROM worker_capabilities WHERE worker_type = :wt AND instance_id = :id',
            ['wt' => self::WORKER_TYPE, 'id' => self::INSTANCE_ID],
        );

        parent::tearDown();
    }

    public function testDisconnectedInstanceStillServesItsPairs(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $repo      = $container->get(WorkerCapabilityRepository::class);

        // A synthetic pair that cannot collide with the real registry-03 seed
        // 'data' matrix (csv/json/xml/yaml/yml/toml) — 'zzzfrom'/'zzzto' are
        // not real formats, so this instance is the ONLY source for the pair.
        $cap = $repo->upsert(self::WORKER_TYPE, self::INSTANCE_ID, [
            'workerType'  => self::WORKER_TYPE,
            'instanceId'  => self::INSTANCE_ID,
            'isAi'        => false,
            'streams'     => [self::WORKER_TYPE],
            'routingKeys' => [self::WORKER_TYPE],
            'matrix'      => ['zzzfrom' => ['zzzto']],
        ]);
        self::assertSame(WorkerLivenessStatus::Alive, $cap->getStatus(), 'precondition: upsert() sets alive');

        // Gateway reports the WS drop via the SAME liveness path production uses.
        $result = $repo->updateLiveness([[
            'workerType' => self::WORKER_TYPE,
            'instanceId' => self::INSTANCE_ID,
            'status'     => WorkerLivenessStatus::Disconnected,
            'lastSeenAt' => new \DateTimeImmutable(),
        ]]);
        self::assertSame(1, $result['updated']);

        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            WorkerLivenessStatus::Disconnected,
            $reloaded->getStatus(),
            'precondition: the row really is marked disconnected',
        );

        /** @var ConversionRegistry $registry */
        $registry = $container->get(ConversionRegistry::class);
        $registry->invalidateMatrix();

        self::assertTrue(
            $registry->isSupported('zzzfrom', 'zzzto'),
            'a disconnected-but-not-yet-GCd instance must keep serving its pairs — '
            . 'liveness informs, it does not route (epic Decisions)',
        );
    }
}
