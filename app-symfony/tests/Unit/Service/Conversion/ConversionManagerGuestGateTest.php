<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Exception\AuthRequiredException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
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
 * Гейт ai/video для гостя (privileged=false) в ConversionManager::createConversion.
 *
 * Проверяем: пара, требующая логина (isAi ИЛИ category=Video), у гостя бросает
 * AuthRequiredException ДО любых size/quota/S3-эффектов; не-ai/не-video пара у
 * гостя проходит; залогиненный (privileged=true) проходит ai/video.
 */
final class ConversionManagerGuestGateTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testGuestAiPairThrowsAuthRequiredBeforeSideEffects(): void
    {
        // mp3→txt = STT = isAi. Ни quota, ни S3 не должны быть тронуты.
        $this->assertGuestGated('mp3', 'txt');
    }

    public function testGuestVideoPairThrowsAuthRequiredBeforeSideEffects(): void
    {
        // mp4→mkv = FileCategory::Video, non-AI. Гость режется гейтом.
        $this->assertGuestGated('mp4', 'mkv');
    }

    public function testGuestNonAiNonVideoPairSucceeds(): void
    {
        // jpg→txt = image, non-AI. Гость конвертит свободно.
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), FileCategory::Image, false);
        $quota->expects($this->once())->method('charge')->with($this->isInstanceOf(User::class), FileCategory::Image, false);

        $manager = $this->buildManager($quota, $this->okS3Client(), $this->stampingEm());

        $conversion = $manager->createConversion(new ConversionRequestDTO(
            $this->guestUser(),
            $this->makeUpload('jpg'),
            'txt',
            false,
            false, // privileged=false (гость)
        ));

        self::assertFalse($conversion->isAi());
        self::assertSame('image', $conversion->getCategory()->value);
    }

    public function testPrivilegedUserPassesAiPair(): void
    {
        // Залогиненный (privileged=true) проходит ai-пару штатно.
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), FileCategory::Audio, true);
        $quota->expects($this->once())->method('charge')->with($this->isInstanceOf(User::class), FileCategory::Audio, true);

        $manager = $this->buildManager($quota, $this->okS3Client(), $this->stampingEm());

        $conversion = $manager->createConversion(new ConversionRequestDTO(
            new User(),
            $this->makeUpload('mp3'),
            'txt',
            false,
            true, // privileged=true
        ));

        self::assertTrue($conversion->isAi());
    }

    public function testTransientGuestIsMaterializedBeforeConversionPersist(): void
    {
        // Ленивая материализация: транзиентный гость (id===null) персистится в
        // createConversion ПЕРЕД Conversion (Conversion.user NOT NULL, без каскада).
        $quota = $this->createStub(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->method('check');
        $quota->method('charge');

        $persistOrder = [];
        $em           = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persistOrder): void {
            $persistOrder[] = $entity::class;
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 55);
            }
        });

        $manager = $this->buildManager($quota, $this->okS3Client(), $em);

        $guest = $this->guestUser();
        self::assertNull($guest->getId(), 'precondition: guest is transient');

        $manager->createConversion(new ConversionRequestDTO($guest, $this->makeUpload('jpg'), 'txt', false, false));

        self::assertContains(User::class, $persistOrder, 'transient guest must be persisted');
        self::assertContains(Conversion::class, $persistOrder);
        self::assertLessThan(
            array_search(Conversion::class, $persistOrder, true),
            array_search(User::class, $persistOrder, true),
            'guest must be persisted BEFORE the conversion',
        );
    }

    public function testExistingGuestIsNotRePersisted(): void
    {
        // Гость с уже присвоенным id (существующая строка) НЕ персистится повторно.
        $quota = $this->createStub(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->method('check');
        $quota->method('charge');

        $persistOrder = [];
        $em           = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persistOrder): void {
            $persistOrder[] = $entity::class;
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 55);
            }
        });

        $manager = $this->buildManager($quota, $this->okS3Client(), $em);

        $guest = $this->guestUser();
        (new \ReflectionProperty(User::class, 'id'))->setValue($guest, 7);

        $manager->createConversion(new ConversionRequestDTO($guest, $this->makeUpload('jpg'), 'txt', false, false));

        self::assertNotContains(User::class, $persistOrder, 'persisted guest must NOT be re-persisted');
    }

    private function assertGuestGated(string $from, string $to): void
    {
        $quota = $this->createMock(QuotaService::class);
        // Ни один из этих методов не должен быть вызван — гейт срабатывает раньше.
        $quota->expects($this->never())->method('maxUploadBytes');
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $manager = $this->buildManager($quota, $s3Client, $this->createStub(EntityManagerInterface::class));

        $this->expectException(AuthRequiredException::class);
        $this->expectExceptionMessage('Для ai/video конвертаций нужен вход.');

        $manager->createConversion(new ConversionRequestDTO(
            $this->guestUser(),
            $this->makeUpload($from),
            $to,
            false,
            false, // privileged=false (гость)
        ));
    }

    private function guestUser(): User
    {
        return (new User())->setIsGuest(true)->setGuestId('deadbeefdeadbeefdeadbeefdeadbeef');
    }

    private function buildManager(QuotaService $quota, S3Client $s3Client, EntityManagerInterface $em): ConversionManager
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        return new ConversionManager(
            $this->newSeedRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
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

    private function makeUpload(string $ext): UploadedFile
    {
        $bytes = match (strtolower($ext)) {
            'jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            // MPEG audio frame-sync → audio/mpeg
            'mp3' => "\xFF\xFB\x90\x64\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00",
            // Minimal ISO-BMFF ftyp box → video/mp4
            'mp4'   => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
            default => "plain ascii payload\n",
        };

        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, "sample.{$ext}", null, null, true);
    }
}
