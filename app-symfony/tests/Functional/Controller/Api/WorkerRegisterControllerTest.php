<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Repository\WorkerCapabilityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты POST /api/v1/worker/register.
 *
 * WorkerCapabilityRepository мокируется в контейнере, чтобы тесты не
 * зависели от живой БД. ConversionRegistry больше не участвует (CNV-71-02:
 * register() больше не инвалидирует роутинг-матрицу — та строится из
 * статического каталога, не из этой таблицы).
 */
final class WorkerRegisterControllerTest extends WebTestCase
{
    private const TOKEN = 'Bearer test-worker-token';
    private const URL   = '/api/v1/worker/register';

    private const VALID_PAYLOAD = [
        'workerType'  => 'image',
        'instanceId'  => 'host-a.image-0',
        'isAi'        => false,
        'streams'     => ['image'],
        'routingKeys' => ['image'],
        'matrix'      => ['jpg' => ['png', 'webp']],
        'image'       => null,
        'version'     => '1.0.0',
    ];

    // -------------------------------------------------------------------------
    // Авторизация
    // -------------------------------------------------------------------------

    public function testRegisterReturns401WithNoToken(): void
    {
        $client = static::createClient();
        $client->request('POST', self::URL);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Валидный запрос
    // -------------------------------------------------------------------------

    public function testRegisterAcceptsValidPayload(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createMock(WorkerCapabilityRepository::class);
        $repo->expects(self::once())
            ->method('upsert')
            ->with('image', 'host-a.image-0', self::VALID_PAYLOAD, null);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode(self::VALID_PAYLOAD),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($body['ok'] ?? false);
    }

    /**
     * Повторный вызов register с тем же workerType возвращает 200.
     * Idempotency на уровне БД обеспечивает WorkerCapabilityRepository::upsert().
     */
    public function testReRegisterReturns200(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode(self::VALID_PAYLOAD),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // host (registry-08): опциональное поле, отдельный параметр upsert()
    // -------------------------------------------------------------------------

    public function testRegisterForwardsHostToRepository(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $payload = array_merge(self::VALID_PAYLOAD, ['host' => 'xbook-remote']);

        $repo = $this->createMock(WorkerCapabilityRepository::class);
        $repo->expects(self::once())
            ->method('upsert')
            ->with('image', 'host-a.image-0', $payload, 'xbook-remote');
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testRegisterWithoutHostForwardsNull(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createMock(WorkerCapabilityRepository::class);
        $repo->expects(self::once())
            ->method('upsert')
            ->with('image', 'host-a.image-0', self::VALID_PAYLOAD, null);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode(self::VALID_PAYLOAD),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testRegisterReturns400WhenHostIsNotAString(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $payload = array_merge(self::VALID_PAYLOAD, ['host' => 12345]);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('host', (string) ($body['error'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // workerType — валидация по App\Enum\WorkerType
    // -------------------------------------------------------------------------

    /**
     * Неизвестный workerType (не входит в App\Enum\WorkerType) → 400, upsert НЕ
     * вызывается — невалидная регистрация не должна долетать до репозитория.
     */
    public function testRegisterReturns400OnUnknownWorkerTypeAndDoesNotUpsert(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createMock(WorkerCapabilityRepository::class);
        $repo->expects(self::never())->method('upsert');
        $container->set(WorkerCapabilityRepository::class, $repo);

        $payload = array_merge(self::VALID_PAYLOAD, ['workerType' => 'bogus']);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(
            'workerType "bogus" is not a known worker type (allowed: document, image, audio, video, data, ai, browser)',
            $body['error'] ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // Невалидные запросы → 400
    // -------------------------------------------------------------------------

    public function testRegisterReturns400OnInvalidJson(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            'not-json',
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testRegisterReturns400OnMissingOrInvalidField(array $payload, string $expectedError): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $container->set(WorkerCapabilityRepository::class, $repo);

        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString($expectedError, (string) ($body['error'] ?? ''));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        $base = self::VALID_PAYLOAD;

        return [
            'missing workerType'     => [array_diff_key($base, ['workerType' => true]), 'workerType'],
            'empty workerType'       => [array_merge($base, ['workerType' => '']), 'workerType'],
            'unknown workerType'     => [array_merge($base, ['workerType' => 'bogus']), 'workerType'],
            'missing instanceId'     => [array_diff_key($base, ['instanceId' => true]), 'instanceId'],
            'empty instanceId'       => [array_merge($base, ['instanceId' => '']), 'instanceId'],
            'too long instanceId'    => [array_merge($base, ['instanceId' => str_repeat('a', 129)]), 'instanceId'],
            'bad charset instanceId' => [array_merge($base, ['instanceId' => 'host a/instance#1']), 'instanceId'],
            'reserved instanceId'    => [array_merge($base, ['instanceId' => '__seed__']), 'reserved'],
            'isAi not bool'          => [array_merge($base, ['isAi' => 'yes']), 'isAi'],
            'missing isAi'           => [array_diff_key($base, ['isAi' => true]), 'isAi'],
            'streams not array'      => [array_merge($base, ['streams' => 'conv.image']), 'streams'],
            'routingKeys not array'  => [array_merge($base, ['routingKeys' => 'image']), 'routingKeys'],
            'matrix not array'       => [array_merge($base, ['matrix' => 'jpg:png']), 'matrix'],
        ];
    }
}
