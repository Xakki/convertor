<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Entity\WorkerCapability;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Enum\WorkerLivenessStatus;
use App\Repository\ConversionRepository;
use App\Repository\WorkerCapabilityRepository;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
            new EventDispatcher(),
            $this->createStub(ConversionRepository::class),
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

    // -------------------------------------------------------------------------
    // liveness (registry-06)
    // -------------------------------------------------------------------------

    public function testLivenessReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"instances":[]}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** The public worker token must NOT authenticate on the internal firewall. */
    public function testLivenessReturns401WithPublicWorkerToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
            '{"instances":[]}',
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLivenessReturns400WhenInstancesMissing(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{}',
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testLivenessReturns400WhenInstancesNotArray(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"instances":"nope"}',
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * Malformed-batch policy (registry-06 decision, see controller docblock):
     * ANY invalid entry rejects the WHOLE batch, including entries that would
     * otherwise be perfectly valid — proven here by pairing one malformed
     * entry with one well-formed entry for a REAL row and asserting that
     * row's lastSeen was NOT bumped despite being valid.
     */
    public function testLivenessReturns400OnMalformedEntryAndAppliesNothingFromTheBatch(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-malformed-batch');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [
                    [
                        'workerType' => $cap->getWorkerType(),
                        'instanceId' => $cap->getInstanceId(),
                        'status'     => 'alive',
                        'lastSeenAt' => '2099-01-01T00:00:00Z',
                    ],
                    [
                        'workerType' => 'image',
                        'instanceId' => 'x',
                        'status'     => 'not-a-real-status',
                        'lastSeenAt' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertLessThan(
            new \DateTimeImmutable('2098-01-01'),
            $reloaded->getLastSeen(),
            'the well-formed sibling entry must NOT have been applied — whole batch rejected',
        );
    }

    public function testLivenessUpdatesLastSeenForKnownInstance(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-known');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [[
                    'workerType' => $cap->getWorkerType(),
                    'instanceId' => $cap->getInstanceId(),
                    'status'     => 'alive',
                    'lastSeenAt' => '2099-06-15T12:00:00Z',
                    'metrics'    => ['cpu' => 0.42, 'mem' => 0.31, 'load' => 1.5],
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['updated']);
        self::assertSame([], $body['unknown']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2099-06-15 12:00:00', $reloaded->getLastSeen()->format('Y-m-d H:i:s'));
        self::assertSame(WorkerLivenessStatus::Alive, $reloaded->getStatus());
        self::assertSame(
            ['cpu' => 0.42, 'mem' => 0.31, 'load' => 1.5],
            $reloaded->getMetrics(),
            'metrics from the wire payload must now be persisted (Phase 1 cheap wins), not accept-and-ignore',
        );
    }

    /**
     * CNV-61: `inflight` on its own (no `metrics` in the push) must still
     * persist inside the `metrics` JSON blob — cpu/mem/load null (genuinely
     * absent this ping), `inflight` set — never dropped just because the
     * sibling metrics object was omitted.
     */
    public function testLivenessPersistsInflightWithoutMetrics(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-inflight-only');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [[
                    'workerType' => $cap->getWorkerType(),
                    'instanceId' => $cap->getInstanceId(),
                    'status'     => 'alive',
                    'lastSeenAt' => '2099-06-15T12:00:00Z',
                    'inflight'   => 3,
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $reloaded = $this->reload($repo, $cap);
        self::assertSame(
            ['cpu' => null, 'mem' => null, 'load' => null, 'inflight' => 3],
            $reloaded->getMetrics(),
        );
    }

    /**
     * CNV-61: `inflight` rejects the WHOLE batch when malformed (negative or
     * non-integer) — same all-or-nothing malformed-batch policy as `status`/
     * `metrics` above.
     */
    public function testLivenessRejectsNegativeInflight(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-negative-inflight');

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [[
                    'workerType' => $cap->getWorkerType(),
                    'instanceId' => $cap->getInstanceId(),
                    'status'     => 'alive',
                    'lastSeenAt' => '2099-06-15T12:00:00Z',
                    'inflight'   => -1,
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * Idempotent-retry regression guard (review finding): `updateLiveness()`
     * is deliberately SELECT-based, not affected-rows-based, because
     * MySQL/MariaDB's default affected-rows semantics count CHANGED rows,
     * not MATCHED ones — a gateway retry that resends the SAME batch (same
     * `lastSeenAt`, nothing actually changes on the second write) would
     * report `affected=0` under a naive implementation, and a known worker
     * would be misreported as `unknown`, forcing a spurious re-register.
     * This test pins that behaviour: pushing the identical entry twice must
     * report `updated` (not `unknown`) BOTH times, and must never create a
     * second row.
     */
    public function testLivenessIdempotentRetryWithIdenticalLastSeenStillReportsUpdated(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-idempotent-retry');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);

        $payload = json_encode([
            'instances' => [[
                'workerType' => $cap->getWorkerType(),
                'instanceId' => $cap->getInstanceId(),
                'status'     => 'alive',
                'lastSeenAt' => '2099-06-15T12:00:00Z',
            ]],
        ], JSON_THROW_ON_ERROR);

        // First push.
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            $payload,
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $firstBody = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $firstBody['updated']);
        self::assertSame([], $firstBody['unknown']);

        // Retry — byte-identical payload, so the row's last_seen/status do
        // NOT actually change on this second write.
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            $payload,
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $secondBody = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $secondBody['updated'], 'a no-op retry must still be reported as updated, not unknown');
        self::assertSame([], $secondBody['unknown']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2099-06-15 12:00:00', $reloaded->getLastSeen()->format('Y-m-d H:i:s'));

        // No second row was fabricated for the same composite key.
        $matching = array_filter(
            $repo->findAllCapabilities(),
            static fn (WorkerCapability $c): bool => $c->getWorkerType() === $cap->getWorkerType()
                && $c->getInstanceId()                                   === $cap->getInstanceId(),
        );
        self::assertCount(1, $matching, 'the idempotent retry must not create a duplicate row');
    }

    /**
     * `metrics` absent entirely (not just null) is a valid entry — the wire
     * contract makes it optional. Also proves `status` is actually PERSISTED
     * (not just accepted-and-ignored the way `metrics` is — grooming
     * decision, see the controller docblock): pushing `disconnected` for a
     * row that {@see registerCapability()} just created as `alive` must flip
     * the stored status.
     */
    public function testLivenessAcceptsEntryWithoutMetricsAndPersistsDisconnectedStatus(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'liveness-no-metrics');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        self::assertSame(WorkerLivenessStatus::Alive, $cap->getStatus(), 'precondition: register() sets alive');

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [[
                    'workerType' => $cap->getWorkerType(),
                    'instanceId' => $cap->getInstanceId(),
                    'status'     => 'disconnected',
                    'lastSeenAt' => '2099-01-01T00:00:00Z',
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['updated']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame(WorkerLivenessStatus::Disconnected, $reloaded->getStatus());
        self::assertNull($reloaded->getMetrics(), 'omitted metrics must persist as null, not fabricated zeros');
    }

    /**
     * register() unconditionally resets `status` to `alive` on reconnect —
     * even if the instance was previously marked `disconnected` by a
     * liveness push. Without this, a worker that reconnects after a WS drop
     * would read as disconnected forever until the next liveness tick.
     */
    public function testRegisterResetsStatusToAliveOnReconnect(): void
    {
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $cap  = $this->registerCapability('gc-test-fixture', 'reconnect-reset');

        $repo->updateLiveness([[
            'workerType' => $cap->getWorkerType(),
            'instanceId' => $cap->getInstanceId(),
            'status'     => WorkerLivenessStatus::Disconnected,
            'lastSeenAt' => new \DateTimeImmutable(),
        ]]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $disconnected = $repo->find($cap->getId());
        self::assertNotNull($disconnected);
        self::assertSame(WorkerLivenessStatus::Disconnected, $disconnected->getStatus(), 'precondition');

        // Same repository call the register() endpoint makes on reconnect.
        $repo->upsert($cap->getWorkerType(), $cap->getInstanceId(), [
            'workerType'  => $cap->getWorkerType(),
            'instanceId'  => $cap->getInstanceId(),
            'isAi'        => false,
            'streams'     => [],
            'routingKeys' => [],
            'matrix'      => [],
        ]);

        $em->clear();
        $reconnected = $repo->find($cap->getId());
        self::assertNotNull($reconnected);
        self::assertSame(WorkerLivenessStatus::Alive, $reconnected->getStatus());
    }

    /**
     * Central no-fabrication guarantee (registry-06): an unrecognized
     * (workerType, instanceId) is reported in `unknown`, and — the part a
     * naive upsert-style implementation would get wrong — NO row is created
     * for it.
     */
    public function testLivenessReportsUnknownInstanceAndCreatesNoRow(): void
    {
        $client          = static::createClient();
        $repo            = static::getContainer()->get(WorkerCapabilityRepository::class);
        $unknownType     = 'gc-test-fixture';
        $unknownInstance = 'never-registered-' . bin2hex(random_bytes(4));

        self::assertSame(
            [],
            array_filter(
                $repo->findAllCapabilities(),
                static fn (WorkerCapability $c): bool => $c->getWorkerType() === $unknownType && $c->getInstanceId() === $unknownInstance,
            ),
            'precondition: this instance must not already exist',
        );

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [[
                    'workerType' => $unknownType,
                    'instanceId' => $unknownInstance,
                    'status'     => 'alive',
                    'lastSeenAt' => '2099-01-01T00:00:00Z',
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['updated']);
        self::assertSame([['workerType' => $unknownType, 'instanceId' => $unknownInstance]], $body['unknown']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stillMissing = array_filter(
            $repo->findAllCapabilities(),
            static fn (WorkerCapability $c): bool => $c->getWorkerType() === $unknownType && $c->getInstanceId() === $unknownInstance,
        );
        self::assertSame([], $stillMissing, 'liveness push for an unknown instance must NEVER create a row');
    }

    /**
     * A batch mixing a known and an unknown instance: the known one updates,
     * the unknown one is reported — proves per-key resolution, not
     * all-or-nothing at the DB layer (distinct from the malformed-INPUT
     * all-or-nothing policy tested above, which is about VALIDATION, not
     * unknown-key handling).
     */
    public function testLivenessHandlesMixedKnownAndUnknownInstancesInOneBatch(): void
    {
        $client    = static::createClient();
        $cap       = $this->registerCapability('gc-test-fixture', 'liveness-mixed-known');
        $repo      = static::getContainer()->get(WorkerCapabilityRepository::class);
        $unknownId = 'never-registered-' . bin2hex(random_bytes(4));

        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode([
                'instances' => [
                    [
                        'workerType' => $cap->getWorkerType(),
                        'instanceId' => $cap->getInstanceId(),
                        'status'     => 'alive',
                        'lastSeenAt' => '2099-03-03T03:03:03Z',
                    ],
                    [
                        'workerType' => 'gc-test-fixture',
                        'instanceId' => $unknownId,
                        'status'     => 'alive',
                        'lastSeenAt' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['updated']);
        self::assertSame([['workerType' => 'gc-test-fixture', 'instanceId' => $unknownId]], $body['unknown']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2099-03-03 03:03:03', $reloaded->getLastSeen()->format('Y-m-d H:i:s'));
    }

    public function testLivenessAcceptsEmptyInstancesArray(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            '{"instances":[]}',
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['updated']);
        self::assertSame([], $body['unknown']);
    }

    // -------------------------------------------------------------------------
    // liveness reconcile (registry-09) — gateway = источник истины о подключённых
    //
    // Инвариант целиком описан в App\Service\Worker\WorkerLivenessReconciler;
    // тесты ниже пиннят ровно его четыре условия и обе ветки обратной
    // совместимости (пуш без флагов = дельта, как в registry-06).
    // -------------------------------------------------------------------------

    /**
     * Главный сценарий: инстанс, о котором gateway не отчитался целое окно
     * тишины, гасится в `disconnected` — та самая ложь админ-панели, ради
     * которой сделан эпик. Строка НЕ удаляется и `lastSeen` НЕ двигается
     * (это вход GC и колонки «Свежесть»).
     */
    public function testAuthoritativeSnapshotOfflinesSilentAliveInstance(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-silent');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $this->ageLastSeen($cap, '2020-01-01 00:00:00');

        $this->pushLiveness($client, [], ['snapshot' => true, 'authoritative' => true, 'gatewayId' => 'gw-a']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(1, $body['offlined']);

        $reloaded = $this->reload($repo, $cap);
        self::assertSame(WorkerLivenessStatus::Disconnected, $reloaded->getStatus());
        self::assertSame(
            '2020-01-01 00:00:00',
            $reloaded->getLastSeen()->format('Y-m-d H:i:s'),
            'сверка меняет только status — lastSeen кормит GC и колонку «Свежесть»',
        );
    }

    /**
     * Инстанс ЕСТЬ в снапшоте, но его `lastSeenAt` уже старый (подключён, но
     * не шлёт ping'и). Гасить его нельзя — спасает явное исключение ключей
     * снапшота, а не одно только условие по времени.
     */
    public function testInstancePresentInSnapshotIsNeverOfflinedEvenWithOldLastSeen(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-present');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $this->ageLastSeen($cap, '2020-01-01 00:00:00');

        $this->pushLiveness(
            $client,
            [[
                'workerType' => $cap->getWorkerType(),
                'instanceId' => $cap->getInstanceId(),
                'status'     => 'alive',
                'lastSeenAt' => '2020-06-01T00:00:00Z',
            ]],
            ['snapshot' => true, 'authoritative' => true],
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(WorkerLivenessStatus::Alive, $this->reload($repo, $cap)->getStatus());
    }

    /**
     * Прогрев gateway после рестарта: `authoritative: false` — снапшот заведомо
     * неполный (воркеры ещё переподключаются), сверка не запускается вовсе.
     */
    public function testNonAuthoritativeSnapshotDoesNotOfflineAnything(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-warmup');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $this->ageLastSeen($cap, '2020-01-01 00:00:00');

        $this->pushLiveness($client, [], ['snapshot' => true, 'authoritative' => false]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['offlined']);
        self::assertSame(WorkerLivenessStatus::Alive, $this->reload($repo, $cap)->getStatus());
    }

    /**
     * Обратная совместимость: gateway СТАРОГО билда шлёт батч без конверта —
     * поведение обязано остаться ровно registry-06 (дельта, без сверки).
     */
    public function testPushWithoutSnapshotFlagStaysDeltaOnly(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-legacy');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        $this->ageLastSeen($cap, '2020-01-01 00:00:00');

        $this->pushLiveness($client, []);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['offlined']);
        self::assertSame(WorkerLivenessStatus::Alive, $this->reload($repo, $cap)->getStatus());
    }

    /**
     * МНОГО GATEWAY: воркер висит на ДРУГОМ gateway, тот каждый цикл обновляет
     * ему `lastSeen`. Снапшот первого gateway его не содержит — и всё равно не
     * должен его погасить. Именно это свойство даёт условие по окну тишины,
     * поэтому колонка «владельца» (gateway_id) и не понадобилась.
     */
    public function testSnapshotFromOneGatewayDoesNotOfflineAnotherGatewaysWorker(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-other-gw');
        $repo   = static::getContainer()->get(WorkerCapabilityRepository::class);
        // registerCapability() уже проставил lastSeen = now — ровно то, что
        // делает пуш второго gateway каждый цикл.

        $this->pushLiveness($client, [], ['snapshot' => true, 'authoritative' => true, 'gatewayId' => 'gw-a']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(WorkerLivenessStatus::Alive, $this->reload($repo, $cap)->getStatus());
    }

    /**
     * Seed-строка (registry-03) — не процесс: у неё вечно древний lastSeen и
     * она никогда не получает liveness-пуш. Сверка обязана её не трогать,
     * иначе админка показывала бы «снимок матрицы» как упавший воркер.
     */
    public function testSeedRowIsNeverOfflinedByReconcile(): void
    {
        $client = static::createClient();
        $conn   = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // Худший случай для seed: статус alive + древний last_seen (дата
        // seed-миграции) — под все условия сверки, кроме исключения по
        // instance_id. Исходное состояние восстанавливаем в finally.
        $conn->executeStatement(
            "UPDATE worker_capabilities SET status = 'alive', last_seen = '2020-01-01 00:00:00' WHERE instance_id = '__seed__'",
        );

        try {
            $this->pushLiveness($client, [], ['snapshot' => true, 'authoritative' => true]);
            self::assertSame(200, $client->getResponse()->getStatusCode());

            $stillAlive = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM worker_capabilities WHERE instance_id = '__seed__' AND status = 'alive'",
            );
            $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM worker_capabilities WHERE instance_id = '__seed__'");
            self::assertSame($total, $stillAlive, 'seed-строки не гасятся сверкой ни при каких условиях');
        } finally {
            $conn->executeStatement(
                "UPDATE worker_capabilities SET status = 'unknown' WHERE instance_id = '__seed__'",
            );
        }
    }

    /**
     * Строка, уже помеченной `disconnected`, повторно не «гасится» — счётчик
     * `offlined` считает реальные переходы, а не совпадения по условию.
     */
    public function testAlreadyDisconnectedRowIsNotCountedAgain(): void
    {
        $client = static::createClient();
        $cap    = $this->registerCapability('gc-test-fixture', 'reconcile-idempotent');
        $this->ageLastSeen($cap, '2020-01-01 00:00:00');

        $meta = ['snapshot' => true, 'authoritative' => true];
        $this->pushLiveness($client, [], $meta);
        $first = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->pushLiveness($client, [], $meta);
        $second = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(1, $first['offlined']);
        self::assertSame(0, $second['offlined'], 'второй проход по тем же строкам — no-op');
    }

    /**
     * POST на liveness-эндпоинт с опциональным конвертом registry-09.
     *
     * @param list<array<string, mixed>> $instances
     * @param array<string, mixed>       $meta
     */
    private function pushLiveness(KernelBrowser $client, array $instances, array $meta = []): void
    {
        $client->request(
            'POST',
            '/api/v1/internal/worker/liveness',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-internal-token'],
            json_encode(['instances' => $instances] + $meta, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Состарить `last_seen` строки нативным SQL — тем же путём, каким его
     * читает сверка (мимо UnitOfWork), чтобы тест не зависел от identity map.
     */
    private function ageLastSeen(WorkerCapability $cap, string $lastSeen): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE worker_capabilities SET last_seen = :lastSeen WHERE id = :id',
            ['lastSeen' => $lastSeen, 'id' => $cap->getId()],
        );
    }

    private function reload(WorkerCapabilityRepository $repo, WorkerCapability $cap): WorkerCapability
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $repo->find($cap->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * Registers a real capability row (workerType/instanceId not colliding
     * with any registry-03 seed type) via the same repository the register()
     * endpoint uses, so liveness tests exercise a genuinely "known" instance.
     */
    private function registerCapability(string $workerType, string $instanceId): WorkerCapability
    {
        $repo = static::getContainer()->get(WorkerCapabilityRepository::class);
        $cap  = $repo->upsert($workerType, $instanceId, [
            'workerType'  => $workerType,
            'instanceId'  => $instanceId,
            'isAi'        => false,
            'streams'     => [],
            'routingKeys' => [],
            'matrix'      => [],
        ]);
        $this->toRemove[] = $cap;

        return $cap;
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
