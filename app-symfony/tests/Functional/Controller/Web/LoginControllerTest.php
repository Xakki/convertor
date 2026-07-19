<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /login — served-страница соцвхода (oauth-05). Проверяем анонимный
 * доступ, наличие ссылок на start-эндпоинт каждого провайдера, переиспользо-
 * ванную кнопку Telegram и разбор `?oauth_error`.
 */
final class LoginControllerTest extends WebTestCase
{
    public function testLoginPageRendersProviderButtonsAndTelegramButton(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Вход');

        $html = (string) $client->getResponse()->getContent();
        foreach (['google', 'github', 'yandex', 'vk'] as $provider) {
            self::assertStringContainsString('/api/v1/auth/oauth/' . $provider . '/start', $html);
        }

        // Telegram — переиспользован тот же magic-link флоу, что на `/`.
        self::assertStringContainsString('startLogin()', $html);
        self::assertStringContainsString('Войти через Telegram', $html);
        self::assertStringContainsString('/api/v1/auth/telegram/start', $html);

        self::assertStringNotContainsString('data-testid="oauth-error"', $html);
    }

    public function testLoginPageRendersOauthErrorBanner(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login?oauth_error=state');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="oauth-error"', $html);
        self::assertStringContainsString('Сессия входа истекла или недействительна', $html);
    }

    public function testLoginPageRendersFallbackForUnknownOauthErrorReason(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login?oauth_error=totally_unknown_reason');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="oauth-error"', $html);
        self::assertStringContainsString('Не удалось войти. Попробуйте ещё раз.', $html);
    }
}
