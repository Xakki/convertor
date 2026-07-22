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
 * Функциональные тесты публичного эндпоинта home-10:
 * GET /api/v1/examples/source/{category}/{name} — inline-отдача исходного
 * sample-файла ИЗ S3 (карточка admin-managed-examples: раньше отдавался с
 * локального диска, теперь единый S3-код с admin-промо-примерами). Whitelist —
 * (category, sourceFilename) строки {@see Example} в БД, а не хардкод-каталог.
 *
 * `S3Storage` (класс final) подменяется реальным поверх S3Client на
 * MockHttpClient — тот же паттерн, что ConversionHistoryFileControllerTest.
 * Требует тест-БД convertor-test.
 */
final class ExampleSourceControllerTest extends WebTestCase
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

    public function testKnownExampleReturns200WithCorrectContentType(): void
    {
        $client = static::createClient();
        $this->persistExample();
        $this->overrideS3(200, 'plain text content');

        $client->request('GET', '/api/v1/examples/source/document/document.txt');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        // StreamedResponse (S3Storage::inlineResponse) не буферизует тело в
        // getContent() — та же ситуация, что и с прежним BinaryFileResponse
        // (обе не поддерживают getContent() в WebTestCase без send()), поэтому
        // содержимое здесь не проверяем — только статус/заголовки/whitelist.
    }

    public function testUnknownNameIsBlockedNotFound(): void
    {
        $client = static::createClient();
        $this->persistExample();

        $client->request('GET', '/api/v1/examples/source/document/not-in-db.txt');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownCategoryIsBlockedNotFound(): void
    {
        $client = static::createClient();
        $this->persistExample();

        $client->request('GET', '/api/v1/examples/source/bogus/document.txt');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCategorySampleMismatchIsBlockedNotFound(): void
    {
        $client = static::createClient();
        $this->persistExample();

        // document.txt существует в БД, но не под категорией image — whitelist
        // сверяет ОБА поля (category, sourceFilename), не только имя файла.
        $client->request('GET', '/api/v1/examples/source/image/document.txt');

        self::assertResponseStatusCodeSame(404);
    }

    public function testS3ObjectGoneReturns404(): void
    {
        $client = static::createClient();
        $this->persistExample();
        $this->overrideS3(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>');

        $client->request('GET', '/api/v1/examples/source/document/document.txt');

        self::assertResponseStatusCodeSame(404);
    }

    private function persistExample(): Example
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $example = (new Example())
            ->setCategory('document')
            ->setFromFormat('txt')
            ->setToFormat('pdf')
            ->setFilename('txt-to-pdf.pdf')
            ->setMime('application/pdf')
            ->setSize(100)
            ->setPreviewable(false)
            ->setSourceFormat('txt')
            ->setSourceMime('text/plain')
            ->setSourceFilename('document.txt')
            ->setResultKey('examples/document/txt-to-pdf.pdf')
            ->setSourceKey('examples/document/txt-to-pdf-source.txt');

        $em->persist($example);
        $em->flush();
        $this->toRemove[] = $example;

        return $example;
    }

    private function overrideS3(int $httpCode, string $body): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => $httpCode]));
        $s3   = new S3Client([
            'endpoint'          => 'http://localhost',
            'accessKeyId'       => 'k',
            'accessKeySecret'   => 's',
            'region'            => 'us-east-1',
            'pathStyleEndpoint' => true,
        ], null, $http);

        static::getContainer()->set(S3Storage::class, new S3Storage($s3, 'test_'));
    }
}
