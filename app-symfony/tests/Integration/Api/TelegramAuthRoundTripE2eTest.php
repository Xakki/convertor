<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Service\Auth\TelegramBotClient;
use App\Service\Auth\TelegramLoginNonceCookieFactory;
use App\Service\Queue\RedisConnectionFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/**
 * E2E auth-round-trip Telegram pairing+poll с мок-ботом.
 *
 * Мокается ТОЛЬКО исходящий {@see TelegramBotClient} (Bot API HTTP). Реальные
 * контроллеры `POST /start` → `POST /telegram/webhook` → `GET /poll` гоняются
 * через HTTP (WebTestCase). Состояние code/nonce — в реальном KeyDB тест-стека
 * (REDIS_SESSIONS_DSN → dbindex=3 из app-symfony/.env.test), не in-memory.
 *
 * Покрывает: pending→authorized, nonce-фактор, one-time redeem, no-burn на
 * mismatch. Без ссылок на удалённые callback/linkSecret.
 */
#[Group('integration')]
final class TelegramAuthRoundTripE2eTest extends WebTestCase
{
    private const WEBHOOK_URL = '/api/v1/telegram/webhook';
    private const SECRET      = 'test-telegram-webhook-secret';

    /** @var list<int> */
    private array $userIdsToRemove = [];

    protected function tearDown(): void
    {
        if ($this->userIdsToRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->userIdsToRemove as $id) {
                $user = $em->find(User::class, $id);
                if ($user !== null) {
                    $em->remove($user);
                }
            }
            $em->flush();
            $this->userIdsToRemove = [];
        }

