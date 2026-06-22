<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\Conversion;
use App\Entity\User;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class ConversionManagerOcrTest extends TestCase
{
    /**
     * @param array{ocr?: bool, expectAi: bool} $opts
     *
     * @return array{message: ConversionMessage, transports: list<string>, conversion: Conversion}
     */
    private function runConversion(string $from, string $to, array $opts): array
    {
        $ocr      = $opts['ocr'] ?? false;
        $expectAi = $opts['expectAi'];

        $registry = new ConversionRegistry();

        $quota = $this->createMock(QuotaService::class);
        // The free-vs-AI quota decision is the assertion: OCR must charge free.
        // Both the up-front check and the post-submit charge carry the same isAi.
        $quota->expects($this->once())
            ->method('check')
            ->with($this->isInstanceOf(User::class), $expectAi);
        $quota->expects($this->once())
            ->method('charge')
            ->with($this->isInstanceOf(User::class), $expectAi);

        // createConversion now enqueues internally; with no DB the auto-generated
        // id is never assigned, so simulate persist() stamping the Conversion id
        // (dispatch reads it while building the message).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 42);
            }
        });

        // S3Storage is final → build a real one over a stubbed S3Client whose
        // putObject returns a resolvable output (Result::resolve is final, so use
        // the async-aws ResultMockFactory).
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));
        $s3 = new S3Storage($s3Client, 'convertor');

        $captured = null;
        $bus      = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []) use (&$captured): Envelope {
                $captured = ['message' => $message, 'stamps' => $stamps];

                return new Envelope($message, $stamps);
            },
        );

        $manager = new ConversionManager(
            $registry,
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory("redis://localhost")),
            $s3,
        );

        $file = $this->makeUpload($from);

        // createConversion enqueues internally (dispatch + charge happen inside).
        $conversion = $manager->createConversion($this->makeUser(), $file, $to, $ocr);

        self::assertIsArray($captured);
        /** @var ConversionMessage $message */
        $message = $captured['message'];

        $transports = [];
        foreach ($captured['stamps'] as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                $transports = $stamp->getTransportNames();
            }
        }

        return ['message' => $message, 'transports' => $transports, 'conversion' => $conversion];
    }

    private function makeUser(): User
    {
        return new User();
    }

    private function makeUpload(string $ext): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ocr');
        self::assertNotFalse($path);
        file_put_contents($path, 'x');

        return new UploadedFile($path, "sample.{$ext}", null, null, true);
    }

    public function testJpgToTxtRoutesToImageNonAi(): void
    {
        $r = $this->runConversion('jpg', 'txt', ['expectAi' => false]);

        self::assertSame(['conv_image'], $r['transports']);
        self::assertFalse($r['message']->isAi);
        self::assertSame('image', $r['message']->category);
    }

    public function testPdfToTxtWithoutFlagRoutesToDocument(): void
    {
        $r = $this->runConversion('pdf', 'txt', ['expectAi' => false]);

        self::assertSame(['conv_document'], $r['transports']);
        self::assertFalse($r['message']->isAi);
        self::assertSame('document', $r['message']->category);
    }

    public function testPdfToTxtWithOcrFlagRoutesToImageFreeNonAi(): void
    {
        $r = $this->runConversion('pdf', 'txt', ['ocr' => true, 'expectAi' => false]);

        self::assertSame(['conv_image'], $r['transports']);
        self::assertFalse($r['message']->isAi);
        self::assertSame('image', $r['message']->category, 'OCR message must carry category=image');
        self::assertTrue($r['conversion']->isOcr());
        self::assertNull($r['message']->subType, 'subType stays null for OCR');
    }

    public function testOcrUploadNoLongerRejected(): void
    {
        // jpg→md OCR used to be unreachable (dead jpg_ocr keys); now accepted.
        $r = $this->runConversion('jpg', 'md', ['ocr' => true, 'expectAi' => false]);

        self::assertSame(['conv_image'], $r['transports']);
    }

    public function testUnsupportedOcrPairRejected(): void
    {
        $registry = new ConversionRegistry();
        $manager  = new ConversionManager(
            $registry,
            $this->createStub(ConversionRepository::class),
            $this->createStub(QuotaService::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new ConversionStatusReader(new RedisConnectionFactory("redis://localhost")),
            new S3Storage($this->createStub(S3Client::class), 'convertor'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $manager->createConversion($this->makeUser(), $this->makeUpload('gif'), 'txt', true);
    }

    /**
     * Submit-path quota leak guard: if the enqueue (dispatch) fails, quota must
     * NOT be charged — no worker job exists, so the worker-failure refund will
     * never run. check() still happens up-front; charge() must not.
     */
    public function testDispatchFailureLeavesQuotaUncharged(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), false);
        $quota->expects($this->never())->method('charge');

        // persist() stamps the id so dispatch() reaches bus->dispatch (which throws)
        // instead of dying earlier on an uninitialized id.
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 7);
            }
        });

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $manager = new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
        );

        $this->expectException(\RuntimeException::class);
        $manager->createConversion($this->makeUser(), $this->makeUpload('jpg'), 'txt', false);
    }

    /**
     * Same guarantee at the earliest post-check failure point: an S3 upload error
     * must leave the quota uncharged.
     */
    public function testS3UploadFailureLeavesQuotaUncharged(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willThrowException(new \RuntimeException('S3 down'));

        $manager = new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
        );

        $this->expectException(\RuntimeException::class);
        $manager->createConversion($this->makeUser(), $this->makeUpload('jpg'), 'txt', false);
    }

    /**
     * Archive ordering guarantee: an archive conversion is rejected with a 422
     * BEFORE quota is touched — neither check() nor charge() runs.
     */
    public function testArchiveRejectedBeforeQuotaCheck(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $manager = new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($this->createStub(S3Client::class), 'convertor'),
        );

        $this->expectException(UnprocessableEntityHttpException::class);
        $manager->createConversion($this->makeUser(), $this->makeUpload('zip'), 'tar.gz', false);
    }
}
