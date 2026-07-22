<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Conversion;
use App\Entity\Example;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Функциональные тесты admin-промо/удаления примеров (карточка
 * admin-managed-examples, подход A). Граница — ROLE_ADMIN на JWT-firewall
 * (Option B), как у остальных admin-API (см. {@see UserControllerTest}/
 * {@see DlqControllerTest} — тот же паттерн: реальный `S3Storage` поверх
 * мокнутого `S3Client`, `container->set()` подмена). Требует тест-БД
 * convertor-test.
 */
final class ExampleAdminControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $entity) {
                // Тест (remove()-эндпоинт) мог сам удалить сущность в рамках
                // запроса — Doctrine после успешного DELETE сбрасывает typed
                // `$id` в неинициализированное состояние на ТОМ ЖЕ объекте
                // (shared EM у тестового контейнера). Пропускаем такие —
                // удалять уже нечего.
                $rp = new \ReflectionProperty($entity, 'id');
                if (! $rp->isInitialized($entity)) {
                    continue;
                }
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

    // -------------------------------------------------------------------------
    // ROLE_ADMIN gate
    // -------------------------------------------------------------------------

    public function testEndpointsForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $cases = [
            ['GET', '/api/v1/admin/examples'],
            ['GET', '/api/v1/admin/examples/candidates'],
            ['POST', '/api/v1/admin/examples/1/promote'],
            ['POST', '/api/v1/admin/examples/1/remove'],
        ];

        foreach ($cases as [$method, $url]) {
            $client->request($method, $url, server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
            self::assertSame(403, $client->getResponse()->getStatusCode(), "{$method} {$url}");
        }
    }

    public function testListUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/examples');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // list / candidates
    // -------------------------------------------------------------------------

    public function testListReturnsExistingExamples(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $example = $this->persistExample();

        $client->request('GET', '/api/v1/admin/examples', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        $ids = array_column($data['items'], 'id');
        self::assertContains($example->getId(), $ids);
    }

    public function testCandidatesOnlyListsCompletedConversionsWithOutput(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $owner  = $this->persistUser(false);

        $completed = $this->persistConversion($owner, ConversionStatus::Completed, withOutput: true);
        $this->persistConversion($owner, ConversionStatus::Pending, withOutput: false);
        $this->persistConversion($owner, ConversionStatus::Failed, withOutput: false);

        $client->request('GET', '/api/v1/admin/examples/candidates', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $ids  = array_column($data['items'], 'id');
        self::assertContains($completed->getId(), $ids);
        self::assertCount(1, $data['items']);
    }

    // -------------------------------------------------------------------------
    // promote
    // -------------------------------------------------------------------------

    public function testPromoteReturns404ForMissingConversion(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('POST', '/api/v1/admin/examples/999999999/promote', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testPromoteReturns409WhenNotCompleted(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $owner  = $this->persistUser(false);

        $conversion = $this->persistConversion($owner, ConversionStatus::Pending, withOutput: false);

        $client->request('POST', "/api/v1/admin/examples/{$conversion->getId()}/promote", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('not_completed', $body['error']);
    }

    public function testPromoteReturns409WhenFilesGone(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $owner  = $this->persistUser(false);

        $conversion = $this->persistConversion($owner, ConversionStatus::Completed, withOutput: true);

        $this->overrideS3(static fn (string $method, string $url): MockResponse => $method === 'HEAD'
            ? new MockResponse('<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>', ['http_code' => 404])
            : new MockResponse('', ['http_code' => 200]));

        $client->request('POST', "/api/v1/admin/examples/{$conversion->getId()}/promote", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('files_gone', $body['error']);
    }

    /**
     * Happy path: gate passes (both S3 objects exist) → promotion service copies
     * both objects and creates the Example row. Verifies the row via DB reload
     * (S3 copy calls themselves are exercised — and would throw on failure —
     * inside ExamplePromotionService, already covered at the unit level).
     */
    public function testPromoteCreatesExampleRowOnSuccess(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));
        $owner  = $this->persistUser(false);

        $conversion = $this->persistConversion($owner, ConversionStatus::Completed, withOutput: true, from: 'txt', to: 'pdf', category: FileCategory::Document);

        $this->overrideS3(static fn (string $method, string $url): MockResponse => $method === 'HEAD'
            ? new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '55']])
            : new MockResponse('', ['http_code' => 200]));

        $client->request('POST', "/api/v1/admin/examples/{$conversion->getId()}/promote", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('document', $body['category']);
        self::assertSame('txt', $body['from']);
        self::assertSame('pdf', $body['to']);
        self::assertSame('txt-to-pdf.pdf', $body['filename']);
        self::assertSame($conversion->getId(), $body['conversionId']);

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $example = $em->getRepository(Example::class)->find($body['id']);
        self::assertNotNull($example);
        self::assertSame('examples/document/txt-to-pdf.pdf', $example->getResultKey());
        $this->toRemove[] = $example;
    }

    // -------------------------------------------------------------------------
    // remove
    // -------------------------------------------------------------------------

    public function testRemoveReturns404ForMissingExample(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('POST', '/api/v1/admin/examples/999999999/remove', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRemoveDeletesExampleRow(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $example = $this->persistExample();
        $id      = $example->getId();

        $this->overrideS3(static fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 204]));

        $client->request('POST', "/api/v1/admin/examples/{$id}/remove", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Example::class)->find($id));
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private function overrideS3(callable $responder): void
    {
        $http = new MockHttpClient($responder);
        $s3   = new S3Client([
            'endpoint'          => 'http://localhost',
            'accessKeyId'       => 'k',
            'accessKeySecret'   => 's',
            'region'            => 'us-east-1',
            'pathStyleEndpoint' => true,
        ], null, $http);

        static::getContainer()->set(S3Storage::class, new S3Storage($s3, 'test_'));
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
            ->setSourceFilename('txt-to-pdf-source.txt')
            ->setResultKey('examples/document/txt-to-pdf.pdf')
            ->setSourceKey('examples/document/txt-to-pdf-source.txt');

        $em->persist($example);
        $em->flush();
        $this->toRemove[] = $example;

        return $example;
    }

    private function persistConversion(
        User $owner,
        ConversionStatus $status,
        bool $withOutput,
        string $from = 'mp3',
        string $to = 'txt',
        FileCategory $category = FileCategory::Audio,
    ): Conversion {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $inputFile = (new FileStorage())
            ->setOriginalName('input.' . $from)
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(100);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $outputFile = null;
        if ($withOutput) {
            $outputFile = (new FileStorage())
                ->setOriginalName('output.' . $to)
                ->setStoragePath('results/test/' . bin2hex(random_bytes(8)) . '.' . $to)
                ->setMimeType('application/octet-stream')
                ->setSizeBytes(200);
            $em->persist($outputFile);
            $this->toRemove[] = $outputFile;
        }

        $conversion = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setOutputFile($outputFile)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory($category)
            ->setStatus($status)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conversion);
        $em->flush();
        $this->toRemove[] = $conversion;

        return $conversion;
    }

    private function persistUser(bool $admin): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setIsAdmin($admin);
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
