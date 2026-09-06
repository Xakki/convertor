<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\DTO\ApiAuditEvent;
use App\DTO\ApiAuditIdentityType;
use App\DTO\ApiAuditTokenMetadata;
use App\Entity\User;
use App\Repository\ApiAuditRecordRepository;
use App\Service\Audit\ApiAuditRetentionService;
use App\Service\Auth\PersonalApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAuditControllerTest extends WebTestCase
{
    private const OWNER_EMAIL = 'cnv109-owner@example.test';
    private const OTHER_EMAIL = 'cnv109-other@example.test';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client        = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->createQuery('DELETE FROM App\\Entity\\User user WHERE user.email IN (:emails)')
                ->setParameter('emails', [self::OWNER_EMAIL, self::OTHER_EMAIL])
                ->execute();
            $this->entityManager->clear();
        }
        parent::tearDown();
    }

    public function testHistoryIsOwnerIsolatedAndEmptyForOwnerWithoutRecords(): void
    {
        [$owner, $secret] = $this->createOwnerWithToken();
        $other            = (new User())->setEmail(self::OTHER_EMAIL)->setIsGuest(false);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->appendRecord($other, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));

        self::assertNotNull(static::getContainer()->get(PersonalApiTokenService::class)->authenticate($secret));
        $this->client->request('GET', '/api/v1/audit/history', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items']);
        self::assertSame($owner->getId(), $this->entityManager->getRepository(User::class)->find($owner->getId())->getId());
    }

    public function testHistoryCursorIsCanonicalAndPagesStableNewestFirst(): void
    {
        [$owner, $secret] = $this->createOwnerWithToken();
        $createdAt        = new \DateTimeImmutable('2026-01-01 00:00:00.123456', new \DateTimeZone('UTC'));
        $this->appendRecord($owner, $createdAt, 200);
        $this->appendRecord($owner, $createdAt, 201);
        $this->appendRecord($owner, $createdAt, 202);

        $this->client->request('GET', '/api/v1/audit/history?limit=2', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $first['items']);
        self::assertSame([202, 201], array_column($first['items'], 'status'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $first['next_cursor']);
        self::assertStringNotContainsString('=', $first['next_cursor']);
        $cursorPayload = json_decode((string) base64_decode(strtr($first['next_cursor'], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/Z$/D', $cursorPayload['created_at']);

        $this->client->request('GET', '/api/v1/audit/history?limit=2&cursor=' . rawurlencode($first['next_cursor']), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([200], array_column($second['items'], 'status'));
        self::assertNull($second['next_cursor']);
    }

    public function testMalformedNoncanonicalAndOffsetCursorsReturnBadRequest(): void
    {
        [, $secret] = $this->createOwnerWithToken();
        $cursors    = [
            'not-base64',
            rtrim(strtr(base64_encode('{"created_at":"2026-01-01T00:00:00.000000Z","id":1}'), '+/', '-_'), '=') . '=',
            rtrim(strtr(base64_encode('{"created_at":"2026-01-01T00:00:00.000000+00:00","id":1}'), '+/', '-_'), '='),
        ];
        foreach ($cursors as $cursor) {
            $this->client->request('GET', '/api/v1/audit/history?cursor=' . rawurlencode($cursor), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), $cursor);
        }
    }

    public function testRealPersonalTokenResponseIsPersistedAndHistoryDoesNotSelfAudit(): void
    {
        [$owner, $secret] = $this->createOwnerWithToken();
        $records          = static::getContainer()->get(ApiAuditRecordRepository::class);
        $startedAt        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->client->request('GET', '/api/v1/quota', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/convert/2147483647/status', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->client->request('GET', '/api/v1/audit/history', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
        self::assertResponseIsSuccessful();
        $history = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertCount(2, $history['items']);

        $persisted = $records->findForOwner($owner);
        self::assertCount(2, $persisted);
        $items = array_map(static fn ($record): array => $record->toArray(), $persisted);
        self::assertSame(
            [
                ['method' => 'GET', 'route' => '/api/v1/convert/{id}/status', 'status' => 404],
                ['method' => 'GET', 'route' => '/api/v1/quota', 'status' => 200],
            ],
            array_map(static fn (array $item): array => [
                'method' => $item['method'],
                'route'  => $item['route'],
                'status' => $item['status'],
            ], $items),
        );
        foreach ($persisted as $record) {
            $item = $record->toArray();
            self::assertSame($owner->getId(), $record->getOwner()->getId());
            self::assertSame('cnv_********', $item['token_mask']);
            self::assertSame('cnv109-test', $item['token_label']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?[+]00:00$/', $item['created_at']);
            $createdAt = new \DateTimeImmutable($item['created_at']);
            self::assertSame(0, $createdAt->getOffset());
            self::assertGreaterThanOrEqual($startedAt->getTimestamp(), $createdAt->getTimestamp());
            self::assertLessThanOrEqual($finishedAt->getTimestamp(), $createdAt->getTimestamp());
            self::assertGreaterThanOrEqual(0, $item['duration_ms']);
        }
    }

    public function testGuestAndJwtCannotUseHistory(): void
    {
        $this->client->request('GET', '/api/v1/audit/history');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/audit/history', server: ['HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiJ9.test.signature']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testRetentionPurgeIsIdempotentAgainstRealDatabase(): void
    {
        [$owner] = $this->createOwnerWithToken();
        $this->appendRecord($owner, new \DateTimeImmutable('2025-12-31 23:59:59', new \DateTimeZone('UTC')));
        $this->appendRecord($owner, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));

        $retention = static::getContainer()->get(ApiAuditRetentionService::class);
        $now       = new \DateTimeImmutable('2026-04-01 00:00:00', new \DateTimeZone('UTC'));
        self::assertSame(1, $retention->purge($now));
        self::assertSame(0, $retention->purge($now));
        self::assertCount(1, static::getContainer()->get(ApiAuditRecordRepository::class)->findForOwner($owner));
    }

    /** @return array{User, string} */
    private function createOwnerWithToken(): array
    {
        $owner = (new User())->setEmail(self::OWNER_EMAIL)->setIsGuest(false);
        $this->entityManager->persist($owner);
        $this->entityManager->flush();
        $result = static::getContainer()->get(PersonalApiTokenService::class)->issue($owner, 'cnv109-test');

        return [$owner, $result['secret']];
    }

    private function appendRecord(User $owner, \DateTimeImmutable $createdAt, int $status = 200): void
    {
        static::getContainer()->get(ApiAuditRecordRepository::class)->append($owner, new ApiAuditEvent(
            'GET',
            '/api/v1/quota',
            $status,
            1,
            new ApiAuditTokenMetadata('cnv109-test', 'cnv_********', ApiAuditIdentityType::PERSONAL_TOKEN),
            null,
            $createdAt,
        ), true);
    }
}
