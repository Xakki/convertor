<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use App\Enum\WorkerLivenessStatus;
use App\Repository\HostTelemetrySnapshotRepository;
use App\Repository\WorkerCapabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты admin-workers-эндпоинта (registry-07, финальный шаг
 * эпика registry-00-self-registration). Граница — ROLE_ADMIN на JWT-firewall
 * (Option B, тот же паттерн, что QueueControllerTest): не-админ 403,
 * неаутентифицированный 401, админ 200. Требуют тест-БД convertor-test.
 *
 * CNV-71-04: `__seed__`-строки и вся их спец-обработка (isSeed/hasSeed,
 * исключения из GC/сверки/bulk-delete) удалены — каждый тест сам заводит
 * нужные ему фикстуры через {@see WorkerCapabilityRepository::upsert()},
 * не полагается на статичный registry-03 снимок.
 */
final class WorkerControllerTest extends WebTestCase
{
    private const TEST_WORKER_TYPE = 'data';

    private const TEST_INSTANCE_ID = 'admin-workers-test';

    /** Общий префикс instanceId-фикстур для тестов `/workers/hosts` и `?host=`. */
    private const HOST_TEST_INSTANCE_PREFIX = 'admin-workers-hosts-';

    /** Общий префикс instanceId-фикстур для тестов `DELETE /workers/stale` (CNV-61). */
    private const STALE_TEST_INSTANCE_PREFIX = 'admin-workers-stale-';

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
        $em->getConnection()->executeStatement(
            'DELETE FROM worker_capabilities WHERE worker_type = :wt AND instance_id LIKE :prefix',
            ['wt' => self::TEST_WORKER_TYPE, 'prefix' => self::HOST_TEST_INSTANCE_PREFIX . '%'],
        );
        $em->getConnection()->executeStatement(
            'DELETE FROM worker_capabilities WHERE worker_type = :wt AND instance_id LIKE :prefix',
            ['wt' => self::TEST_WORKER_TYPE, 'prefix' => self::STALE_TEST_INSTANCE_PREFIX . '%'],
        );
        $em->getConnection()->executeStatement(
            'DELETE FROM host_telemetry_snapshots WHERE host_name LIKE :prefix',
            ['prefix' => 'cnv137-admin-%'],
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
    }

    /**
     * A freshly-registered instance: status=alive, NOT stale (just
     * registered), pairCount/matrix reflect what it declared.
     */
    public function testWorkersReflectsRealInstance(): void
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

