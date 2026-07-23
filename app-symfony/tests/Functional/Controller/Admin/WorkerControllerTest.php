<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use App\Enum\WorkerLivenessStatus;
use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-workers-эндпоинта (registry-07, финальный шаг
 * эпика registry-00-self-registration). Граница — ROLE_ADMIN на JWT-firewall
 * (Option B, тот же паттерн, что QueueControllerTest): не-админ 403,
 * неаутентифицированный 401, админ 200. Требуют тест-БД convertor-test
 * (registry-03 seed-строки там уже есть — используются как готовые
 * isSeed=true фикстуры без ручной вставки).
 */
final class WorkerControllerTest extends WebTestCase
{
    private const TEST_WORKER_TYPE = 'data';

    private const TEST_INSTANCE_ID = 'admin-workers-test';

    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if ($this->createdUserIds !== []) {
            foreach ($this->createdUserIds as $id) {
                $user = $em->find(User::class, $id);
                if ($user !== null) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }
        $this->createdUserIds = [];

        $em->getConnection()->executeStatement(
            'DELETE FROM worker_capabilities WHERE worker_type = :wt AND instance_id = :id',
            ['wt' => self::TEST_WORKER_TYPE, 'id' => self::TEST_INSTANCE_ID],
        );

        parent::tearDown();
    }

    public function testWorkersForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testWorkersUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/workers');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testWorkersReturnsStructuredJsonForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('ttlHours', $data);
        self::assertIsInt($data['ttlHours']);
        self::assertGreaterThan(0, $data['ttlHours']);

        self::assertArrayHasKey('workers', $data);
        self::assertIsArray($data['workers']);

