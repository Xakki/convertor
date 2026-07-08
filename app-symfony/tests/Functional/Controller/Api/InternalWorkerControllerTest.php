<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Service\Queue\ConversionResultPersister;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Service\Worker\WorkerStreamGateway;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the WS-Gateway internal relay (/api/v1/internal/worker/*).
 *
 * Firewall `internal_api` — токен GATEWAY_INTERNAL_TOKEN (в .env.test =
 * `test-internal-token`, отличается от WORKER_API_TOKEN). Эндпоинты НЕ ацкают
 * Stream (это делает gateway). S3Storage/ConversionResultPersister — final,
 * поэтому в тестах строятся реально: S3Storage поверх мока S3Client, persister
 * поверх мока EntityManager (find→null → идемпотентный no-op).
 */
final class InternalWorkerControllerTest extends WebTestCase
{
    private const META = [
        'conversionId' => 99999,
        'inputBucket'  => 'test_-inputs',
        'inputKey'     => 'inputs/test.mp3',
        'stream'       => 'conv.ai',
        'targetFormat' => 'txt',
    ];

    private function noOpPersister(): ConversionResultPersister
    {
        // Real persister; EM finds no conversion → persist() logs + returns (no throw).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return new ConversionResultPersister(
            $registry,
            'test_-results',
            new NullLogger(),
            $this->createStub(QuotaService::class),
        );
    }

    // -------------------------------------------------------------------------
    // Auth (401)
    // -------------------------------------------------------------------------

    public function testResultReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"jobId":"1-0","data":"aGk="}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testResultReturns401WithWrongToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer nope'],
            '{"jobId":"1-0","data":"aGk="}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** The public worker token must NOT authenticate on the internal firewall. */
    public function testResultReturns401WithPublicWorkerToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"jobId":"1-0","data":"aGk="}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFailReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"jobId":"1-0","error":"x"}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // result
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
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1-0","data":"aGk="}',
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testResultReturns400WhenDataMissing(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(self::META);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1-0"}',
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testResultReturns400WhenDataNotBase64(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(self::META);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1-0","data":"!!!!not-base64"}',
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /** Happy path: inline base64 → putObject to S3 + persist(completed) → 200. */
    public function testResultInlinePutsToS3AndPersistsCompleted(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(self::META);
        $container->set(WorkerStreamGateway::class, $gateway);

        // Real S3Storage (final) over a mocked S3Client — assert the object is stored.
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::once())
            ->method('putObject')
            ->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $container->set(ConversionResultPersister::class, $this->noOpPersister());

        $client->request(
            'POST',
            '/api/v1/internal/worker/result',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1717000000000-0","data":"' . base64_encode('hello world') . '","mime":"text/plain","processingMs":123}',
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
    }

    // -------------------------------------------------------------------------
    // fail
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
            '/api/v1/internal/worker/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1-0","error":"boom"}',
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testFailPersistsFailedAndReturns200(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn(self::META);
        $container->set(WorkerStreamGateway::class, $gateway);

        $container->set(ConversionResultPersister::class, $this->noOpPersister());

        $client->request(
            'POST',
            '/api/v1/internal/worker/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"jobId":"1717000000000-0","error":"GPU out of memory"}',
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
    }
}
