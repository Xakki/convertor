<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Message\ConversionMessage;
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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * home-02-text-input: `POST /api/v1/convert` accepts EITHER `file` OR
 * `text`+`source_format` (mutually exclusive). Validation-only cases (both/
 * neither/missing source_format/unsupported source_format) return BEFORE any
 * S3/quota/dispatch side effect, so they run against the real wired
 * `ConversionManager` — no doubles needed. The two success cases (text-only,
 * file-only regression) swap `ConversionManager` for one built from the SAME
 * stub doubles as {@see \App\Tests\Unit\Service\Conversion\ConversionManagerOcrTest}
 * (real S3Storage over a stubbed S3Client, stubbed EM/Bus), so the request runs
 * through the ACTUAL controller→DTO→manager wiring without touching real S3/Redis.
 */
final class ConversionTextInputControllerTest extends WebTestCase
{
    use SeedsConversionRegistry;


    public function testFileAndTextTogetherReturns400(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/convert',
            ['text' => 'hello', 'source_format' => 'txt', 'to_format' => 'md'],
            ['file' => $this->uploadedTxt('sample.txt', 'hello')],
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNeitherFileNorTextReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/convert', ['to_format' => 'md']);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testEmptyTextIsTreatedAsNeitherAndReturns400(): void
    {
        // Empty text is indistinguishable from "no text" per the AC — same 400
        // branch as testNeitherFileNorTextReturns400(), not a distinct error.
        $client = static::createClient();

        $client->request('POST', '/api/v1/convert', [
            'text'          => '',
            'source_format' => 'txt',
            'to_format'     => 'md',
        ]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTextWithoutSourceFormatReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/convert', ['text' => 'hello', 'to_format' => 'md']);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testBinarySourceFormatInTextModeReturns422(): void
    {
        // docx→pdf is a VALID upload pair (registry isSupported), but docx is a
        // binary container — pasted text claiming source_format=docx must be
        // rejected with 422 (distinct from the general 400 unsupported-pair path).
        $client = static::createClient();

        $client->request('POST', '/api/v1/convert', [
            'text'          => 'not really a docx',
            'source_format' => 'docx',
            'to_format'     => 'pdf',
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUnknownSourceFormatInTextModeReturns422(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/convert', [
            'text'          => 'hello',
            'source_format' => 'zzz',
            'to_format'     => 'md',
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testTextOnlySubmitReachesManagerMaterializedAndDispatchesToDocumentStream(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request('POST', '/api/v1/convert', [
            'text'          => "# Заголовок\n\nПривет мир",
            'source_format' => 'md',
            'to_format'     => 'html',
        ]);

        $response = $client->getResponse();
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(999, $data['conversion_id']);
        self::assertSame('pending', $data['status']);

        self::assertNotNull($captured['message']);
        /** @var ConversionMessage $message */
        $message = $captured['message'];
        self::assertSame('md', $message->sourceFormat);
        self::assertSame('html', $message->targetFormat);
    }

    public function testFileOnlySubmitStillWorksNoRegression(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'png'],
            ['file'      => $this->uploadedJpg('photo.jpg')],
        );

        $response = $client->getResponse();
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());

        self::assertNotNull($captured['message']);
        /** @var ConversionMessage $message */
        $message = $captured['message'];
        self::assertSame('jpg', $message->sourceFormat);
        self::assertSame('png', $message->targetFormat);
    }

    /**
     * @param array{message: object|null} $captured filled by reference with the
     *                                                dispatched ConversionMessage
     */
    private function stubbedManager(array &$captured): ConversionManager
    {
        $quota = $this->createStub(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 999);
            }
        });

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []) use (&$captured): Envelope {
                $captured['message'] = $message;

                return new Envelope($message, $stamps);
            },
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

    private function uploadedTxt(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function uploadedJpg(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv_test_');
        self::assertNotFalse($path);
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        return new UploadedFile($path, $name, null, null, true);
    }
}
