<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

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
 * Функциональные тесты обогащённой истории и двух owner-scoped file-эндпоинтов:
 *  - GET /convert/history — новые ключи (processing_ms, source_*, result_*, previewable);
 *  - GET /convert/{id}/source — стрим входного файла, 410 при удалённом объекте;
 *  - GET /convert/{id}/preview — inline текстовое превью (415 для бинаря, text/plain для текста).
 *
 * Owner-scope: чужая/несуществующая конвертация — 404 (не 403), не палим существование.
 * S3Storage (класс final) подменяется реальным поверх S3Client на MockHttpClient —
 * так же, как в FileCleanupServiceTest (Functional). Требуют тест-БД convertor-test.
 */
final class ConversionHistoryFileControllerTest extends WebTestCase
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

    // -------------------------------------------------------------------------
    // history — обогащённые ключи
    // -------------------------------------------------------------------------

    public function testHistoryReturnsEnrichedKeysWithPreviewableFlags(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        // md-результат (text/markdown) → previewable=true, с processing_ms и размерами.
        $mdConv = $this->seedConversion(
            $owner,
            'docx',
            'md',
            ConversionStatus::Completed,
            input: ['name' => 'договор.docx', 'size' => 4096],
            output: ['name' => 'договор.md', 'mime' => 'text/markdown', 'size' => 321],
            processingMs: 1234,
        );

        // png-результат (image/png) → previewable=false.
        $pngConv = $this->seedConversion(
            $owner,
            'jpg',
            'png',
            ConversionStatus::Completed,
            input: ['name' => 'photo.jpg', 'size' => 8192],
            output: ['name' => 'photo.png', 'mime' => 'image/png', 'size' => 9000],
            processingMs: 500,
        );

        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/api/v1/convert/history', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('items', $data);

        $byId = [];
        foreach ($data['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        // md-строка: все новые ключи + previewable=true.
        $md = $byId[$mdConv->getId()] ?? null;
        self::assertNotNull($md, 'md-конвертация должна быть в истории');
        foreach (
            ['id', 'from_format', 'to_format', 'status', 'is_ai', 'created_at',
                'processing_ms', 'source_name', 'source_size', 'result_size', 'result_mime', 'previewable'] as $key
        ) {
            self::assertArrayHasKey($key, $md, $key);
        }
        self::assertSame('docx', $md['from_format']);
        self::assertSame('md', $md['to_format']);
        self::assertSame('completed', $md['status']);
        self::assertFalse($md['is_ai']);
        self::assertSame(1234, $md['processing_ms']);
        self::assertSame('договор.docx', $md['source_name']);
        self::assertSame(4096, $md['source_size']);
        self::assertSame(321, $md['result_size']);
        self::assertSame('text/markdown', $md['result_mime']);
        self::assertTrue($md['previewable'], 'md-результат должен быть previewable');

        // png-строка: previewable=false.
        $png = $byId[$pngConv->getId()] ?? null;
        self::assertNotNull($png);
        self::assertSame('image/png', $png['result_mime']);
        self::assertFalse($png['previewable'], 'png-результат не previewable');
    }

    public function testHistoryPendingConversionHasNullResultFieldsAndNotPreviewable(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        $conv = $this->seedConversion(
            $owner,
            'docx',
            'pdf',
            ConversionStatus::Pending,
            input: ['name' => 'draft.docx', 'size' => 100],
            output: null,
            processingMs: null,
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/api/v1/convert/history', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $item = null;
        foreach ($data['items'] as $row) {
            if ($row['id'] === $conv->getId()) {
                $item = $row;
            }
        }
        self::assertNotNull($item);
        self::assertNull($item['processing_ms']);
        self::assertNull($item['result_size']);
        self::assertNull($item['result_mime']);
        self::assertFalse($item['previewable']);
        self::assertSame('draft.docx', $item['source_name']);
        self::assertSame(100, $item['source_size']);
    }

    // -------------------------------------------------------------------------
    // source
    // -------------------------------------------------------------------------

    public function testSourceReturns410WhenInputObjectMissing(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        $conv = $this->seedConversion(
            $owner,
            'docx',
            'pdf',
            ConversionStatus::Completed,
            input: ['name' => 'gone.docx', 'size' => 10],
            output: ['name' => 'gone.pdf', 'mime' => 'application/pdf', 'size' => 20],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // S3 отвечает 404 NoSuchKey → контроллер обязан вернуть 410, не 500.
        $this->overrideS3(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>');

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/source", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        self::assertSame(410, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('gone', $data['error']);
    }

    public function testSourceReturns404ForNonOwner(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $other  = $this->persistUser();
        $token  = $this->jwtFor($other);

        $conv = $this->seedConversion(
            $owner,
            'docx',
            'pdf',
            ConversionStatus::Completed,
            input: ['name' => 'secret.docx', 'size' => 10],
            output: null,
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/source", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testSourceWithoutAuthGetsTransientGuestAnd404(): void
    {
        // Firewall `convert` = ROLE_GUEST: аноним получает ТРАНЗИЕНТНОГО гостя
        // (GuestAuthenticator, ленивая модель), а не null. Чужая/несуществующая
        // конвертация для гостя → 404 (owner-scope), не 401 и не утечка существования.
        $client = static::createClient();
        $client->request('GET', '/api/v1/convert/999999999/source');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // preview
    // -------------------------------------------------------------------------

    public function testPreviewReturns415ForBinaryResult(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        // pdf-результат (application/pdf) — не текст → 415 (S3 не читается).
        $conv = $this->seedConversion(
            $owner,
            'docx',
            'pdf',
            ConversionStatus::Completed,
            input: ['name' => 'in.docx', 'size' => 10],
            output: ['name' => 'out.pdf', 'mime' => 'application/pdf', 'size' => 2048],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        self::assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unsupported', $data['error']);
    }

    public function testPreviewReturnsTextPlainForTextResult(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        $conv = $this->seedConversion(
            $owner,
            'csv',
            'json',
            ConversionStatus::Completed,
            input: ['name' => 'data.csv', 'size' => 10],
            output: ['name' => 'data.json', 'mime' => 'application/json', 'size' => 11],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->overrideS3(206, '{"a":1}');

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('{"a":1}', $response->getContent());
        // Объект меньше потолка → без сигнала усечения.
        self::assertFalse($response->headers->has('X-Preview-Truncated'));
    }

    public function testPreviewSetsTruncatedHeaderForLargeResult(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        // Размер результата > 64 KiB → заголовок X-Preview-Truncated.
        $conv = $this->seedConversion(
            $owner,
            'txt',
            'txt',
            ConversionStatus::Completed,
            input: ['name' => 'big.txt', 'size' => 10],
            output: ['name' => 'big.txt', 'mime' => 'text/plain', 'size' => 200000],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // Отдаём ровно потолок (64 KiB) — как реальный Range-ответ.
        $this->overrideS3(206, str_repeat('x', 65536));

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('X-Preview-Truncated'));
        self::assertSame(65536, \strlen((string) $response->getContent()));
    }

    public function testPreviewReturnsEmptyTextForZeroByteResultWithoutHittingS3(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        // 0-байт текстовый результат: Range на нём дал бы 416 → 500. Контроллер
        // короткозамыкает пустым превью, НЕ дёргая S3 (иначе mock ниже упал бы).
        $conv = $this->seedConversion(
            $owner,
            'txt',
            'txt',
            ConversionStatus::Completed,
            input: ['name' => 'empty.txt', 'size' => 0],
            output: ['name' => 'empty.txt', 'mime' => 'text/plain', 'size' => 0],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // S3 отвечал бы 416 — но контроллер не должен к нему обращаться.
        $this->overrideS3(416, '<?xml version="1.0"?><Error><Code>InvalidRange</Code></Error>');

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('X-Preview-Truncated'));
    }

    public function testPreviewReturns409WhenNotCompleted(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $token  = $this->jwtFor($owner);

        $conv = $this->seedConversion(
            $owner,
            'csv',
            'json',
            ConversionStatus::Processing,
            input: ['name' => 'in.csv', 'size' => 10],
            output: null,
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testPreviewReturns404ForNonOwner(): void
    {
        $client = static::createClient();
        $owner  = $this->persistUser();
        $other  = $this->persistUser();
        $token  = $this->jwtFor($other);

        $conv = $this->seedConversion(
            $owner,
            'csv',
            'json',
            ConversionStatus::Completed,
            input: ['name' => 'in.csv', 'size' => 10],
            output: ['name' => 'out.json', 'mime' => 'application/json', 'size' => 11],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', "/api/v1/convert/{$conv->getId()}/preview", server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

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

    private function persistUser(): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    /**
     * @param array{name: string, size: int}                 $input
     * @param array{name: string, mime: string, size: int}|null $output
     */
    private function seedConversion(
        User $owner,
        string $from,
        string $to,
        ConversionStatus $status,
        array $input,
        ?array $output,
        ?int $processingMs = null,
    ): Conversion {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $inputFile = (new FileStorage())
            ->setOriginalName($input['name'])
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.' . $from)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes($input['size']);
        $em->persist($inputFile);
        $this->toRemove[] = $inputFile;

        $outputFile = null;
        if ($output !== null) {
            $outputFile = (new FileStorage())
                ->setOriginalName($output['name'])
                ->setStoragePath('results/test/' . bin2hex(random_bytes(8)) . '.' . $to)
                ->setMimeType($output['mime'])
                ->setSizeBytes($output['size']);
            $em->persist($outputFile);
            $this->toRemove[] = $outputFile;
        }

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($inputFile)
            ->setOutputFile($outputFile)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory(FileCategory::Document)
            ->setStatus($status)
            ->setProcessingMs($processingMs)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($conv);
        $this->toRemove[] = $conv;

        return $conv;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
