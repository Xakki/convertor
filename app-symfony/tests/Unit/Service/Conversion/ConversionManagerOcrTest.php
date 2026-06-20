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
        $quota->expects($this->once())
            ->method('checkAndDecrement')
            ->with($this->isInstanceOf(User::class), $expectAi);

        $em = $this->createStub(EntityManagerInterface::class);

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

        $conversion = $manager->createConversion($this->makeUser(), $file, $to, $ocr);
        // No DB → the auto-generated id is never assigned; dispatch reads it.
        $idProp = new \ReflectionProperty(Conversion::class, 'id');
        $idProp->setValue($conversion, 42);
        $manager->dispatch($conversion);

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
}
