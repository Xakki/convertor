<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\DTO\ConversionRequestDTO;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * home-02-text-input: {@see ConversionRequestDTO::fromText()} materializes text
 * into an `UploadedFile`, so `ConversionManager::createConversion()` needs NO
 * code change to drive it — this test exercises the FULL manager pipeline
 * (registry routing, size gate, S3 store, dispatch, quota charge) with a
 * text-materialized DTO exactly the way the controller builds it, mirroring
 * {@see ConversionManagerOcrTest}'s doubles/pattern for the file-upload path.
 */
final class ConversionManagerTextInputTest extends TestCase
{
    public function testTextInputReachesS3AndDispatchesToDocumentStream(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->with($this->isInstanceOf(User::class), false);
        $quota->expects($this->once())->method('charge')->with($this->isInstanceOf(User::class), false);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 321);
            }
        });

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())
            ->method('putObject')
            ->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $captured = null;
        $bus      = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []) use (&$captured): Envelope {
                $captured = ['message' => $message, 'stamps' => $stamps];

                return new Envelope($message, $stamps);
            },
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

        $request = ConversionRequestDTO::fromText(new User(), "# Заголовок\n\nтекст", 'md', 'html', true);

        try {
            $conversion = $manager->createConversion($request);

            self::assertSame('markup', $conversion->getCategory()->value);
            self::assertFalse($conversion->isAi());
            self::assertSame('md', $conversion->getFromFormat());
            self::assertSame('html', $conversion->getToFormat());

            self::assertIsArray($captured);
            /** @var ConversionMessage $message */
            $message = $captured['message'];
            self::assertSame('md', $message->sourceFormat);
            self::assertSame('html', $message->targetFormat);

            $transports = [];
            foreach ($captured['stamps'] as $stamp) {
                if ($stamp instanceof TransportNamesStamp) {
                    $transports = $stamp->getTransportNames();
                }
            }
            // markup folds into the document stream at routing time (no dedicated markup worker).
            self::assertSame(['conv_document'], $transports);
        } finally {
            $request->cleanupTempFile();
        }
    }

    /**
     * Same size gate as the file-upload path (home-02 AC: no separate text
     * limit) — a text payload over the plan's maxUploadBytes is rejected 413
     * BEFORE quota/S3 side effects, identically to {@see ConversionManagerOcrTest::testOversizeRejectedFreePlanWith413()}.
     */
    public function testOversizeTextRejectedWith413BeforeQuotaOrS3(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(10); // 10 bytes — trivially exceeded
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('putObject');

        $manager = new ConversionManager(
            new ConversionRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
        );

        $request = ConversionRequestDTO::fromText(new User(), str_repeat('x', 1000), 'txt', 'md');

        try {
            $manager->createConversion($request);
            self::fail('Expected 413 HttpException');
        } catch (HttpException $e) {
            self::assertSame(413, $e->getStatusCode());
        } finally {
            $request->cleanupTempFile();
        }
    }
}
