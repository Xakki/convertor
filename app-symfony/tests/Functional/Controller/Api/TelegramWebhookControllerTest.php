<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use App\Service\Auth\TelegramBotClient;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramUserProvisioner;
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
            ->with(999, self::stringContains('Convertor'));
        $container->set(TelegramBotClient::class, $bot);

        // Допускаем суффикс "@bot_username" — так шлёт клиент Telegram в группах.
        $update = ['message' => ['chat' => ['id' => 999], 'text' => '/help@anyconvertor_bot']];
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

    public function testCallbackQueryAuthorizesCodeAndSendsMagicLink(): void
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

        // authorize возвращает сырой linkSecret; он должен попасть в magic-ссылку
        // (query `s`) и НИКУДА больше (только в чат авторизовавшего).
        $store = $this->createMock(TelegramLoginCodeStore::class);
        $store->expects(self::once())->method('authorize')->with('ABC', 321)->willReturn('LINK-SECRET-XYZ');
        $container->set(TelegramLoginCodeStore::class, $store);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('answerCallbackQuery')
            ->with('cbid-1', self::stringContains('Готово'));
        // Magic-ссылка уходит В ЧАТ авторизовавшего (кнопка с url: code + linkSecret).
        $bot->expects(self::once())
            ->method('sendMessage')
            ->with(
                777,
                self::anything(),
                self::callback(static function (?array $markup): bool {
                    $btn = $markup['inline_keyboard'][0][0] ?? [];
                    $url = $btn['url']                      ?? '';

                    return str_contains($url, '/api/v1/auth/telegram/callback?code=ABC')
                        && str_contains($url, 's=LINK-SECRET-XYZ');
                }),
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

        // authorize вернул null → код истёк ИЛИ уже не pending (status-guard):
        // magic-ссылка НЕ шлётся (нет linkSecret), пользователю говорим начать заново.
        $store = $this->createStub(TelegramLoginCodeStore::class);
        $store->method('authorize')->willReturn(null);
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