        parent::tearDown();
    }

    public function testRoundTripPendingAuthorizedThenOneTimeGone(): void
    {
        $client = $this->bootClientWithMockBot();
        $this->requireKeyDb();

        $start = $this->startLogin($client);
        $code  = $start['code'];
        $nonce = $start['nonce'];

        // До апрува в боте poll = pending (код не гасится).
        $client->request('GET', '/api/v1/auth/telegram/poll?code=' . rawurlencode($code));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $tgId = $this->uniqueTelegramId('e2e-ok');
        $this->approveInBot($client, $code, $tgId, 'e2e_ok', 'E2E');

        // Исходная вкладка: code + nonce-cookie → authorized + refresh.
        $client->request('GET', '/api/v1/auth/telegram/poll?code=' . rawurlencode($code));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('authorized', $body['status']);
        self::assertArrayNotHasKey('nonce', $body);
        self::assertArrayNotHasKey('linkSecret', $body);
        self::assertArrayNotHasKey('s', $body);

        $cookieNames = array_map(
            static fn (HttpCookie $c) => $c->getName(),
            $client->getResponse()->headers->getCookies(),
        );
        self::assertContains('refresh_token', $cookieNames);

        // Nonce погашен (одноразовая cookie).
        $nonceClear = $this->findResponseCookie($client, TelegramLoginNonceCookieFactory::NAME);
        self::assertNotNull($nonceClear);
        self::assertLessThan(time(), $nonceClear->getExpiresTime());

        $this->trackProvisionedUser($tgId);

        // One-time: повторный poll по тому же code → gone (ключ DEL в KeyDB).
        // Cookie jar ещё несёт старый nonce (гашение с ответа не удаляет из jar
        // BrowserKit автоматически) — этого достаточно для redeem-пути.
        $client->getCookieJar()->set(
            new BrowserCookie(TelegramLoginNonceCookieFactory::NAME, $nonce),
        );
        $client->request('GET', '/api/v1/auth/telegram/poll?code=' . rawurlencode($code));
        self::assertSame(410, $client->getResponse()->getStatusCode());
        $gone = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('expired', $gone['error'] ?? null);
    }

    public function testNonceMismatchDoesNotBurnCode(): void
    {
        $client = $this->bootClientWithMockBot();
        $this->requireKeyDb();

        $start = $this->startLogin($client);
        $code  = $start['code'];
        $nonce = $start['nonce'];

        $tgId = $this->uniqueTelegramId('e2e-fix');
        $this->approveInBot($client, $code, $tgId, 'e2e_fix', 'Fix');

        // Чужой браузер: неверный nonce → 403, код НЕ сгорает (no-burn).
        $client->getCookieJar()->set(
            new BrowserCookie(TelegramLoginNonceCookieFactory::NAME, 'nonce-of-another-browser'),
        );
        $client->request('GET', '/api/v1/auth/telegram/poll?code=' . rawurlencode($code));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $mismatch = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('mismatch', $mismatch['error'] ?? null);

        // Легитимный браузер всё ещё может завершить вход.
        $client->getCookieJar()->set(
            new BrowserCookie(TelegramLoginNonceCookieFactory::NAME, $nonce),
        );
        $client->request('GET', '/api/v1/auth/telegram/poll?code=' . rawurlencode($code));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('authorized', $body['status'] ?? null);

        $this->trackProvisionedUser($tgId);
    }

    /**
     * @return array{code: string, nonce: string}
     */
    private function startLogin(KernelBrowser $client): array
    {
        $client->request('POST', '/api/v1/auth/telegram/start');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('deep_link', $body);
        self::assertArrayNotHasKey('nonce', $body);
        self::assertArrayNotHasKey('linkSecret', $body);
        self::assertStringContainsString('?start=' . $body['code'], (string) $body['deep_link']);

        $nonceCookie = $this->findResponseCookie($client, TelegramLoginNonceCookieFactory::NAME);
        self::assertNotNull($nonceCookie, 'tg_login_nonce must be set on /start');
        self::assertTrue($nonceCookie->isHttpOnly());

        return ['code' => (string) $body['code'], 'nonce' => $nonceCookie->getValue()];
    }

    private function approveInBot(
        KernelBrowser $client,
        string $code,
        string $telegramId,
        string $username,
        string $firstName,
    ): void {
        // Шаг 1: /start <code> → инлайн-кнопка «Войти» (мок бота уже no-op).
        $startUpdate = [
            'message' => [
                'chat' => ['id' => 900_001],
                'text' => '/start ' . $code,
            ],
        ];
        $client->request(
            'POST',
            self::WEBHOOK_URL,
            [],
            [],
            $this->webhookHeaders(),
            (string) json_encode($startUpdate),
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Шаг 2: callback_query «Войти» → provisioner + authorize(code).
        $cbUpdate = [
            'callback_query' => [
                'id'   => 'cb-' . bin2hex(random_bytes(4)),
                'data' => 'login:' . $code,
                'from' => [
                    'id'         => (int) $telegramId,
                    'username'   => $username,
                    'first_name' => $firstName,
                ],
                'message' => ['chat' => ['id' => 900_001]],
            ],
        ];
        $client->request(
            'POST',
            self::WEBHOOK_URL,
            [],
            [],
            $this->webhookHeaders(),
            (string) json_encode($cbUpdate),
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function bootClientWithMockBot(): KernelBrowser
    {
        $client = static::createClient();
        // Без disableReboot ядро пересоздаётся между HTTP-запросами и съедает
        // подмену TelegramBotClient (мок нужен на всём round-trip start→webhook→poll).
        $client->disableReboot();

        $bot = $this->createStub(TelegramBotClient::class);
        static::getContainer()->set(TelegramBotClient::class, $bot);

        return $client;
    }

    private function requireKeyDb(): void
    {
        // Изоляция = REDIS_SESSIONS_DSN из .env.test (dbindex=3), как у login-code store.
        $dsn = getenv('REDIS_SESSIONS_DSN')
            ?: ($_SERVER['REDIS_SESSIONS_DSN'] ?? 'redis://keydb:6379?dbindex=3');

        try {
            (new RedisConnectionFactory((string) $dsn))->create()->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB (sessions db) not reachable: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function webhookHeaders(): array
    {
        return [
            'CONTENT_TYPE'                         => 'application/json',
            'HTTP_X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ];
    }

    private function findResponseCookie(KernelBrowser $client, string $name): ?HttpCookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function uniqueTelegramId(string $tag): string
    {
        // Числовой id как у Telegram; суффикс уникален на прогон.
        return (string) (8_000_000_000 + (crc32($tag . microtime(true) . random_int(0, 999_999)) % 1_000_000_000));
    }

    private function trackProvisionedUser(string $telegramId): void
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['telegramId' => $telegramId]);
        if ($user !== null && $user->getId() !== null) {
            $this->userIdsToRemove[] = $user->getId();
        }
    }
}
