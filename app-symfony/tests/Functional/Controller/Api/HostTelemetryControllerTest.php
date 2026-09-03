<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\HostTelemetrySnapshot;
use App\Repository\HostTelemetrySnapshotRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HostTelemetryControllerTest extends WebTestCase
{
    private const HOST = 'cnv137-test.example';

    protected function tearDown(): void
    {
        static::getContainer()->get(HostTelemetrySnapshotRepository::class)
            ->getEntityManager()
            ->getConnection()
            ->executeStatement('DELETE FROM host_telemetry_snapshots WHERE host_name = :host', ['host' => self::HOST]);

        parent::tearDown();
    }

    public function testIngestRequiresInternalToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/internal/host-telemetry', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testIngestRejectsMalformedAndStalePayloads(): void
    {
        $client  = static::createClient();
        $headers = [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-internal-token',
        ];

        $client->request('POST', '/api/v1/internal/host-telemetry', server: $headers, content: json_encode([
            'host'            => 'Not a host',
            'contractVersion' => 1,
            'observedAt'      => time(),
        ], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/internal/host-telemetry', server: $headers, content: json_encode([
            'host'            => self::HOST,
            'contractVersion' => 1,
            'observedAt'      => time() - 1201,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testValidIngestStoresExactHostAndLatestSnapshot(): void
    {
        $client  = static::createClient();
        $headers = [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-internal-token',
        ];
        $repository = static::getContainer()->get(HostTelemetrySnapshotRepository::class);
        $now        = new \DateTimeImmutable();

        $repository->save(new HostTelemetrySnapshot(self::HOST, [
            'contractVersion'   => 1,
            'cpuCount'          => 4,
            'memAvailableBytes' => 100,
            'workers'           => ['worker-data' => ['memoryBytes' => 10]],
        ], $now, $now));

        $client->request('POST', '/api/v1/internal/host-telemetry', server: $headers, content: json_encode([
            'host'            => self::HOST,
            'contractVersion' => 1,
            'observedAt'      => $now->getTimestamp() - 1,
            'cpuCount'        => 99,
            'workers'         => ['worker-data' => ['memoryBytes' => 99]],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $snapshot = $repository->findByExactHost(self::HOST);
        self::assertNotNull($snapshot);
        self::assertSame(4, $snapshot->getData()['cpuCount']);
        self::assertSame(100, $snapshot->getData()['memAvailableBytes']);
    }

    public function testAdminTelemetryEndpointIsRoleAdminProtected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/workers/telemetry');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
