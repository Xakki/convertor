<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Service\Queue\RedisConnectionFactory;
use App\Service\Worker\WorkerStreamGateway;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the universal worker pull-API (/api/v1/worker/*).
 *
 * После s1-04 контроллер сузился до `input` (стриминг) и `result`
 * (large-multipart, без XACK). Чтение Stream (claim), inline-result и fail ушли
 * в WS-Gateway + InternalWorkerController — их тесты в InternalWorkerControllerTest.
 *
 * WorkerStreamGateway переопределяется в тест-контейнере PHPUnit-моком.
 */
final class WorkerControllerTest extends WebTestCase
{
    // -------------------------------------------------------------------------
    // Auth (401)
    // -------------------------------------------------------------------------

    public function testInputReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/worker/jobs/1234567890123-0/input');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testResultReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/worker/jobs/1234567890123-0/result');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function testInputReturns404WhenJobNotFound(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(null);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'GET',
            '/api/v1/worker/jobs/1234567890123-0/input',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Result (large-multipart)
    // -------------------------------------------------------------------------

    public function testResultReturns404WhenJobNotFound(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(null);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/jobs/1234567890123-0/result',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testResultReturns400WhenFileFieldMissing(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn([
            'conversionId' => 1,
            'inputBucket'  => 'test_-inputs',
            'inputKey'     => 'inputs/2026/06/23/abc.mp3',
            'stream'       => 'conv.ai',
            'targetFormat' => 'txt',
        ]);
        $container->set(WorkerStreamGateway::class, $gateway);

        // POST with auth but no multipart file
        $client->request(
            'POST',
            '/api/v1/worker/jobs/1234567890123-0/result',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Unit-style: WorkerStreamGateway::getJobMeta (no live Redis)
    // -------------------------------------------------------------------------

    public function testGetJobMetaReturnsNullWhenKeyMissing(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn(false);

        $factory = new class ($redis) extends RedisConnectionFactory {
            public function __construct(private \Redis $mockRedis)
            {
                parent::__construct('redis://localhost:6379?dbindex=2');
            }

            public function create(): \Redis
            {
                return $this->mockRedis;
            }
        };

        $gateway = new WorkerStreamGateway($factory);
        self::assertNull($gateway->getJobMeta('0-0'));
    }
}
