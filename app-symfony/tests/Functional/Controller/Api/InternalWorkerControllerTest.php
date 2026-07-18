<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
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
    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $entity) {
                $managed = $em->contains($entity) ? $entity : $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

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

    /**
     * hardening-06: fail-путь читает `processingMs` из тела и персистит его — раньше
     * контроллер хардкодил `processingMs => null`, даже когда воркер его прислал.
     */
    public function testFailPersistsProcessingMs(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $owner = new User();
        $em->persist($owner);
        $em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('audio.mp3')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.mp3')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('mp3')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Audio)
            ->setStatus(ConversionStatus::Processing)
            ->setIsAi(true)
            ->setIsOcr(false);
        $em->persist($conversion);
        $em->flush();
        $this->toRemove[] = $conversion;

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn([
            'conversionId' => $conversion->getId(),
            'inputBucket'  => 'test_-inputs',
            'inputKey'     => $inputFile->getStoragePath(),
            'stream'       => 'conv.ai',
            'targetFormat' => 'txt',
        ]);
        $container->set(WorkerStreamGateway::class, $gateway);

        $client->request(
            'POST',
            '/api/v1/internal/worker/fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'jobId'        => $conversion->getId() . '-0',
                'error'        => 'GPU out of memory',
                'processingMs' => 789,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Failed, $reloaded->getStatus());
        self::assertSame(789, $reloaded->getProcessingMs());
    }

    // -------------------------------------------------------------------------
    // dlq-fail
    // -------------------------------------------------------------------------

    public function testDlqFailReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"conversionId":99999,"reason":"retries exhausted"}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testDlqFailReturns401WithPublicWorkerToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"conversionId":99999,"reason":"retries exhausted"}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testDlqFailReturns400WhenConversionIdMissing(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"reason":"retries exhausted"}',
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testDlqFailReturns404WhenConversionNotFound(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"conversionId":999999999,"reason":"retries exhausted"}',
        );
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * Happy path: pending Conversion → dlq-fail → status=failed, reason +
     * processingMs persisted (persist() reused as-is, same as fail()).
     */
    public function testDlqFailPersistsFailedWithReasonAndProcessingMs(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $conversion = $this->persistPendingConversion($em);

        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'conversionId' => $conversion->getId(),
                'reason'       => 'Job exhausted retries in DLQ',
                'processingMs' => 456,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Failed, $reloaded->getStatus());
        self::assertSame('Job exhausted retries in DLQ', $reloaded->getErrorMessage());
        self::assertSame(456, $reloaded->getProcessingMs());
    }

    /**
     * Idempotency: a conversion already Failed stays Failed and the request
     * still succeeds (persist()'s status-guard skips the second write, no
     * double refund) — DLQ-consumer redelivery must not error.
     */
    public function testDlqFailIsIdempotentWhenAlreadyFailed(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $conversion = $this->persistPendingConversion($em);
        $conversion->setStatus(ConversionStatus::Failed);
        $conversion->setErrorMessage('first failure');
        $em->flush();

        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'conversionId' => $conversion->getId(),
                'reason'       => 'redelivered DLQ entry',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Failed, $reloaded->getStatus());
        // Idempotency guard skipped the second write entirely — original message stands.
        self::assertSame('first failure', $reloaded->getErrorMessage());
    }

    /**
     * requeue-attempt-generation-marker MAJOR #2: a stale dlq-fail for an
     * attempt an operator requeue already superseded must be a no-op — no
     * status change, no error message, no refund (refund is asserted
     * indirectly via the unchanged status: persist() only calls refund() on
     * the `state===failed` branch, which the stale-guard now short-circuits
     * before it is reached).
     */
    public function testDlqFailIgnoresStaleAttempt(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $conversion = $this->persistPendingConversion($em);
        $conversion->setAttempt(1);
        $em->flush();

        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'conversionId' => $conversion->getId(),
                'reason'       => 'stale duplicate DLQ entry for a superseded attempt',
                'attempt'      => 0,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        // Stale-guard skipped the write entirely: status stays exactly as it was
        // BEFORE this request (Pending, as left by a requeue) — no error message,
        // attempt unchanged.
        self::assertSame(ConversionStatus::Pending, $reloaded->getStatus());
        self::assertNull($reloaded->getErrorMessage());
        self::assertSame(1, $reloaded->getAttempt());
    }

    /**
     * Boundary: attempt in the body equal to (not older than) the row's current
     * attempt is NOT stale — normal finalization proceeds (guard is `<`, not `<=`).
     */
    public function testDlqFailFinalizesWhenAttemptMatchesCurrent(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $conversion = $this->persistPendingConversion($em);
        $conversion->setAttempt(1);
        $em->flush();

        $client->request(
            'POST',
            '/api/v1/internal/worker/dlq-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'conversionId' => $conversion->getId(),
                'reason'       => 'current-attempt DLQ entry',
                'attempt'      => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Failed, $reloaded->getStatus());
        self::assertSame('current-attempt DLQ entry', $reloaded->getErrorMessage());
    }

    private function persistPendingConversion(EntityManagerInterface $em): Conversion
    {
        $owner = new User();
        $em->persist($owner);
        $em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('audio.mp3')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.mp3')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('mp3')
            ->setToFormat('txt')
            ->setCategory(FileCategory::Audio)
            ->setStatus(ConversionStatus::Pending)
            ->setIsAi(true)
            ->setIsOcr(false);
        $em->persist($conversion);
        $em->flush();
        $this->toRemove[] = $conversion;

        return $conversion;
    }
}
