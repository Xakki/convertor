<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Service\Auth\RefreshTokenService;
use App\Service\Queue\RedisConnectionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * HTTP + cookie wiring for POST /api/v1/auth/refresh and /logout — the layer the
 * unit/integration tests can't reach (cookie attributes, status codes, JSON body).
 * The happy-path and logout-with-cookie cases need a real KeyDB (sessions db);
 * they skip cleanly if it is unreachable. The happy path also creates and deletes
 * a real active user row.
 */
final class AuthRefreshControllerTest extends WebTestCase
{
    private const TEST_EMAIL = 'qa-refresh-functional@example.test';
    private const TEST_TG_ID = '990001112223';

    public function testTelegramLoginIssuesAccessTokenAndRefreshCookie(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $refreshTokens = $container->get(RefreshTokenService::class);
        $this->skipUnlessKeyDb($refreshTokens);
        $this->skipUnlessTestDb($container);

        $botToken = $this->telegramBotToken();
        $this->purgeTelegramUser($container);

        try {
            $payload = $this->signedTelegramPayload($botToken, self::TEST_TG_ID, time() - 30);

            $client->request(
                'POST',
                '/api/v1/auth/telegram',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
            $response = $client->getResponse();

            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('token', $body, 'login must return an access JWT');
            self::assertNotSame('', $body['token']);

            $cookie = $this->findCookie($client, 'refresh_token');
            self::assertNotNull($cookie, 'login must set a refresh_token cookie');
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame('/api/v1/auth', $cookie->getPath());
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertFalse($cookie->isSecure(), 'test env uses insecure cookie (plain HTTP)');
            self::assertNotEmpty($cookie->getValue());
        } finally {
            $this->purgeTelegramUser($container);
        }
    }

    /**
     * The token the service signs with — read from the SAME env source DI uses
     * (%env(TELEGRAM_BOT_TOKEN)%). In the test env this is an empty placeholder
     * (real secret lives only in .env.local), so we sign with the same empty
     * value and the hash still matches what TelegramAuthService::verify computes —
     * fully exercising the login endpoint rather than skipping it.
     */
    private function telegramBotToken(): string
    {
        $token = getenv('TELEGRAM_BOT_TOKEN');
        if ($token === false) {
            $token = $_SERVER['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        }

        return (string) $token;
    }

    /**
     * Build a Telegram login payload with a valid hash, mirroring
     * TelegramAuthService::buildCheckString (filter nulls, ksort, "k=v"\n join,
     * HMAC-SHA256 keyed by sha256(botToken)).
     *
     * @return array<string, int|string>
     */
    private function signedTelegramPayload(string $botToken, string $tgId, int $authDate): array
    {
        $fields = [
            'auth_date'  => (string) $authDate,
            'first_name' => 'Grace',
            'id'         => $tgId,
        ];
        ksort($fields);

        $checkString = implode("\n", array_map(
            static fn (string $k, string $v) => "{$k}={$v}",
            array_keys($fields),
            array_values($fields),
        ));
        $secretKey = hash('sha256', $botToken, true);
        $hash      = hash_hmac('sha256', $checkString, $secretKey);

        return [
            'id'         => $tgId,
            'first_name' => 'Grace',
            'auth_date'  => $authDate,
            'hash'       => $hash,
        ];
    }

    private function purgeTelegramUser(\Psr\Container\ContainerInterface $container): void
    {
        /** @var EntityManagerInterface $em */
        $em       = $container->get(EntityManagerInterface::class);
        $existing = $em->getRepository(User::class)->findOneBy(['telegramId' => self::TEST_TG_ID]);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }
    }

    public function testRefreshWithoutCookieReturns401AndClearsCookie(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/auth/refresh');

        self::assertSame(401, $client->getResponse()->getStatusCode());
        $this->assertClearCookie($client);
    }

    public function testRefreshWithGarbageCookieReturns401AndClearsCookie(): void
    {
        $client = static::createClient();
        $this->setRefreshCookie($client, 'totally-garbage-value');
        $client->request('POST', '/api/v1/auth/refresh');

        self::assertSame(401, $client->getResponse()->getStatusCode());
        $this->assertClearCookie($client);
    }

    public function testLogoutWithoutCookieClearsCookieAnd204(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/auth/logout');

        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->assertClearCookie($client);
    }

    public function testRefreshHappyPathRotatesCookieAndMintsToken(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $refreshTokens = $container->get(RefreshTokenService::class);
        $this->skipUnlessKeyDb($refreshTokens);
        $this->skipUnlessTestDb($container);

        $userId = $this->createActiveUser($container);

        try {
            $cookieValue = $refreshTokens->issueFamily($this->loadUser($container, $userId));
            $this->setRefreshCookie($client, $cookieValue);

            $client->request('POST', '/api/v1/auth/refresh');
            $response = $client->getResponse();

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('token', $body);
            self::assertNotSame('', $body['token']);

            $cookie = $this->findCookie($client, 'refresh_token');
            self::assertNotNull($cookie, 'a rotated refresh cookie must be set');
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame('/api/v1/auth', $cookie->getPath());
            self::assertFalse($cookie->isSecure(), 'test env uses insecure cookie (plain HTTP)');
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertNotSame($cookieValue, $cookie->getValue(), 'cookie must be rotated');
            self::assertNotEmpty($cookie->getValue());

            // Logout with the rotated cookie clears it.
            $this->setRefreshCookie($client, (string) $cookie->getValue());
            $client->request('POST', '/api/v1/auth/logout');
            self::assertSame(204, $client->getResponse()->getStatusCode());
            $this->assertClearCookie($client);
        } finally {
            $this->deleteUser($container, $userId);
        }
    }

    public function testRefreshWithDeactivatedUserReturns401AndRevokesFamily(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $refreshTokens = $container->get(RefreshTokenService::class);
        $this->skipUnlessKeyDb($refreshTokens);
        $this->skipUnlessTestDb($container);

        $userId = $this->createActiveUser($container);

        try {
            // Deactivate the account after issuing a valid family.
            $cookieValue = $refreshTokens->issueFamily($this->loadUser($container, $userId));
            [$familyId]  = explode('.', $cookieValue, 2);
            $this->setInactive($container, $userId);

            $this->setRefreshCookie($client, $cookieValue);
            $client->request('POST', '/api/v1/auth/refresh');

            self::assertSame(401, $client->getResponse()->getStatusCode());
            $this->assertClearCookie($client);

            // Deactivated account must kill the WHOLE family, not just this token.
            $redis = (new RedisConnectionFactory($this->sessionsDsn()))->create();
            self::assertFalse($redis->get('rt:' . $familyId), 'deactivated-user refresh must revoke the family');
        } finally {
            $this->deleteUser($container, $userId);
        }
    }

    private function sessionsDsn(): string
    {
        $dsn = getenv('REDIS_SESSIONS_DSN') ?: ($_SERVER['REDIS_SESSIONS_DSN'] ?? 'redis://keydb:6379?dbindex=1');

        return (string) $dsn;
    }

    private function setInactive(\Psr\Container\ContainerInterface $container, int $id): void
    {
        /** @var EntityManagerInterface $em */
        $em   = $container->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);
        $user->setIsActive(false);
        $em->flush();
        $em->clear();
    }

    private function skipUnlessKeyDb(RefreshTokenService $service): void
    {
        try {
            // issueFamily touches KeyDB; a throw here means the sessions store is down.
            $probe = $this->createStub(User::class);
            $probe->method('getId')->willReturn(0);
            $service->revoke($service->issueFamily($probe)); // probe + clean up the throwaway family

        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB (sessions db) not reachable: ' . $e->getMessage());
        }
    }

    private function skipUnlessTestDb(\Psr\Container\ContainerInterface $container): void
    {
        try {
            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database not provisioned (needs convertor_test): ' . $e->getMessage());
        }
    }

    private function createActiveUser(\Psr\Container\ContainerInterface $container): int
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->purgeTestUser($em);

        $user = new User();
        $user->setEmail(self::TEST_EMAIL);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $id = $user->getId();
        $em->clear();

        return $id;
    }

    private function loadUser(\Psr\Container\ContainerInterface $container, int $id): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $container->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function deleteUser(\Psr\Container\ContainerInterface $container, int $id): void
    {
        /** @var EntityManagerInterface $em */
        $em   = $container->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $id);
        if ($user !== null) {
            $em->remove($user);
            $em->flush();
        }
    }

    private function purgeTestUser(EntityManagerInterface $em): void
    {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }
    }

    private function setRefreshCookie(KernelBrowser $client, string $value): void
    {
        $client->getCookieJar()->set(
            new BrowserKitCookie('refresh_token', $value, null, '/api/v1/auth', 'localhost'),
        );
    }

    private function findCookie(KernelBrowser $client, string $name): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function assertClearCookie(KernelBrowser $client): void
    {
        $cookie = $this->findCookie($client, 'refresh_token');
        self::assertNotNull($cookie, 'a clear-cookie header must be sent');
        self::assertSame('/api/v1/auth', $cookie->getPath());
        // Cleared cookie: empty value and an expiry in the past.
        self::assertEmpty($cookie->getValue());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }
}
