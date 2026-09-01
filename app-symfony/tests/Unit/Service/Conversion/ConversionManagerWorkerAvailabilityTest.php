<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\FileCategory;
use App\Exception\WorkerUnavailableException;
use App\Repository\ConversionRepository;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ChainEnablement;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CNV-71-03: гибридная проверка "воркер существует" в
 * {@see ConversionManager} — ни одной строки в `worker_capabilities` для
 * нужного workerType (пары from→to) → немедленный
 * {@see WorkerUnavailableException} before size/quota/S3 effects. Normal worker
 * types use durable row existence, including offline/stale rows; API uses fresh
 * alive registrations with a validated model contract.
 *
 * Reachable end-to-end since CNV-71-04 (seed rows removed) — see
 * {@see \App\Tests\Functional\Service\Conversion\ConversionManagerWorkerAvailabilityFunctionalTest}
 * for the real-DB proof. This test stays at the unit level with a
 * stub/empty repository — cheaper and doesn't need a real DB connection.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConversionManagerWorkerAvailabilityTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testNoWorkerRowRejectsSingleHopBeforeSideEffects(): void
    {
        $workerCapabilities = $this->createMock(WorkerCapabilityRepository::class);
        $workerCapabilities->expects($this->once())
            ->method('existsForWorkerType')
            ->with('image')
            ->willReturn(false);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('maxUploadBytes');
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $manager = $this->buildManager($quota, $s3Client, $this->createStub(EntityManagerInterface::class), $workerCapabilities, $bus);

        $this->expectException(WorkerUnavailableException::class);
        $this->expectExceptionMessage('Конвертация временно недоступна');

        $manager->createConversion(new ConversionRequestDTO(new User(), $this->makeJpgUpload(), 'png', false, true));
    }

    public function testRegisteredWorkerTypeAllowsSingleHopToProceed(): void
    {
        $workerCapabilities = $this->createMock(WorkerCapabilityRepository::class);
        $workerCapabilities->expects($this->once())
            ->method('existsForWorkerType')
            ->with('image')
            ->willReturn(true);

        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->willReturn(BillingMode::PlanQuota);
        $quota->expects($this->once())->method('charge');

        $manager = $this->buildManager($quota, $this->okS3Client(), $this->stampingEm(), $workerCapabilities);

        $conversion = $manager->createConversion(new ConversionRequestDTO(new User(), $this->makeJpgUpload(), 'png', false, true));

        self::assertSame('png', $conversion->getToFormat());
    }

    public function testChainHopRejectedWhenNoWorkerRowForHopWorkerType(): void
    {
        // epub→pdf идёт цепочкой epub→docx→pdf, оба хопа — workerType 'document'.
        $workerCapabilities = $this->createMock(WorkerCapabilityRepository::class);
        $workerCapabilities->expects($this->once())
            ->method('existsForWorkerType')
            ->with('document')
            ->willReturn(false);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('checkPlan');
        $quota->expects($this->never())->method('check');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $manager = $this->buildChainManager($quota, new ChainEnablement('epub:pdf'), $workerCapabilities, $bus);

        $this->expectException(WorkerUnavailableException::class);
        $this->expectExceptionMessage('Конвертация временно недоступна');

        $manager->createConversion(new ConversionRequestDTO(
            $this->makeUser(),
            $this->makeEpubUpload(),
            'pdf',
            false,
            true,
        ));
    }

    /**
     * CNV-71-03 review gap: retryConversion() had the toggle-гейт но не
     * worker-availability-гейт — пара без строки в worker_capabilities
     * ставилась в очередь и висела до gateway-таймаута вместо немедленного 503.
     */
    public function testRetryRejectsWhenNoWorkerRowBeforeQuota(): void
    {
        $owner  = $this->makeUser();
        $source = $this->seedRetrySource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $workerCapabilities = $this->createMock(WorkerCapabilityRepository::class);
        $workerCapabilities->expects($this->once())
            ->method('existsForWorkerType')
            ->with('image')
            ->willReturn(false);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->never())->method('copyObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildRetryManager($quota, $s3Client, $this->createStub(EntityManagerInterface::class), $repo, $workerCapabilities);

        $this->expectException(WorkerUnavailableException::class);
        $this->expectExceptionMessage('Конвертация временно недоступна');

        $manager->retryConversion(42, $owner);
    }

    public function testRetryProceedsWhenWorkerRowPresent(): void
    {
        $owner  = $this->makeUser();
        $source = $this->seedRetrySource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $workerCapabilities = $this->createMock(WorkerCapabilityRepository::class);
        $workerCapabilities->expects($this->once())
            ->method('existsForWorkerType')
            ->with('image')
            ->willReturn(true);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('check')->willReturn(BillingMode::PlanQuota);
        $quota->expects($this->once())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $s3Client->expects($this->once())->method('copyObject')->willReturn(
            ResultMockFactory::create(\AsyncAws\S3\Result\CopyObjectOutput::class),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 99);
            }
        });

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        $manager = $this->buildRetryManager($quota, $s3Client, $em, $repo, $workerCapabilities, $bus);
        $retry   = $manager->retryConversion(42, $owner);

        self::assertSame(99, $retry->getId());
    }

    private function seedRetrySource(User $owner, string $inputKey): Conversion
    {
        $input = (new FileStorage())
            ->setOriginalName('photo.jpg')
            ->setStoragePath($inputKey)
            ->setMimeType('image/jpeg')
            ->setSizeBytes(1234);

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image)
            ->setIsAi(false)
            ->setIsOcr(false);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        return $conv;
    }

    private function buildRetryManager(
        QuotaService $quota,
        S3Client $s3Client,
        EntityManagerInterface $em,
        ConversionRepository $repo,
        WorkerCapabilityRepository $workerCapabilities,
        ?MessageBusInterface $bus = null,
    ): ConversionManager {
        if ($bus === null) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(
                static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
            );
        }

        return new ConversionManager(
            $this->newSeedRegistry(),
            $repo,
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(QuotaService::class),
            ),
            null,
            null,
            null,
            $workerCapabilities,
        );
    }

    private function buildManager(
        QuotaService $quota,
        S3Client $s3Client,
        EntityManagerInterface $em,
        WorkerCapabilityRepository $workerCapabilities,
        ?MessageBusInterface $bus = null,
    ): ConversionManager {
        if ($bus === null) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(
                static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
            );
        }

        return new ConversionManager(
            $this->newSeedRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(QuotaService::class),
            ),
            null,
            null,
            null,
            $workerCapabilities,
        );
    }

    private function buildChainManager(
        QuotaService $quota,
        ChainEnablement $enablement,
        WorkerCapabilityRepository $workerCapabilities,
        MessageBusInterface $bus,
    ): ConversionManager {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 42);
            }
        });

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        return new ConversionManager(
            $this->newSeedRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $em,
                $quota,
            ),
            null,
            null,
            $enablement,
            $workerCapabilities,
        );
    }

    private function okS3Client(): S3Client
    {
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        return $s3Client;
    }

    private function stampingEm(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 55);
            }
        });

        return $em;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setEmail('worker-availability-test@example.com');
        $user->setPlan('free');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);

        return $user;
    }

    private function makeJpgUpload(): UploadedFile
    {
        $bytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $path  = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'sample.jpg', null, null, true);
    }

    private function makeEpubUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epub');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "PK\x03\x04fake-epub-content");

        return new UploadedFile($tmp, 'book.epub', 'application/epub+zip', null, true);
    }
}
