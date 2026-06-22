<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Service\Queue\ConversionResultPersister;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Worker\WorkerStreamGateway;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the universal worker pull-API (/api/v1/worker/*).
 *
 * Auth, input-validation, and "job not found" cases are covered without real
 * infrastructure. WorkerStreamGateway is overridden in the test container with
 * a PHPUnit mock; ConversionResultPersister is constructed with a mocked
 * EntityManager that returns null for every find() call, making persist() a
 * controlled no-op.
 *
 * Tests that need live Redis/S3/DB are guarded with skipUnless* helpers and
 * remain skippable in CI environments that lack those services.
 */
final class WorkerControllerTest extends WebTestCase
{
    // -------------------------------------------------------------------------
    // Auth (401)
    // -------------------------------------------------------------------------

    public function testClaimReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"type":"ai","consumer":"w1"}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testClaimReturns401WithWrongToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
            '{"type":"ai","consumer":"w1"}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

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

    public function testFailReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/worker/jobs/1234567890123-0/fail');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Claim
    // -------------------------------------------------------------------------

    public function testClaimReturns204WhenNoJob(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('claim')->willReturn(null);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"type":"ai","consumer":"w1"}',
        );

        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame('', $client->getResponse()->getContent());
    }

    public function testClaimReturns200WithJob(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $job = [
            'jobId'        => '1717000000000-0',
            'conversionId' => 42,
            'sourceFormat' => 'mp3',
            'targetFormat' => 'txt',
        ];

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('claim')->willReturn($job);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"type":"ai","consumer":"w1"}',
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1717000000000-0', $body['jobId']);
        self::assertSame(42, $body['conversionId']);
        self::assertSame('mp3', $body['sourceFormat']);
        self::assertSame('txt', $body['targetFormat']);
    }

    public function testClaimReturns400WhenMissingFields(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"type":"ai"}', // consumer missing
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testClaimReturns400WhenTypeNotAllowed(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"type":"unknown_type","consumer":"w1"}',
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('unknown_type', (string) $body['error']);
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
    // Result
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
    // Fail
    // -------------------------------------------------------------------------

    public function testFailReturns404WhenJobNotFound(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(null);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/worker/jobs/1234567890123-0/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"error":"Worker crashed"}',
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testFailReturns200AndNoOpsWhenConversionMissingFromDb(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        // Gateway: return meta so the controller proceeds, ack() is a no-op.
        $gateway = $this->createMock(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn([
            'conversionId' => 99999,
            'inputBucket'  => 'test_-inputs',
            'inputKey'     => 'inputs/test.mp3',
            'stream'       => 'conv.ai',
            'targetFormat' => 'txt',
        ]);
        // ack() is called by the controller after persist().
        $gateway->expects(self::once())->method('ack')->with('conv.ai', '1717000000000-0');
        $container->set(WorkerStreamGateway::class, $gateway);

        // Real ConversionResultPersister with a mocked EM that finds no conversion →
        // persist() logs a warning and returns without throwing (idempotent no-op).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $persister = new ConversionResultPersister(
            $registry,
            'test_-results',
            new NullLogger(),
            $this->createStub(QuotaService::class),
        );
        $container->set(ConversionResultPersister::class, $persister);

        $client->request(
            'POST',
            '/api/v1/worker/jobs/1717000000000-0/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"error":"GPU out of memory"}',
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
    }

    // -------------------------------------------------------------------------
    // Unit-style: WorkerStreamGateway message parsing (no live Redis)
    // -------------------------------------------------------------------------

    public function testGatewayParsesDoubleEncodedMessengerEnvelope(): void
    {
        $job = [
            'conversionId' => 7,
            'inputBucket'  => 'convertor-inputs',
            'inputKey'     => 'inputs/2026/06-23/abc.mp3',
            'sourceFormat' => 'mp3',
            'targetFormat' => 'txt',
            'category'     => 'ai',
            'isAi'         => true,
        ];

        // Simulate a Symfony Messenger double-encoded envelope in the stream.
        $envelope = json_encode(['body' => json_encode($job, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
        $fields   = ['message' => $envelope];

        // Access the private method via a test subclass.
        $redis = $this->createStub(\Redis::class);
        $redis->method('xGroup')->willReturn(true);
        $redis->method('xReadGroup')->willReturn([
            'conv.ai' => ['1717000000000-0' => $fields],
        ]);
        $redis->method('setex')->willReturn(true);

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

        $gateway = new WorkerStreamGateway($factory, new NullLogger());
        $result  = $gateway->claim('ai', 'w1');

        self::assertNotNull($result);
        self::assertSame('1717000000000-0', $result['jobId']);
        self::assertSame(7, $result['conversionId']);
        self::assertSame('mp3', $result['sourceFormat']);
        self::assertSame('txt', $result['targetFormat']);
    }

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

        $gateway = new WorkerStreamGateway($factory, new NullLogger());
        self::assertNull($gateway->getJobMeta('0-0'));
    }
}
