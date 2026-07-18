<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Storage\S3Storage;
use App\Service\Worker\WorkerStreamGateway;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    /**
     * hardening-06: large-путь (multipart) читает `processingMs` из form-поля и
     * персистит его — ровно как inline-путь (InternalWorkerControllerTest). Раньше
     * контроллер хардкодил `processingMs => null`.
     */
    public function testResultUploadsAndPersistsProcessingMs(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $owner = new User();
        $em->persist($owner);
        $em->flush();
        $this->toRemove[] = $owner;

        $inputFile = (new FileStorage())
            ->setOriginalName('report.docx')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.docx')
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setFromFormat('docx')
            ->setToFormat('pdf')
            ->setCategory(FileCategory::Document)
            ->setStatus(ConversionStatus::Processing)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conversion);
        $em->flush();
        $this->toRemove[] = $conversion;

        $gateway = $this->createStub(WorkerStreamGateway::class);
        $gateway->method('getJobMeta')->willReturn([
            'conversionId' => $conversion->getId(),
            'inputBucket'  => 'test_-inputs',
            'inputKey'     => $inputFile->getStoragePath(),
            'stream'       => 'conv.document',
            'targetFormat' => 'pdf',
        ]);
        $container->set(WorkerStreamGateway::class, $gateway);

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects(self::once())
            ->method('putObject')
            ->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        $tmpFile = tempnam(sys_get_temp_dir(), 'worker-result-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'result bytes');

        $client->request(
            'POST',
            "/api/v1/worker/jobs/{$conversion->getId()}-0/result",
            ['processingMs'       => '4567'],
            ['file'               => new UploadedFile($tmpFile, 'out.pdf', 'application/pdf', null, true)],
            ['HTTP_AUTHORIZATION' => 'Bearer test-worker-token'],
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $em->clear();
        $reloaded = $em->find(Conversion::class, $conversion->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ConversionStatus::Completed, $reloaded->getStatus());
        self::assertSame(4567, $reloaded->getProcessingMs());
        // persist() создал новый output FileStorage (не cascade-удаляемый) — прибрать за собой.
        // $conversion (FK-держатель) должен быть удалён РАНЬШЕ outputFile — переставляем его
        // в конец списка, чтобы tearDown (reverse-order) снёс его первым.
        if ($reloaded->getOutputFile() !== null) {
            $outputFile     = $reloaded->getOutputFile();
            $this->toRemove = array_values(array_filter(
                $this->toRemove,
                static fn (object $e): bool => $e !== $conversion,
            ));
            $this->toRemove[] = $outputFile;
            $this->toRemove[] = $conversion;
        }

        unlink($tmpFile);
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
