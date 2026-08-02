<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramBotClient;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramUserProvisioner;
use App\Service\Billing\BalanceService;
use App\Service\Billing\PaymentTopUpService;
use App\Service\Billing\TopUpPackRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты POST /api/v1/telegram/webhook.
 *
 * Секрет-заголовок = TELEGRAM_WEBHOOK_SECRET из .env.test. TelegramBotClient,
 * CodeStore и Provisioner мокируются в контейнере (без сети/KeyDB/БД).
 */
final class TelegramWebhookControllerTest extends WebTestCase
{
    private const URL    = '/api/v1/telegram/webhook';
    private const SECRET = 'test-telegram-webhook-secret';

    public function testRejectsMissingSecret(): void
    {
        $client = static::createClient();
        $client->request('POST', self::URL, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRejectsWrongSecret(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Telegram-Bot-Api-Secret-Token' => 'wrong'],
            '{}',
        );
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testStartCommandRendersLoginButton(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::anything(),
                self::callback(static function (?array $markup): bool {
                    $btn = $markup['inline_keyboard'][0][0] ?? [];

                    return ($btn['text'] ?? null)          === 'Войти'
                        && ($btn['callback_data'] ?? null) === 'login:ABC';
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => ['chat' => ['id' => 999], 'text' => '/start ABC']];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testHelpCommandSendsShortInstructions(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::callback(static function (string $text): bool {
                    return str_contains($text, 'Convertor')
                        && str_contains($text, 'https://convertor.test/login')
                        && str_contains($text, 'Войти через Telegram');
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        // Допускаем суффикс "@bot_username" — так шлёт клиент Telegram в группах.
        $update = ['message' => ['chat' => ['id' => 999], 'text' => '/help@anyconvertor_bot']];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testBareStartContainsLoginUrlAndNextStep(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::callback(static function (string $text): bool {
                    return str_contains($text, 'https://convertor.test/login')
                        && str_contains($text, 'Войти через Telegram')
                        && ! str_contains($text, 'ссылку с сайта');
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => ['chat' => ['id' => 999], 'text' => '/start']];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testBalanceCommandForLinkedUserShowsBalanceAndRates(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');
        $user->setBalanceCents(250);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::once())->method('getBalanceCents')->with($user)->willReturn(250);
        $balance->expects(self::exactly(2))
            ->method('getPayPerUseCostCents')
            ->willReturnCallback(static fn (bool $isAi): int => $isAi ? 15 : 5);
        $container->set(BalanceService::class, $balance);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::callback(static function (string $text): bool {
                    return str_contains($text, '250 ¢ ($2.50)')
                        && str_contains($text, '5 ¢ ($0.05)')
                        && str_contains($text, '15 ¢ ($0.15)')
                        && str_contains($text, '/topup');
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/balance',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testBalanceCommandForUnlinkedUserShowsLoginUrl(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn(null);
        $container->set(UserRepository::class, $users);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('https://convertor.test/login'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/balance',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testBalanceCommandWithoutFromStillReplies(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('https://convertor.test/login'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'text' => '/balance',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testBalanceCommandWithTrailingSpaceShowsBalance(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');
        $user->setBalanceCents(100);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::once())->method('getBalanceCents')->with($user)->willReturn(100);
        $balance->method('getPayPerUseCostCents')->willReturn(5);
        $container->set(BalanceService::class, $balance);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('100 ¢'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/balance ',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandWithoutArgsShowsPackKeyboard(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::stringContains('пакет'),
                self::callback(static function (?array $markup): bool {
                    $rows = $markup['inline_keyboard'] ?? [];
                    $callbacks = [];
                    foreach ($rows as $row) {
                        foreach ($row as $btn) {
                            $callbacks[] = $btn['callback_data'] ?? null;
                        }
                    }

                    return in_array('topup:pack_100', $callbacks, true)
                        && in_array('topup:pack_500', $callbacks, true)
                        && in_array('topup:pack_2000', $callbacks, true);
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandWithKnownPackSendsInvoice(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())->method('sendInvoiceToChat')->with($user, 'pack_100', 999);
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::never())->method('sendMessage');
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup pack_100',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandWithUnknownPackShowsError(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::never())->method('sendInvoiceToChat');
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::callback(static function (string $text): bool {
                    return str_contains($text, 'Неизвестный пакет')
                        && str_contains($text, 'pack_100')
                        && str_contains($text, 'pack_500')
                        && str_contains($text, 'pack_2000')
                        && str_contains($text, '/topup 5');
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup no_such_pack',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandForUnlinkedUserShowsLoginUrlWithoutInvoice(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn(null);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::never())->method('sendInvoiceToChat');
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('https://convertor.test/login'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandWithStarsAmountSendsCustomInvoice(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())->method('sendInvoiceForStars')->with($user, 5, 999);
        $topUp->expects(self::never())->method('sendInvoiceToChat');
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::never())->method('sendMessage');
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup 5',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandBelowMinStarsShowsError(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::never())->method('sendInvoiceForStars');
        $topUp->expects(self::never())->method('sendInvoiceToChat');
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('Минимум 5 ⭐'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup 4',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCommandWithStarsForUnlinkedUserShowsLoginUrlWithoutInvoice(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn(null);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::never())->method('sendInvoiceForStars');
        $topUp->expects(self::never())->method('sendInvoiceToChat');
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('https://convertor.test/login'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/topup 10',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTopupCallbackSendsInvoiceAndDoesNotBreakLogin(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())->method('sendInvoiceToChat')->with($user, 'pack_100', 777);
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())->method('answerCallbackQuery')->with('cbid-topup');
        $bot->expects(self::never())->method('sendMessage');
        $container->set(TelegramBotClient::class, $bot);

        // login-provisioner не должен вызываться на topup-callback.
        $provisioner = $this->createMock(TelegramUserProvisioner::class);
        $provisioner->expects(self::never())->method('findOrCreateUser');
        $container->set(TelegramUserProvisioner::class, $provisioner);

        $update = ['callback_query' => [
            'id'      => 'cbid-topup',
            'data'    => 'topup:pack_100',
            'from'    => ['id' => 12345],
            'message' => ['chat' => ['id' => 777]],
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testConvertCommandSendsAppLink(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(999, self::stringContains('http'));
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => ['chat' => ['id' => 999], 'text' => '/convert']];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCallbackQueryAuthorizesCodeAndTellsUserToReturn(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(321);

        $provisioner = $this->createMock(TelegramUserProvisioner::class);
        $provisioner->expects(self::once())
            ->method('findOrCreateUser')
            ->with('12345', 'ivan', 'Иван')
            ->willReturn($user);
        $container->set(TelegramUserProvisioner::class, $provisioner);

        $store = $this->createMock(TelegramLoginCodeStore::class);
        $store->expects(self::once())->method('authorize')->with('ABC', 321)->willReturn(true);
        $container->set(TelegramLoginCodeStore::class, $store);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('answerCallbackQuery')
            ->with('cbid-1', self::stringContains('Вернитесь'));
        // Без magic-URL: только текст «вернитесь в браузер».
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                777,
                'Авторизация успешна. Вернитесь в браузер.',
                null,
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['callback_query' => [
            'id'      => 'cbid-1',
            'data'    => 'login:ABC',
            'from'    => ['id' => 12345, 'username' => 'ivan', 'first_name' => 'Иван'],
            'message' => ['chat' => ['id' => 777]],
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCallbackQueryOnExpiredCodeTellsUser(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $provisioner = $this->createStub(TelegramUserProvisioner::class);
        $provisioner->method('findOrCreateUser')->willReturn($this->makeUser(9));
        $container->set(TelegramUserProvisioner::class, $provisioner);

        // authorize вернул false → код истёк ИЛИ уже не pending (status-guard):
        // сообщение «вернитесь» НЕ шлётся, пользователю говорим начать заново.
        $store = $this->createStub(TelegramLoginCodeStore::class);
        $store->method('authorize')->willReturn(false);
        $container->set(TelegramLoginCodeStore::class, $store);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('answerCallbackQuery')
            ->with('cbid-2', self::stringContains('истёк'));
        $bot->expects(self::never())->method('sendMessage');
        $container->set(TelegramBotClient::class, $bot);

        $update = ['callback_query' => [
            'id'   => 'cbid-2',
            'data' => 'login:GONE',
            'from' => ['id' => 9],
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPreCheckoutQueryDelegatesToTopUpService(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())
            ->method('handlePreCheckoutQuery')
            ->with('pcq-1', 'topup:9', 100, '12345');
        $container->set(PaymentTopUpService::class, $topUp);

        $update = ['pre_checkout_query' => [
            'id'              => 'pcq-1',
            'from'            => ['id' => 12345],
            'invoice_payload' => 'topup:9',
            'total_amount'    => 100,
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPayStartDeepLinkSendsInvoiceForLinkedUser(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $packs = $this->createMock(TopUpPackRegistry::class);
        $packs->expects(self::once())->method('hasPack')->with('pack_100')->willReturn(true);
        $container->set(TopUpPackRegistry::class, $packs);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())->method('sendInvoiceToChat')->with($user, 'pack_100', 999);
        $container->set(PaymentTopUpService::class, $topUp);

        $update = ['message' => [
            'chat' => ['id' => 999],
            'from' => ['id' => 12345],
            'text' => '/start pay_pack_100',
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testSuccessfulPaymentDelegatesToTopUpServiceAndNotifiesUser(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $user = $this->makeUser(55);
        $user->setTelegramId('12345');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByTelegramId')->with('12345')->willReturn($user);
        $container->set(UserRepository::class, $users);

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())
            ->method('handleSuccessfulPayment')
            ->with('topup:3', 'charge-1', 100, '12345')
            ->willReturn(true);
        $container->set(PaymentTopUpService::class, $topUp);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::once())->method('getBalanceCents')->with($user)->willReturn(350);
        $container->set(BalanceService::class, $balance);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                999,
                self::callback(static function (string $text): bool {
                    return str_contains($text, 'пополнен')
                        && str_contains($text, '350 ¢ ($3.50)');
                }),
            );
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat'               => ['id' => 999],
            'from'               => ['id' => 12345],
            'successful_payment' => [
                'invoice_payload'            => 'topup:3',
                'telegram_payment_charge_id' => 'charge-1',
                'total_amount'               => 100,
            ],
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testSuccessfulPaymentNoOpDoesNotSendSuccessMessage(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $topUp = $this->createMock(PaymentTopUpService::class);
        $topUp->expects(self::once())
            ->method('handleSuccessfulPayment')
            ->with('topup:3', 'charge-dup', 100, '12345')
            ->willReturn(false);
        $container->set(PaymentTopUpService::class, $topUp);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::never())->method('sendMessage');
        $container->set(TelegramBotClient::class, $bot);

        $update = ['message' => [
            'chat'               => ['id' => 999],
            'from'               => ['id' => 12345],
            'successful_payment' => [
                'invoice_payload'            => 'topup:3',
                'telegram_payment_charge_id' => 'charge-dup',
                'total_amount'               => 100,
            ],
        ]];
        $client->request('POST', self::URL, [], [], $this->headers(), (string) json_encode($update));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'CONTENT_TYPE'                         => 'application/json',
            'HTTP_X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ];
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
