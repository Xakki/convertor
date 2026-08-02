<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Exception\ConversionDisabledException;
use App\Repository\ConversionRepository;
use App\Repository\ConversionToggleRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionToggleService;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Toggle-гейт (вкл/выкл пары) в ConversionManager::createConversion.
 *
 * Проверяем: отключённая пара (ConversionToggleService::isEnabled → false)
 * бросает ConversionDisabledException ДО quota/S3-эффектов и не уходит в очередь;
 * включённая пара проходит штатно. Гранулярность — по паре (from→to).
 */
final class ConversionManagerToggleTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testDisabledPairRejectedBeforeSideEffects(): void
    {
        // jpg→txt отключена админом → режется до любых эффектов.
        $toggle = $this->toggleService(['jpg>txt']);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('maxUploadBytes');
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $manager = $this->buildManager($quota, $s3Client, $this->createStub(EntityManagerInterface::class), $toggle, $bus);

        $this->expectException(ConversionDisabledException::class);
        $this->expectExceptionMessage('Конвертация временно отключена');

        $manager->createConversion(new ConversionRequestDTO(new User(), $this->makeJpgUpload(), 'txt', false, true));
    }

    public function testEnabledPairProceeds(): void
    {
        // jpg→txt включена (нет ряда в disabled-set) → штатный сабмит.
        $toggle = $this->toggleService([]);

        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), FileCategory::Image, false);
        $quota->expects($this->once())->method('charge')->with($this->isInstanceOf(User::class), FileCategory::Image, false);

        $manager = $this->buildManager($quota, $this->okS3Client(), $this->stampingEm(), $toggle);

        $conversion = $manager->createConversion(new ConversionRequestDTO(new User(), $this->makeJpgUpload(), 'txt', false, true));

        self::assertSame('txt', $conversion->getToFormat());
        self::assertSame('image', $conversion->getCategory()->value);
    }

    private function buildManager(
        QuotaService $quota,
        S3Client $s3Client,
        EntityManagerInterface $em,
        ConversionToggleService $toggle,
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
            $toggle,
        );
    }

    /**
     * Реальный ConversionToggleService со stub-репозиторием (без БД, cache=null):
     * disabledPairKeys() отдаёт заданное множество отключённых пар.
     *
     * @param list<string> $disabledKeys ключи «from>to»
     */
    private function toggleService(array $disabledKeys): ConversionToggleService
    {
        $repo = $this->createStub(ConversionToggleRepository::class);
        $repo->method('disabledPairKeys')->willReturn($disabledKeys);

        return new ConversionToggleService($repo, $this->createStub(EntityManagerInterface::class), null);
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

    private function makeJpgUpload(): UploadedFile
    {
        $bytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $path  = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'sample.jpg', null, null, true);
    }
}
