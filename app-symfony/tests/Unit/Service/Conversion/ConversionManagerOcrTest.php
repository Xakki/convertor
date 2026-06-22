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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
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
        // Generous upload ceiling so the new size gate is a no-op on these paths.
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
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

    /**
     * Build an UploadedFile whose bytes sniff (finfo) to the right MIME family
     * for the extension, so the content-type gate sees a matching type. Falls
     * back to ASCII text (→ text/plain) for unknown extensions.
     */
    private function makeUpload(string $ext): UploadedFile
    {
        return $this->makeRawUpload($ext, self::magicBytes($ext));
    }

    /** Build an UploadedFile with arbitrary raw bytes (to drive the MIME gate). */
    private function makeRawUpload(string $ext, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, "sample.{$ext}", null, null, true);
    }

    /**
     * Minimal valid magic bytes per format. Verified via finfo inside the
     * container: jpg→image/jpeg, png→image/png, pdf→application/pdf,
     * default ASCII→text/plain. The gif/zip entries only feed early-reject
     * paths (unsupported-pair / archive) where the MIME is never sniffed.
     */
    private static function magicBytes(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'png'         => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR",
            'pdf'         => "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n",
            'gif'         => "GIF89a",
            'zip'         => "PK\x03\x04\x14\x00\x00\x00\x00\x00",
            default       => "plain ascii text payload for conversion\n",
        };
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
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
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
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
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

    /**
     * Content-type gate: a .jpg whose real bytes are a PHP script sniffs as
     * text/x-php ∉ image/* → 415, BEFORE any quota check/charge or S3 upload.
     */
    public function testMimeMismatchRejectedWith415AndNoSideEffects(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024); // size gate passes
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $manager = $this->buildManager($quota, $s3Client);

        $file = $this->makeRawUpload('jpg', "<?php echo 'pwn'; ?>\n");

        try {
            $manager->createConversion($this->makeUser(), $file, 'txt', false);
            self::fail('Expected UnsupportedMediaTypeHttpException');
        } catch (UnsupportedMediaTypeHttpException $e) {
            self::assertSame(415, $e->getStatusCode());
        }
    }

    /**
     * Size gate, free tier (50 MB): a 60 MB upload is rejected with 413 before
     * quota or S3 are touched.
     */
    public function testOversizeRejectedFreePlanWith413(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(50 * 1024 * 1024); // free tier
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $manager = $this->buildManager($quota, $s3Client);

        $file = $this->makeSizedUpload('jpg', 60 * 1024 * 1024); // > 50 MB

        try {
            $manager->createConversion($this->makeUser(), $file, 'txt', false);
            self::fail('Expected 413 HttpException');
        } catch (HttpException $e) {
            self::assertSame(413, $e->getStatusCode());
        }
    }

    /**
     * Size gate, paid tier (500 MB): a 600 MB upload is rejected with 413 before
     * quota or S3 are touched.
     */
    public function testOversizeRejectedPaidPlanWith413(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024); // paid tier
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $manager = $this->buildManager($quota, $s3Client);

        $file = $this->makeSizedUpload('jpg', 600 * 1024 * 1024); // > 500 MB

        try {
            $manager->createConversion($this->makeUser(), $file, 'txt', false);
            self::fail('Expected 413 HttpException');
        } catch (HttpException $e) {
            self::assertSame(413, $e->getStatusCode());
        }
    }

    /**
     * Happy path, S3 side asserted directly: a valid in-family upload within the
     * size limit must reach S3 (putObject called exactly once) and charge quota.
     */
    public function testValidUploadReachesS3AndCharges(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), false);
        $quota->expects($this->once())->method('charge')->with($this->isInstanceOf(User::class), false);

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())
            ->method('putObject')
            ->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 101);
            }
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        $manager = new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
        );

        $conversion = $manager->createConversion($this->makeUser(), $this->makeUpload('jpg'), 'txt', false);

        self::assertSame('image', $conversion->getCategory()->value);
    }

    private function buildManager(QuotaService $quota, S3Client $s3Client): ConversionManager
    {
        return new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
        );
    }

    /**
     * UploadedFile with valid leading magic bytes but a logical size of
     * $sizeBytes (sparse via ftruncate — no real disk cost), so finfo still
     * sniffs the right family while getSize() reports the large size.
     */
    private function makeSizedUpload(string $ext, int $sizeBytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);

        $fh = fopen($path, 'wb');
        self::assertNotFalse($fh);
        fwrite($fh, self::magicBytes($ext));
        ftruncate($fh, $sizeBytes);
        fclose($fh);

        return new UploadedFile($path, "sample.{$ext}", null, null, true);
    }
}
