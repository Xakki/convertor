<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramLoginNonceCookieFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты POST /start и GET /poll (pairing + poll модель).
 * TelegramLoginCodeStore и зависимости мокируются в контейнере — тесты не
 * трогают живой KeyDB/БД (кроме merge-seam теста, см. отдельный DB-тест).
 */
final class TelegramLoginControllerTest extends WebTestCase
{
    public function testStartMintsCodeDeepLinkAndNonceCookie(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $store = $this->createStub(TelegramLoginCodeStore::class);
        $store->method('mint')->willReturn(['code' => 'CODE123', 'nonce' => 'NONCE123']);
        $store->method('ttl')->willReturn(300);
        $container->set(TelegramLoginCodeStore::class, $store);

        $client->request('POST', '/api/v1/auth/telegram/start');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('CODE123', $body['code']);
        self::assertSame(300, $body['expires_in']);
        self::assertSame('https://t.me/anyconvertor_bot?start=CODE123', $body['deep_link']);

        // Nonce уходит только в httpOnly-cookie — не в теле ответа.
        self::assertArrayNotHasKey('nonce', $body);
        $nonceCookie = $this->findCookie($client, TelegramLoginNonceCookieFactory::NAME);
        self::assertNotNull($nonceCookie, 'tg_login_nonce cookie must be set');
        self::assertSame('NONCE123', $nonceCookie->getValue());
        self::assertTrue($nonceCookie->isHttpOnly());
    }

    public function testPollPendingReturns204(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $store = $this->createMock(TelegramLoginCodeStore::class);
        $store->expects(self::once())->method('redeem')->with('CODE123', 'NONCE123')
            ->willReturn(['status' => TelegramLoginCodeStore::STATUS_PENDING, 'userId' => null]);
        $container->set(TelegramLoginCodeStore::class, $store);

        $this->setNonceCookie($client, 'NONCE123');
        $client->request('GET', '/api/v1/auth/telegram/poll?code=CODE123');

        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testPollMismatchReturns403(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        // mismatch = не тот браузер (nonce) → 403. Код НЕ гасится.
        $store = $this->createMock(TelegramLoginCodeStore::class);
        $store->expects(self::once())->method('redeem')->with('CODE123', 'ATTACKER-NONCE')
            ->willReturn(['status' => TelegramLoginCodeStore::STATUS_MISMATCH, 'userId' => null]);
        $container->set(TelegramLoginCodeStore::class, $store);

        $this->setNonceCookie($client, 'ATTACKER-NONCE');
        $client->request('GET', '/api/v1/auth/telegram/poll?code=CODE123');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('mismatch', $body['error']);
        $this->assertNonceCookieCleared($client);
    }

    public function testPollWithoutNonceCookieReturnsBadRequest(): void
    {
        $client = static::createClient();

        // Нет nonce-cookie вообще → нельзя завершить вход (не тот браузер).
        $client->request('GET', '/api/v1/auth/telegram/poll?code=CODE123');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testPollWithoutCodeReturnsBadRequest(): void
    {
        $client = static::createClient();

        $this->setNonceCookie($client, 'NONCE123');
        $client->request('GET', '/api/v1/auth/telegram/poll');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testPollAuthorizedSetsRefreshCookieAndClearsNonce(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(555);

        $store = $this->createMock(TelegramLoginCodeStore::class);
        $store->expects(self::once())->method('redeem')->with('CODE123', 'NONCE123')
            ->willReturn(['status' => TelegramLoginCodeStore::STATUS_AUTHORIZED, 'userId' => 555]);
        $container->set(TelegramLoginCodeStore::class, $store);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('find')->with(555)->willReturn($user);
        $container->set(UserRepository::class, $users);

        // RefreshTokenService — final, не мокается; настоящий пишет семейство в
        // KeyDB (test db). Нам важно лишь, что refresh-cookie выставлена.

        $this->setNonceCookie($client, 'NONCE123');
        $client->request('GET', '/api/v1/auth/telegram/poll?code=CODE123');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('authorized', $body['status']);

        $names = array_map(static fn ($c) => $c->getName(), $client->getResponse()->headers->getCookies());
        self::assertContains('refresh_token', $names);

        // Nonce-cookie погашена (одноразовая).
        $this->assertNonceCookieCleared($client);
    }

    /**
     * Без валидного guest_id merge-ветка не берётся → guest-cookie на ответе НЕ
     * трогается (нет гашения). Проверяем оба «невалидных» случая.
     *
     * @return iterable<string, array{0: ?string}>
     */
    public static function nonMergingGuestCookieProvider(): iterable
    {
        yield 'no cookie' => [null];
        yield 'tampered sig' => ['guest-abc-123.deadbeefbadsig'];
    }

    #[DataProvider('nonMergingGuestCookieProvider')]
    public function testPollAuthorizedWithoutValidGuestCookieDoesNotClearGuestCookie(?string $rawCookie): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(888);

        $store = $this->createStub(TelegramLoginCodeStore::class);
        $store->method('redeem')
            ->willReturn(['status' => TelegramLoginCodeStore::STATUS_AUTHORIZED, 'userId' => 888]);
        $container->set(TelegramLoginCodeStore::class, $store);

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $this->setNonceCookie($client, 'NONCE888');
        if ($rawCookie !== null) {
            $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(GuestCookieFactory::NAME, $rawCookie));
        }
        $client->request('GET', '/api/v1/auth/telegram/poll?code=CODE888');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $names = array_map(static fn ($c) => $c->getName(), $client->getResponse()->headers->getCookies());
        self::assertNotContains(GuestCookieFactory::NAME, $names, 'no guest-cookie clear without a valid inbound guest_id');
    }

    public function testPollExpiredReturns410(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $store = $this->createStub(TelegramLoginCodeStore::class);
        $store->method('redeem')
            ->willReturn(['status' => TelegramLoginCodeStore::STATUS_EXPIRED, 'userId' => null]);
        $container->set(TelegramLoginCodeStore::class, $store);

        $this->setNonceCookie($client, 'whatever');
        $client->request('GET', '/api/v1/auth/telegram/poll?code=whatever');

        self::assertSame(410, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('expired', $body['error']);
        $this->assertNonceCookieCleared($client);
    }

    private function setNonceCookie(object $client, string $value): void
    {
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(TelegramLoginNonceCookieFactory::NAME, $value),
        );
    }

    private function findCookie(object $client, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** Гашение tg_login_nonce: expires в прошлом, path/sameSite как у create. */
    private function assertNonceCookieCleared(object $client): void
    {
        $nonceClear = $this->findCookie($client, TelegramLoginNonceCookieFactory::NAME);
        self::assertNotNull($nonceClear, 'tg_login_nonce clear Set-Cookie expected');
        self::assertLessThan(time(), $nonceClear->getExpiresTime());
        self::assertSame(TelegramLoginNonceCookieFactory::PATH, $nonceClear->getPath());
        self::assertSame(\Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX, $nonceClear->getSameSite());
        self::assertTrue($nonceClear->isHttpOnly());
        // Secure берётся из REFRESH_COOKIE_SECURE (false в .env.test) — сверяем с фабрикой.
        $expected = static::getContainer()->get(TelegramLoginNonceCookieFactory::class)->clear();
        self::assertSame($expected->isSecure(), $nonceClear->isSecure());
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