    /**
     * CNV-61: `/workers/hosts` groups the SAME rows `/workers` returns by
     * `host` — a real host with a partially-known `inflight`, a real host
     * with NO worker reporting `inflight` at all (`inflightKnown: false`,
     * not a misleading 0), and the legacy `host IS NULL` bucket. CNV-71-04:
     * seed rows (which used to populate the null bucket for free) are gone —
     * this test now provides its own explicit no-host fixture.
     */
    public function testWorkersHostsAggregatesPerHostIncludingNullBucketAndUnknownInflight(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $hostA1   = self::HOST_TEST_INSTANCE_PREFIX . 'a1';
        $hostA2   = self::HOST_TEST_INSTANCE_PREFIX . 'a2';
        $hostB1   = self::HOST_TEST_INSTANCE_PREFIX . 'b1';
        $hostNull = self::HOST_TEST_INSTANCE_PREFIX . 'nullhost';

        $repo->upsert(self::TEST_WORKER_TYPE, $hostA1, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []], 'hosts-test-a');
        $repo->upsert(self::TEST_WORKER_TYPE, $hostA2, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []], 'hosts-test-a');
        $repo->upsert(self::TEST_WORKER_TYPE, $hostB1, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []], 'hosts-test-b');
        // No `$host` argument — persists `host = NULL`, feeding the legacy bucket.
        $repo->upsert(self::TEST_WORKER_TYPE, $hostNull, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []]);

        // Only ONE instance on "hosts-test-a" reports `inflight`; "hosts-test-b"
        // reports none at all.
        $repo->updateLiveness([[
            'workerType' => self::TEST_WORKER_TYPE,
            'instanceId' => $hostA1,
            'status'     => WorkerLivenessStatus::Alive,
            'lastSeenAt' => new \DateTimeImmutable(),
            'inflight'   => 4,
        ]]);
        // updateLiveness() writes via native SQL and does NOT refresh managed
        // entities (unlike upsert()) — clear the identity map so the read
        // below re-queries instead of returning the stale in-memory instance
        // from the upsert() calls above (same pattern as
        // testWorkersSurfacesMetricsFromLivenessPush).
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', '/api/v1/admin/workers/hosts', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ttlHours', $data);
        self::assertIsInt($data['ttlHours']);
        self::assertArrayHasKey('hosts', $data);

        $byHost = [];
        foreach ($data['hosts'] as $entry) {
            $byHost[$entry['host'] ?? '__null__'] = $entry;
        }

        self::assertArrayHasKey('hosts-test-a', $byHost);
        $hostA = $byHost['hosts-test-a'];
        self::assertSame(2, $hostA['workers']);
        self::assertSame(4, $hostA['inflight'], 'sum across workers, unknown treated as 0');
        self::assertTrue($hostA['inflightKnown']);

        self::assertArrayHasKey('hosts-test-b', $byHost);
        $hostB = $byHost['hosts-test-b'];
        self::assertSame(1, $hostB['workers']);
        self::assertSame(0, $hostB['inflight']);
        self::assertFalse($hostB['inflightKnown'], 'no worker on this host ever reported inflight');

        // Legacy null-host bucket — populated by $hostNull above, sorted LAST.
        self::assertArrayHasKey('__null__', $byHost, 'null-host bucket must be present');
        self::assertNull($byHost['__null__']['host']);
        self::assertSame(null, $data['hosts'][array_key_last($data['hosts'])]['host'], 'null bucket sorted last');
    }

    public function testWorkersHostsForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/workers/hosts', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * `?host=<name>` — only workers on that host. Additive: absent-param
     * behaviour ({@see testWorkersReturnsStructuredJsonForAdmin}) stays exact.
     */
    public function testWorkersHostFilterReturnsOnlyThatHostsWorkers(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $hostA = self::HOST_TEST_INSTANCE_PREFIX . 'filter-a';
        $hostB = self::HOST_TEST_INSTANCE_PREFIX . 'filter-b';
        $repo->upsert(self::TEST_WORKER_TYPE, $hostA, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []], 'filter-host-a');
        $repo->upsert(self::TEST_WORKER_TYPE, $hostB, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []], 'filter-host-b');

        $client->request('GET', '/api/v1/admin/workers?host=filter-host-a', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['workers']);
        foreach ($data['workers'] as $row) {
            self::assertSame('filter-host-a', $row['host']);
        }
        $instanceIds = array_column($data['workers'], 'instanceId');
        self::assertContains($hostA, $instanceIds);
        self::assertNotContains($hostB, $instanceIds);
    }

    /**
     * `?hostNull=1` — the legacy `host IS NULL` bucket (CNV-61 review, finding
     * #2). Replaced the old `?host=__none__` sentinel, which collided with a
     * real host literally named `__none__`.
     */
    public function testWorkersHostNullFilterReturnsLegacyNullBucket(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        // CNV-71-04: seed rows (which used to guarantee a non-empty null-host
        // bucket for free) are gone — provide an explicit no-host fixture.
        $repo->upsert(self::TEST_WORKER_TYPE, self::TEST_INSTANCE_ID, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []]);

        $client->request(
            'GET',
            '/api/v1/admin/workers?hostNull=1',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['workers'], 'the no-host fixture just registered must appear in the null bucket');
        foreach ($data['workers'] as $row) {
            self::assertNull($row['host']);
        }
    }

    /**
     * A REAL host literally named `__none__` is just a normal name now — no
     * longer a collision-prone sentinel (CNV-61 review, finding #2).
     */
    public function testWorkersHostFilterReachesRealHostLiterallyNamedNoneSentinel(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $instanceId = self::HOST_TEST_INSTANCE_PREFIX . 'literal-none';
        $repo->upsert(self::TEST_WORKER_TYPE, $instanceId, [
            'isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => [],
        ], '__none__');

        $client->request(
            'GET',
            '/api/v1/admin/workers?host=' . rawurlencode('__none__'),
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();

        $data        = json_decode((string) $client->getResponse()->getContent(), true);
        $instanceIds = array_column($data['workers'], 'instanceId');
        self::assertContains($instanceId, $instanceIds, 'a host literally named __none__ must be reachable via ?host=__none__');
        foreach ($data['workers'] as $row) {
            self::assertSame('__none__', $row['host']);
        }
    }

    /**
     * Passing both `host` and `hostNull` at once is a client error — the two
     * filters are mutually exclusive (CNV-61 review, finding #2).
     */
    public function testWorkersRejectsBothHostAndHostNullFilters(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request(
            'GET',
            '/api/v1/admin/workers?host=some-host&hostNull=1',
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testDeleteStaleForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('DELETE', '/api/v1/admin/workers/stale', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testDeleteStaleReturnsZeroWhenNothingMatches(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        // No disconnected/unknown rows exist at this point — a clean run must
        // report an honest zero, not error.
        $client->request('DELETE', '/api/v1/admin/workers/stale', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['deleted' => 0], $data);
    }

    /**
     * Happy path (CNV-61): a `disconnected` row and an `unknown` row are both
     * removed, the endpoint reports their count.
     */
    public function testDeleteStaleRemovesDisconnectedAndUnknownRows(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $disconnectedId = self::STALE_TEST_INSTANCE_PREFIX . 'disconnected';
        $unknownId      = self::STALE_TEST_INSTANCE_PREFIX . 'unknown';

        $repo->upsert(self::TEST_WORKER_TYPE, $disconnectedId, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []]);
        $repo->upsert(self::TEST_WORKER_TYPE, $unknownId, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []]);
        $repo->updateLiveness([[
            'workerType' => self::TEST_WORKER_TYPE,
            'instanceId' => $disconnectedId,
            'status'     => WorkerLivenessStatus::Disconnected,
            'lastSeenAt' => new \DateTimeImmutable(),
        ]]);
        // `unknown` never comes from updateLiveness() on a real known row (see
        // WorkerLivenessStatus::Unknown doc — it's a DB-column DEFAULT never
        // written by application code); the repository's delete predicate
        // matches on the raw column value regardless of how it got there, so
        // a direct UPDATE is a fair way to fabricate a row in that state for
        // this test.
        $em->getConnection()->executeStatement(
            'UPDATE worker_capabilities SET status = :status WHERE worker_type = :wt AND instance_id = :id',
            ['status' => WorkerLivenessStatus::Unknown->value, 'wt' => self::TEST_WORKER_TYPE, 'id' => $unknownId],
        );

        $client->request('DELETE', '/api/v1/admin/workers/stale', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('deleted', $data);
        // >=2, not ===2: other suites may leave their own disconnected/unknown
        // fixtures around in a shared test DB — the two rows this test created
        // being gone (assertion below) is the load-bearing proof.
        self::assertGreaterThanOrEqual(2, $data['deleted']);

        $remaining = $em->getConnection()->fetchAllAssociative(
            'SELECT instance_id FROM worker_capabilities WHERE worker_type = :wt AND instance_id IN (:d, :u)',
            ['wt' => self::TEST_WORKER_TYPE, 'd' => $disconnectedId, 'u' => $unknownId],
        );
        self::assertSame([], $remaining, 'both rows must be gone');
    }

    /**
     * `alive` rows are untouched — only `disconnected`/`unknown` match the
     * delete predicate.
     */
    public function testDeleteStaleKeepsAliveRows(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $aliveId = self::STALE_TEST_INSTANCE_PREFIX . 'alive';
        $repo->upsert(self::TEST_WORKER_TYPE, $aliveId, ['isAi' => false, 'streams' => [], 'routingKeys' => [], 'matrix' => []]);

        $client->request('DELETE', '/api/v1/admin/workers/stale', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $status = $em->getConnection()->fetchOne(
            'SELECT status FROM worker_capabilities WHERE worker_type = :wt AND instance_id = :id',
            ['wt' => self::TEST_WORKER_TYPE, 'id' => $aliveId],
        );
        self::assertSame('alive', $status, 'alive row must survive');
    }

    public function testTelemetryReturnsSnapshotsForAdminAndMarksStale(): void
    {
        $client     = static::createClient();
        $token      = $this->jwtFor($this->persistUser(true));
        $repository = static::getContainer()->get(HostTelemetrySnapshotRepository::class);
        $now        = new \DateTimeImmutable();

        $repository->save(new \App\Entity\HostTelemetrySnapshot(
            'cnv137-admin-fresh',
            ['contractVersion' => 1, 'cpuCount' => 8],
            $now,
            $now,
        ));
        $repository->save(new \App\Entity\HostTelemetrySnapshot(
            'cnv137-admin-stale',
            ['contractVersion' => 1, 'cpuCount' => 2],
            $now->modify('-21 minutes'),
            $now,
        ));

        $client->request('GET', '/api/v1/admin/workers/telemetry', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['contractVersion']);
        $stale = [];
        foreach ($data['snapshots'] as $snapshot) {
            $stale[$snapshot['host']] = $snapshot['stale'];
        }
        self::assertFalse($stale['cnv137-admin-fresh']);
        self::assertTrue($stale['cnv137-admin-stale']);
    }

    public function testTelemetryIsForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/workers/telemetry', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * Create a user fixture with the requested role.
     */
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
