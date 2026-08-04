<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Conversion;
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
 * Функциональные тесты admin-эндпоинтов лога конвертаций (эпик admin-panel,
 * подзадача logs; CNV-61 добавил file-превью):
 *  - GET /admin/conversions — список + пагинация + graylogUrl + per-side мета
 *    (inputMime/outputMime/inputName/outputName/inputSize/outputSize);
 *  - GET /admin/conversions/{id}/file?side=source|result — сырые байты файла
 *    для admin-превью (attachment, без аудит-лога — решение продукта).
 * Граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ 403, unauth 401.
 * Требуют тест-БД convertor-test.
 */
final class ConversionLogControllerTest extends WebTestCase
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

    public function testForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(false));

        $client->request('GET', '/api/v1/admin/conversions', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/conversions');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testReturnsPaginationMetadataAndGraylogUrlForAdmin(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $client->request('GET', '/api/v1/admin/conversions', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (['items', 'page', 'pageSize', 'total', 'pages', 'graylogUrl'] as $key) {
            self::assertArrayHasKey($key, $data, $key);
        }
        self::assertIsArray($data['items']);
        self::assertSame(1, $data['page']);
    }

    public function testErrorsOnlyFilterReturnsFailedRowWithMessage(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null);
        $failed = $this->seedConversion($owner, 'mp3', 'txt', ConversionStatus::Failed, 'worker crashed');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(
            'GET',
            '/api/v1/admin/conversions?status=failed&user=' . $owner->getId(),
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['total'], 'только failed-строка владельца');
        $row = $data['items'][0];
        self::assertSame($failed->getId(), $row['id']);
        self::assertSame('failed', $row['status']);
        self::assertSame('worker crashed', $row['errorMessage']);
        self::assertSame('mp3', $row['fromFormat']);
        self::assertSame('txt', $row['toFormat']);
        self::assertArrayHasKey('user', $row);
        self::assertSame($owner->getId(), $row['user']['id']);
    }

    public function testListItemsContainInputOutputMeta(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner         = $this->persistUser(false);
        $withOutput    = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        $withoutOutput = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Pending, null, withOutput: false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(
            'GET',
            '/api/v1/admin/conversions?user=' . $owner->getId(),
            server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        );
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $byId = [];
        foreach ($data['items'] as $row) {
            $byId[$row['id']] = $row;
        }

        $withRow = $byId[$withOutput->getId()];
        foreach (['inputMime', 'outputMime', 'inputName', 'outputName', 'inputSize', 'outputSize'] as $key) {
            self::assertArrayHasKey($key, $withRow, $key);
        }
        self::assertSame('application/octet-stream', $withRow['inputMime']);
        self::assertSame('application/octet-stream', $withRow['outputMime']);
        self::assertSame('in.jpg', $withRow['inputName']);
        self::assertSame('out.png', $withRow['outputName']);
        self::assertSame(123, $withRow['inputSize']);
        self::assertSame(456, $withRow['outputSize']);

        $withoutRow = $byId[$withoutOutput->getId()];
        self::assertSame('application/octet-stream', $withoutRow['inputMime']);
        self::assertNull($withoutRow['outputMime']);
        self::assertNull($withoutRow['outputName']);
        self::assertNull($withoutRow['outputSize']);
    }

    // -------------------------------------------------------------------------
    // GET /admin/conversions/{id}/file
    // -------------------------------------------------------------------------

    public function testFileReturns200ForSourceSide(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $conv  = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->overrideS3(206, 'raw-bytes');
        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=source", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testFileReturns200ForResultSide(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $conv  = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->overrideS3(206, 'raw-bytes');
        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=result", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function testFileForbiddenForRegularUser(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser(false);
        $token  = $this->jwtFor($owner);

        $conv = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=source", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testFileUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser(false);

        $conv = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=source");
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFileReturns410WhenObjectGoneInS3(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $conv  = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // S3 отвечает 404 NoSuchKey → контроллер обязан вернуть 410, не 500
        // (тот же паттерн, что и /convert/{id}/preview?side=source 410-тест).
        $this->overrideS3(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>');

        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=source", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        self::assertSame(410, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('gone', $data['error']);
    }

    public function testFileReturns404WhenSideFileAbsent(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        // Без output-файла (pending) — side=result должен быть 404.
        $conv = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Pending, null, withOutput: false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=result", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testFileReturns400ForBadSide(): void
    {
        $client = static::createClient();
        $token  = $this->jwtFor($this->persistUser(true));

        $owner = $this->persistUser(false);
        $conv  = $this->seedConversion($owner, 'jpg', 'png', ConversionStatus::Completed, null, withOutput: true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/admin/conversions/{$conv->getId()}/file?side=bogus", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('bad_request', $data['error']);
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

    private function seedConversion(User $owner, string $from, string $to, ConversionStatus $status, ?string $err, bool $withOutput = false): Conversion
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $input = (new FileStorage())
            ->setOriginalName('in.' . $from)
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(123);
        $em->persist($input);
        $this->toRemove[] = $input;

        $output = null;
        if ($withOutput) {
            $output = (new FileStorage())
                ->setOriginalName('out.' . $to)
                ->setStoragePath('results/test/' . bin2hex(random_bytes(8)) . '.' . $to)
                ->setMimeType('application/octet-stream')
                ->setSizeBytes(456);
            $em->persist($output);
            $this->toRemove[] = $output;
        }

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Image)
            ->setStatus($status)
            ->setErrorMessage($err)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
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

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