        // registry-03 seeded all 6 canonical types as instance_id='__seed__' —
        // they must be present, flagged isSeed=true, status='unknown'.
        $seedRows  = array_filter($data['workers'], static fn (array $w): bool => $w['isSeed'] === true);
        $seedTypes = array_column($seedRows, 'workerType');
        foreach (['document', 'image', 'audio', 'video', 'data', 'ai'] as $type) {
            self::assertContains($type, $seedTypes, "seed workerType {$type} присутствует");
        }
        foreach ($seedRows as $row) {
            self::assertSame('__seed__', $row['instanceId']);
            self::assertSame('unknown', $row['status']);
        }
    }

    /**
     * A freshly-registered (non-seed) instance: isSeed=false, status=alive,
     * NOT stale (just registered), pairCount/matrix reflect what it declared.
     */
    public function testWorkersReflectsRealNonSeedInstance(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $repo->upsert(self::TEST_WORKER_TYPE, self::TEST_INSTANCE_ID, [
            'workerType'        => self::TEST_WORKER_TYPE,
            'instanceId'        => self::TEST_INSTANCE_ID,
            'isAi'              => true,
            'streams'           => [self::TEST_WORKER_TYPE],
            'routingKeys'       => [self::TEST_WORKER_TYPE],
            'matrix'            => ['zzzfrom' => ['zzzto1', 'zzzto2']],
            'matrix_categories' => ['zzzfrom' => self::TEST_WORKER_TYPE],
            'image'             => null,
            'version'           => '9.9.9-test',
        ]);

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $row  = null;
        foreach ($data['workers'] as $w) {
            if ($w['workerType'] === self::TEST_WORKER_TYPE && $w['instanceId'] === self::TEST_INSTANCE_ID) {
                $row = $w;
                break;
            }
        }

        self::assertNotNull($row, 'freshly registered instance must appear in the list');
        self::assertFalse($row['isSeed']);
        self::assertSame('alive', $row['status'], 'register() sets alive');
        self::assertFalse($row['stale'], 'just registered — must not be stale');
        self::assertSame('9.9.9-test', $row['version']);
        self::assertNull($row['image']);
        self::assertSame(2, $row['pairCount']);
        self::assertSame(['zzzfrom' => ['zzzto1', 'zzzto2']], $row['matrix']);
        self::assertTrue($row['isAi']);
        self::assertSame([self::TEST_WORKER_TYPE], $row['streams']);
        self::assertSame([self::TEST_WORKER_TYPE], $row['routingKeys']);
        self::assertSame(['zzzfrom' => self::TEST_WORKER_TYPE], $row['matrix_categories']);
        self::assertNull($row['metrics'], 'register() alone never carries metrics — only a liveness push does');
    }

    /**
     * A liveness push with `metrics` (cpu/mem/load) must surface on the admin
     * page — Phase 1 "cheap wins" over registry-06/07.
     */
    public function testWorkersSurfacesMetricsFromLivenessPush(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $repo->upsert(self::TEST_WORKER_TYPE, self::TEST_INSTANCE_ID, [
            'workerType'  => self::TEST_WORKER_TYPE,
            'instanceId'  => self::TEST_INSTANCE_ID,
            'isAi'        => false,
            'streams'     => [self::TEST_WORKER_TYPE],
            'routingKeys' => [self::TEST_WORKER_TYPE],
            'matrix'      => [],
        ]);
        $repo->updateLiveness([[
            'workerType' => self::TEST_WORKER_TYPE,
            'instanceId' => self::TEST_INSTANCE_ID,
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable(),
            'metrics'    => ['cpu' => 0.55, 'mem' => 0.2, 'load' => 0.05],
        ]]);
        // updateLiveness() writes via native SQL and does NOT refresh the
        // managed entity (unlike upsert()) — clear the identity map so the
        // admin read below actually re-queries the row instead of returning
        // the stale in-memory instance from the upsert() call above.
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $row  = null;
        foreach ($data['workers'] as $w) {
            if ($w['workerType'] === self::TEST_WORKER_TYPE && $w['instanceId'] === self::TEST_INSTANCE_ID) {
                $row = $w;
                break;
            }
        }

        self::assertNotNull($row);
        self::assertSame(['cpu' => 0.55, 'mem' => 0.2, 'load' => 0.05], $row['metrics']);
    }

    /**
     * registry-08: явный host/node-идентификатор из register() surfaces на
     * admin-странице; строки без host (register до этого поля, старый билд)
     * не ломают страницу — null.
     */
    public function testWorkersSurfacesHostFromRegister(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $repo->upsert(self::TEST_WORKER_TYPE, self::TEST_INSTANCE_ID, [
            'workerType'  => self::TEST_WORKER_TYPE,
            'instanceId'  => self::TEST_INSTANCE_ID,
            'isAi'        => false,
            'streams'     => [self::TEST_WORKER_TYPE],
            'routingKeys' => [self::TEST_WORKER_TYPE],
            'matrix'      => [],
            'host'        => 'xbook-remote',
        ], 'xbook-remote');

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $row  = null;
        foreach ($data['workers'] as $w) {
            if ($w['workerType'] === self::TEST_WORKER_TYPE && $w['instanceId'] === self::TEST_INSTANCE_ID) {
                $row = $w;
                break;
            }
        }

        self::assertNotNull($row);
        self::assertSame('xbook-remote', $row['host']);
    }

    public function testWorkersHostIsNullWhenNeverSent(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $repo->upsert(self::TEST_WORKER_TYPE, self::TEST_INSTANCE_ID, [
            'workerType'  => self::TEST_WORKER_TYPE,
            'instanceId'  => self::TEST_INSTANCE_ID,
            'isAi'        => false,
            'streams'     => [self::TEST_WORKER_TYPE],
            'routingKeys' => [self::TEST_WORKER_TYPE],
            'matrix'      => [],
        ]);

        $client->request('GET', '/api/v1/admin/workers', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $row  = null;
        foreach ($data['workers'] as $w) {
            if ($w['workerType'] === self::TEST_WORKER_TYPE && $w['instanceId'] === self::TEST_INSTANCE_ID) {
                $row = $w;
                break;
            }
        }

        self::assertNotNull($row);
        self::assertNull($row['host']);
    }

    private function persistUser(bool $admin): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function jwtFor(User $user): string
    {
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwt->create($user);
    }
}
