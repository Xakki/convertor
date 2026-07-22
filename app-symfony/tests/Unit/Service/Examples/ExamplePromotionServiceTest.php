<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Examples;

use App\Entity\Conversion;
use App\Entity\Example;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Repository\ExampleRepository;
use App\Service\Examples\ExamplePromotionService;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Юнит-тесты промо/удаления примера (карточка admin-managed-examples).
 * `S3Storage` — реальный класс поверх `S3Client` на `MockHttpClient` (S3Storage
 * финальный, не мокается — тот же паттерн, что и в
 * ConversionHistoryFileControllerTest::overrideS3). `ExampleRepository` — стаб
 * (PHPUnit не вызывает конструктор), т.к. сервис работает только через её
 * публичный интерфейс (save/remove/findOneByResultKey/nextSortOrder).
 */
final class ExamplePromotionServiceTest extends TestCase
{
    public function testPromoteBuildsDeterministicKeysAndCopiesBothObjects(): void
    {
        $requests = [];
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['headers'] ?? []];

            // HEAD после copy (objectStat) — заголовок Content-Length.
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '42']]);
            }

            return new MockResponse('', ['http_code' => 200]);
        });
        $s3Storage = $this->s3Storage($http);

        $examples = $this->createMock(ExampleRepository::class);
        $examples->method('findOneByResultKey')->willReturn(null);
        $examples->method('nextSortOrder')->willReturn(3);
        $examples->expects(self::once())->method('save')->with(self::isInstanceOf(Example::class), true);

        $service = new ExamplePromotionService($s3Storage, $examples);

        $conversion = $this->buildConversion();
        $example    = $service->promote($conversion);

        self::assertSame('document', $example->getCategory());
        self::assertSame('txt', $example->getFromFormat());
        self::assertSame('pdf', $example->getToFormat());
        self::assertSame('txt-to-pdf.pdf', $example->getFilename());
        self::assertSame('examples/document/txt-to-pdf.pdf', $example->getResultKey());
        self::assertSame('txt-to-pdf-source.txt', $example->getSourceFilename());
        self::assertSame('examples/document/txt-to-pdf-source.txt', $example->getSourceKey());
        self::assertSame(42, $example->getSize());
        self::assertSame(3, $example->getSortOrder());
        self::assertSame($conversion, $example->getConversion());
        self::assertFalse($example->isPreviewable(), 'application/pdf не текстовый — previewable должен быть false');
    }

    public function testPromoteAppendsSuffixOnSlugCollision(): void
    {
        $http      = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $s3Storage = $this->s3Storage($http);

        $examples = $this->createMock(ExampleRepository::class);
        // Первый ключ (без суффикса) уже занят → сервис должен взять "-2".
        $examples->method('findOneByResultKey')->willReturnCallback(
            static fn (string $key): ?Example => $key === 'examples/document/txt-to-pdf.pdf' ? new Example() : null,
        );
        $examples->method('nextSortOrder')->willReturn(0);
        $examples->expects(self::once())->method('save');

        $service = new ExamplePromotionService($s3Storage, $examples);
        $example = $service->promote($this->buildConversion());

        self::assertSame('txt-to-pdf-2.pdf', $example->getFilename());
        self::assertSame('examples/document/txt-to-pdf-2.pdf', $example->getResultKey());
    }

    public function testRemoveDeletesBothS3ObjectsAndRow(): void
    {
        $deletedKeys = [];
        $http        = new MockHttpClient(function (string $method, string $url) use (&$deletedKeys): MockResponse {
            if ($method === 'DELETE') {
                $deletedKeys[] = $url;
            }

            return new MockResponse('', ['http_code' => 204]);
        });
        $s3Storage = $this->s3Storage($http);

        $examples = $this->createMock(ExampleRepository::class);
        $examples->expects(self::once())->method('remove')->with(self::isInstanceOf(Example::class), true);

        $example = (new Example())
            ->setResultKey('examples/document/txt-to-pdf.pdf')
            ->setSourceKey('examples/document/txt-to-pdf-source.txt');

        (new ExamplePromotionService($s3Storage, $examples))->remove($example);

        self::assertCount(2, $deletedKeys);
        self::assertStringContainsString('txt-to-pdf.pdf', $deletedKeys[0]);
        self::assertStringContainsString('txt-to-pdf-source.txt', $deletedKeys[1]);
    }

    private function s3Storage(MockHttpClient $http): S3Storage
    {
        $client = new S3Client([
            'endpoint'          => 'http://localhost',
            'accessKeyId'       => 'k',
            'accessKeySecret'   => 's',
            'region'            => 'us-east-1',
            'pathStyleEndpoint' => true,
        ], null, $http);

        return new S3Storage($client, 'test_');
    }

    private function buildConversion(): Conversion
    {
        $user = new User();

        $input = (new FileStorage())
            ->setOriginalName('doc.txt')
            ->setStoragePath('inputs/2026/07-21/abc.txt')
            ->setMimeType('text/plain')
            ->setSizeBytes(11);

        $output = (new FileStorage())
            ->setOriginalName('doc.pdf')
            ->setStoragePath('results/2026/07-21/abc.pdf')
            ->setMimeType('application/pdf')
            ->setSizeBytes(99);

        return (new Conversion())
            ->setUser($user)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('txt')
            ->setToFormat('pdf')
            ->setCategory(FileCategory::Document);
    }
}
