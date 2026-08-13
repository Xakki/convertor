<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Service\Auth\TelegramLinkCodeStore;
use App\Service\Auth\TelegramLinkNonceCookieFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;

/**
 * Функциональные тесты POST/GET /api/v1/auth/telegram/link/* (CNV-59).
 * CodeStore мокается; JWT через Lexik.
 */
#[AllowMockObjectsWithoutExpectations]
final class TelegramLinkControllerTest extends WebTestCase
{
    /** @var list<User> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->toRemove as $user) {
                $fresh = $em->find(User::class, $user->getId());
                if ($fresh !== null) {
                    $em->remove($fresh);
                }
            }
            $em->flush();
            $this->toRemove = [];
        }

        parent::tearDown();
    }

    public function testStartRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/auth/telegram/link/start');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testStartRejectsGuestRole(): void
    {
        // Без JWT firewall отдаёт 401 (GuestAuthenticator не на auth_telegram_link).
        $client = static::createClient();
        $client->request('POST', '/api/v1/auth/telegram/link/start');

        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testStartMintsDeepLinkBoundToUser(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser();
        $token  = $this->jwtFor($user);

        $store = $this->createStub(TelegramLinkCodeStore::class);
        $store->method('mint')->willReturn(['code' => 'LINKCODE', 'nonce' => 'LINKNONCE']);
        $store->method('ttl')->willReturn(300);
        static::getContainer()->set(TelegramLinkCodeStore::class, $store);

        $client->request('POST', '/api/v1/auth/telegram/link/start', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('LINKCODE', $body['code']);
        self::assertSame(300, $body['expires_in']);
        self::assertSame('https://t.me/anyconvertor_bot?start=link_LINKCODE', $body['deep_link']);
        self::assertArrayNotHasKey('nonce', $body);

        $cookie = $this->findCookie($client, TelegramLinkNonceCookieFactory::NAME);
        self::assertNotNull($cookie);
        self::assertSame('LINKNONCE', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testPollPendingReturns204(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser();
        $token  = $this->jwtFor($user);

        $store = $this->createMock(TelegramLinkCodeStore::class);
        $store->expects(self::once())->method('redeem')->with('C', 'N')
            ->willReturn(['status' => TelegramLinkCodeStore::STATUS_PENDING, 'userId' => null]);
        static::getContainer()->set(TelegramLinkCodeStore::class, $store);

        $this->setNonceCookie($client, 'N');
        $client->request('GET', '/api/v1/auth/telegram/link/poll?code=C', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testPollSuccessReturnsLinkedWithoutRefreshCookie(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser();
        $userId = $user->getId();
        \assert($userId !== null);
        $token = $this->jwtFor($user);

        $store = $this->createMock(TelegramLinkCodeStore::class);
        $store->expects(self::once())->method('redeem')->with('C', 'N')
            ->willReturn(['status' => TelegramLinkCodeStore::STATUS_AUTHORIZED, 'userId' => $userId]);
        static::getContainer()->set(TelegramLinkCodeStore::class, $store);

        $this->setNonceCookie($client, 'N');
        $client->request('GET', '/api/v1/auth/telegram/link/poll?code=C', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('linked', $body['status']);

        // Не переключаем сессию — refresh-cookie не выставляется.
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotSame('refresh_token', $cookie->getName());
            self::assertNotSame('refresh', $cookie->getName());
        }
    }

    public function testPollCollisionReturns409(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser();
        $userId = $user->getId();
        \assert($userId !== null);
        $token = $this->jwtFor($user);

        $store = $this->createMock(TelegramLinkCodeStore::class);
        $store->method('redeem')
            ->willReturn(['status' => TelegramLinkCodeStore::STATUS_COLLISION, 'userId' => $userId]);
        static::getContainer()->set(TelegramLinkCodeStore::class, $store);

        $this->setNonceCookie($client, 'N');
        $client->request('GET', '/api/v1/auth/telegram/link/poll?code=C', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('telegram_already_linked', $body['error']);
        self::assertNotEmpty($body['message'] ?? '');
    }

    public function testPollRejectsCodeBoundToOtherUser(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser();
        $token  = $this->jwtFor($user);

        $store = $this->createMock(TelegramLinkCodeStore::class);
        $store->method('redeem')
            ->willReturn(['status' => TelegramLinkCodeStore::STATUS_AUTHORIZED, 'userId' => 999999]);
        static::getContainer()->set(TelegramLinkCodeStore::class, $store);

        $this->setNonceCookie($client, 'N');
        $client->request('GET', '/api/v1/auth/telegram/link/poll?code=C', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
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

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function setNonceCookie(KernelBrowser $client, string $nonce): void
    {
        $client->getCookieJar()->set(new BrowserCookie(
            TelegramLinkNonceCookieFactory::NAME,
            $nonce,
            (string) (time() + 300),
            TelegramLinkNonceCookieFactory::PATH,
        ));
    }

    private function findCookie(KernelBrowser $client, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
