<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Auth\TelegramBotClient;
use App\Service\Billing\BalanceService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Payment API (CNV-28 slice 6): packs, topup invoice link, balance history.
 */
final class PaymentControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->toRemove) as $entity) {
                if ($entity instanceof User) {
                    $em->createQuery('DELETE FROM App\Entity\BalanceTransaction bt WHERE bt.user = :user')
                        ->setParameter('user', $entity)
                        ->execute();
                    $em->createQuery('DELETE FROM App\Entity\Payment p WHERE p.user = :user')
                        ->setParameter('user', $entity)
                        ->execute();
                }

                $fresh = $em->find($entity::class, $entity->getId());
                if ($fresh !== null) {
                    $em->remove($fresh);
                }
            }
            $em->flush();
        }

        parent::tearDown();
    }

    public function testPacksReturnsConfiguredPresets(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser(withTelegram: true);
        $token  = $this->jwtFor($user);

        $client->request('GET', '/api/v1/payment/packs', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('packs', $data);
        self::assertNotEmpty($data['packs']);

        $first = $data['packs'][0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('usd_cents', $first);
        self::assertArrayHasKey('stars', $first);
    }

    public function testTopUpCreatesInvoiceLink(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser(withTelegram: true);
        $token  = $this->jwtFor($user);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('createInvoiceLink')
            ->willReturn(['ok' => true, 'result' => 'https://t.me/$invoice123']);
        static::getContainer()->set(TelegramBotClient::class, $bot);

        $client->request(
            'POST',
            '/api/v1/payment/topup',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode(['pack' => 'pack_100'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('https://t.me/$invoice123', $data['invoice_link']);
        self::assertSame('pack_100', $data['pack']);
        self::assertSame(100, $data['usd_cents']);
        self::assertSame(100, $data['stars']);
    }

    public function testTopUpAliasTelegramStarsPathWorks(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser(withTelegram: true);
        $token  = $this->jwtFor($user);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('createInvoiceLink')
            ->willReturn(['ok' => true, 'result' => 'https://t.me/$alias']);
        static::getContainer()->set(TelegramBotClient::class, $bot);

        $client->request(
            'POST',
            '/api/v1/payment/telegram-stars',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode(['pack' => 'pack_500'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('pack_500', $data['pack']);
        self::assertSame(500, $data['usd_cents']);
    }

    public function testTopUpUnknownPackReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser(withTelegram: true);
        $token  = $this->jwtFor($user);

        $client->request(
            'POST',
            '/api/v1/payment/topup',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode(['pack' => 'pack_missing'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unknown_pack', $data['error']);
    }

    public function testTopUpWithoutTelegramLinkReturns403(): void
    {
        $client = static::createClient();
        $user   = $this->persistUser(withTelegram: false);
        $token  = $this->jwtFor($user);

        $client->request(
            'POST',
            '/api/v1/payment/topup',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode(['pack' => 'pack_100'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('telegram_link_required', $data['error']);
    }

    public function testTopUpForbiddenForGuestCookie(): void
    {
        $client = static::createClient();
        $guest  = $this->persistGuest();

        $tokens = static::getContainer()->get(GuestTokenService::class);
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                GuestCookieFactory::NAME,
                $tokens->sign((string) $guest->getGuestId()),
            ),
        );

        $client->request(
            'POST',
            '/api/v1/payment/topup',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['pack' => 'pack_100'], JSON_THROW_ON_ERROR),
        );

        // GuestAuthenticator не покрывает /payment/* — без Bearer → 401 (не 403).
        self::assertResponseStatusCodeSame(401);
    }

    public function testHistoryReturnsRecentBalanceTransactions(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $user   = $this->persistUser(withTelegram: true);
        $token  = $this->jwtFor($user);

        $balance = static::getContainer()->get(BalanceService::class);
        $balance->credit($user, 100, BalanceTransactionSource::Payment, 'charge-test-1');
        $balance->debit($user, 5, BalanceTransactionSource::Conversion, 'conv-1');
        $em->refresh($user);

        $client->request('GET', '/api/v1/payment/history', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('items', $data);
        self::assertGreaterThanOrEqual(2, \count($data['items']));

        $item = $data['items'][0];
        self::assertArrayHasKey('amount_cents', $item);
        self::assertArrayHasKey('type', $item);
        self::assertArrayHasKey('source', $item);
        self::assertArrayHasKey('ref_id', $item);
        self::assertArrayHasKey('created_at', $item);
    }

    private function persistUser(bool $withTelegram): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        if ($withTelegram) {
            $user->setTelegramId((string) random_int(10_000_000, 99_999_999));
        }
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function persistGuest(): User
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = static::getContainer()->get(GuestTokenService::class);
        $guest  = (new User())->setIsGuest(true)->setGuestId($tokens->generateGuestId());
        $em->persist($guest);
        $em->flush();
        $this->toRemove[] = $guest;

        return $guest;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
