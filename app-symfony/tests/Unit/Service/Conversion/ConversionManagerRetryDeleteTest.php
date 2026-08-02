<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Exception\ConversionDisabledException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionToggleService;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\CopyObjectRequest;
use AsyncAws\S3\Input\DeleteObjectRequest;
use AsyncAws\S3\Result\CopyObjectOutput;
use AsyncAws\S3\Result\DeleteObjectOutput;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CNV-8: retry (новая строка + S3 copy + квота) и hard-delete (+ S3) в
 * ConversionManager. Owner-scope, 410 при отсутствии исходника, path-safe keys.
 */
final class ConversionManagerRetryDeleteTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testRetryCreatesNewConversionCopiesInputAndChargesQuota(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('check')->with($owner, false);
        $quota->expects($this->once())->method('charge')->with($owner, false);

        $copied   = [];
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->once())->method('copyObject')->willReturnCallback(
            function (CopyObjectRequest $req) use (&$copied): CopyObjectOutput {
                $copied[] = [
                    'src' => (string) $req->getCopySource(),
                    'dst' => (string) $req->getKey(),
                ];

                return ResultMockFactory::create(CopyObjectOutput::class);
            },
        );

        $persisted = [];
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
                if ($entity instanceof Conversion) {
                    (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 99);
                }
            },
        );
        $em->expects($this->once())->method('flush');

        $dispatched = 0;
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
                ++$dispatched;

                return new Envelope($message, $stamps);
            },
        );

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($source);

        $manager = $this->buildManager($quota, $s3Client, $em, $repo, $bus);
        $retry   = $manager->retryConversion(42, $owner);

        self::assertSame(99, $retry->getId());
        self::assertNotSame($source, $retry);
        self::assertSame('jpg', $retry->getFromFormat());
        self::assertSame('png', $retry->getToFormat());
        self::assertSame(ConversionStatus::Pending, $retry->getStatus());
        self::assertCount(1, $copied);
        self::assertStringContainsString('inputs/2026/08/01/aabbccddeeff0011.jpg', $copied[0]['src']);
        self::assertStringStartsWith('inputs/', $copied[0]['dst']);
        self::assertNotSame($source->getInputFile()->getStoragePath(), $retry->getInputFile()->getStoragePath());
        self::assertSame(1, $dispatched);
    }

    public function testRetryThrows410WhenInputMissingInS3(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/deadbeefdeadbeef.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturnCallback(
            static function (): never {
                throw new \AsyncAws\S3\Exception\NoSuchKeyException(
                    new \Symfony\Component\HttpClient\Response\MockResponse(
                        '<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>',
                        ['http_code' => 404],
                    ),
                );
            },
        );
        $s3Client->expects($this->never())->method('copyObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(GoneHttpException::class);
        $manager->retryConversion(42, $owner);
    }

    public function testRetryRejectsNonOwner(): void
    {
        $owner  = $this->userWithId(10);
        $other  = $this->userWithId(11);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('headObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversion not found');
        $manager->retryConversion(42, $other);
    }

    public function testRetryRejectsUnsafeStorageKeyBeforeS3(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, '../etc/passwd');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('headObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid storage path');
        $manager->retryConversion(42, $owner);
    }

    public function testRetryRespectsToggleDisabled(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->never())->method('copyObject');

        $toggle = $this->createMock(ConversionToggleService::class);
        $toggle->method('isEnabled')->with('jpg', 'png')->willReturn(false);

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
            toggle: $toggle,
        );

        $this->expectException(ConversionDisabledException::class);
        $manager->retryConversion(42, $owner);
    }

    public function testDeleteRemovesDbRowsAndS3Objects(): void
    {
        $owner = $this->userWithId(10);
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/2026/08/01/aabbccddeeff0011.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(100);
        $output = (new FileStorage())
            ->setOriginalName('out.png')
            ->setStoragePath('results/2026/08/01/ffeeddccbbaa0011.png')
            ->setMimeType('image/png')
            ->setSizeBytes(200);
        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image)
            ->setStatus(ConversionStatus::Completed);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        $deleted  = [];
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->exactly(2))->method('deleteObject')->willReturnCallback(
            function (DeleteObjectRequest $req) use (&$deleted): DeleteObjectOutput {
                $deleted[] = ['bucket' => (string) $req->getBucket(), 'key' => (string) $req->getKey()];

                return ResultMockFactory::create(DeleteObjectOutput::class);
            },
        );

        $removed = [];
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity::class;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($conv);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $em,
            $repo,
        );
        $manager->deleteConversion(42, $owner);

        self::assertSame([
            ['bucket' => 'convertor-inputs', 'key' => 'inputs/2026/08/01/aabbccddeeff0011.jpg'],
            ['bucket' => 'convertor-results', 'key' => 'results/2026/08/01/ffeeddccbbaa0011.png'],
        ], $deleted);
        self::assertSame([Conversion::class, FileStorage::class, FileStorage::class], $removed);
    }

    public function testDeleteRejectsUnsafeResultKey(): void
    {
        $owner = $this->userWithId(10);
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/2026/08/01/aabbccddeeff0011.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(100);
        $output = (new FileStorage())
            ->setOriginalName('evil')
            ->setStoragePath('../../secrets')
            ->setMimeType('image/png')
            ->setSizeBytes(1);
        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        $s3Client = $this->createMock(S3Client::class);
        // input удаляется до проверки output — один deleteObject допустим.
        $s3Client->expects($this->once())->method('deleteObject')->willReturn(
            ResultMockFactory::create(DeleteObjectOutput::class),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($conv);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $em,
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid storage path');
        $manager->deleteConversion(42, $owner);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function seedSource(User $owner, string $inputKey): Conversion
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
            ->setStatus(ConversionStatus::Completed)
            ->setIsAi(false)
            ->setIsOcr(false);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        return $conv;
    }

    private function buildManager(
        QuotaService $quota,
        S3Client $s3Client,
        EntityManagerInterface $em,
        ConversionRepository $repo,
        ?MessageBusInterface $bus = null,
        ?ConversionToggleService $toggle = null,
    ): ConversionManager {
        $bus ??= $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        return new ConversionManager(
            $this->newSeedRegistry(),
            $repo,
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            $toggle,
        );
    }
}
