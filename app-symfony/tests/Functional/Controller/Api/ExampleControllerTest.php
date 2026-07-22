<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Example;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Функциональные тесты публичной витрины примеров (карточка admin-managed-examples):
 * GET /api/v1/examples (список из БД) и GET /api/v1/examples/file/{category}/{name}
 * (inline-отдача результата). Источник данных — таблица {@see Example}, НЕ
 * захардкоженный ExampleCatalog — контракт JSON-полей/URL-шаблонов не изменился
 * (см. класс-докблок ExampleController). Требует тест-БД convertor-test.
 */
final class ExampleControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $entity) {
                $managed = $em->contains($entity) ? $entity : $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
        $this->toRemove = [];
    }

    public function testListReturnsOnlyRowsWhoseResultExistsInS3(): void
    {
        $client  = static::createClient();
        $present = $this->persistExample('document', 'txt', 'pdf', 'txt-to-pdf.pdf', sortOrder: 0);
        $missing = $this->persistExample('image', 'png', 'jpg', 'png-to-jpg.jpg', sortOrder: 1);

        // HEAD 200 для txt-to-pdf.pdf, 404 (NoSuchKey) для png-to-jpg.jpg —
        // objectStat() фильтрует несуществующие объекты (пропали мимо приложения).
        $http = new MockHttpClient(static function (string $method, string $url) use ($present): MockResponse {
            if (str_contains($url, $present->getResultKey())) {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '18558', 'content-type' => 'application/pdf']]);
            }

            return new MockResponse('<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>', ['http_code' => 404]);
        });
        static::getContainer()->set(S3Storage::class, new S3Storage($this->s3Client($http), 'test_'));

        $client->request('GET', '/api/v1/examples');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('examples', $data);
        self::assertCount(1, $data['examples'], 'пример без объекта в S3 должен быть отфильтрован');

        $item = $data['examples'][0];
        foreach (['category', 'from', 'to', 'filename', 'mime', 'size', 'previewable', 'url', 'source_format', 'source_mime', 'source_url'] as $key) {
            self::assertArrayHasKey($key, $item, $key);
        }
        self::assertSame('document', $item['category']);
        self::assertSame('txt', $item['from']);
        self::assertSame('pdf', $item['to']);
        self::assertSame('txt-to-pdf.pdf', $item['filename']);
        self::assertSame(18558, $item['size']);
        self::assertSame('/api/v1/examples/file/document/txt-to-pdf.pdf', $item['url']);
        self::assertSame('/api/v1/examples/source/document/document.txt', $item['source_url']);

        unset($missing); // используется только для сида
    }

    public function testListOrdersBySortOrder(): void
    {
        $client = static::createClient();
        $this->persistExample('video', 'mp4', 'webm', 'mp4-to-webm.webm', sortOrder: 5);
        $this->persistExample('document', 'txt', 'pdf', 'txt-to-pdf.pdf', sortOrder: 0);
        $this->persistExample('image', 'png', 'jpg', 'png-to-jpg.jpg', sortOrder: 2);

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '10']]));
        static::getContainer()->set(S3Storage::class, new S3Storage($this->s3Client($http), 'test_'));

        $client->request('GET', '/api/v1/examples');
        self::assertResponseIsSuccessful();

        $data       = json_decode((string) $client->getResponse()->getContent(), true);
        $categories = array_column($data['examples'], 'category');
        self::assertSame(['document', 'image', 'video'], $categories);
    }

    public function testServeReturns200ForKnownExample(): void
    {
        $client = static::createClient();
        $this->persistExample('document', 'txt', 'pdf', 'txt-to-pdf.pdf');

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('%PDF-fake%', ['http_code' => 200]));
        static::getContainer()->set(S3Storage::class, new S3Storage($this->s3Client($http), 'test_'));

        $client->request('GET', '/api/v1/examples/file/document/txt-to-pdf.pdf');
        self::assertResponseIsSuccessful();
        // Content-Type отдаётся из Example.mime (полe entity), не из S3-заголовков.
        self::assertSame('application/octet-stream', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testServeReturns404ForUnknownFilename(): void
    {
        $client = static::createClient();
        $this->persistExample('document', 'txt', 'pdf', 'txt-to-pdf.pdf');

        $client->request('GET', '/api/v1/examples/file/document/not-a-real-example.pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testServeReturns404WhenS3ObjectMissing(): void
    {
        $client = static::createClient();
        $this->persistExample('document', 'txt', 'pdf', 'txt-to-pdf.pdf');

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>',
            ['http_code' => 404],
        ));
        static::getContainer()->set(S3Storage::class, new S3Storage($this->s3Client($http), 'test_'));

        $client->request('GET', '/api/v1/examples/file/document/txt-to-pdf.pdf');
        self::assertResponseStatusCodeSame(404);
    }

    private function persistExample(string $category, string $from, string $to, string $filename, int $sortOrder = 0): Example
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $example = (new Example())
            ->setCategory($category)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setFilename($filename)
            ->setMime('application/octet-stream')
            ->setSize(1)
            ->setPreviewable(false)
            ->setSourceFormat($from)
            ->setSourceMime('application/octet-stream')
            ->setSourceFilename($category . '.' . $from)
            ->setResultKey('examples/' . $category . '/' . $filename)
            ->setSourceKey('examples/' . $category . '/' . $from . '-to-' . $to . '-source.' . $from)
            ->setSortOrder($sortOrder);

        $em->persist($example);
        $em->flush();
        $this->toRemove[] = $example;

        return $example;
    }

    private function s3Client(MockHttpClient $http): S3Client
    {
        return new S3Client([
            'endpoint'          => 'http://localhost',
            'accessKeyId'       => 'k',
            'accessKeySecret'   => 's',
            'region'            => 'us-east-1',
            'pathStyleEndpoint' => true,
        ], null, $http);
    }
}
