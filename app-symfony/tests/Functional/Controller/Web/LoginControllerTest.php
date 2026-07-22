<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /login — served-страница соцвхода (oauth-05). Проверяем анонимный
 * доступ, наличие ссылок на start-эндпоинт каждого провайдера (по видимой
 * для локали подмножеству, home-08), переиспользованную кнопку Telegram и
 * разбор `?oauth_error`.
 *
 * i18n (home-07): default_locale — `en` (framework.yaml), поэтому RU-текст
 * проверяется явным заголовком Accept-Language.
 *
 * home-08: RU → только Yandex+VK; любая другая локаль → Google+GitHub+
 * Telegram (App\Service\Auth\LoginProviderVisibility). Фильтрация чисто
 * визуальная — testHiddenProviderStartUrlStillRedirectsUnderRuLocale ниже
 * проверяет, что прямой переход по start-URL скрытого для локали провайдера
 * (Google под RU) продолжает работать без искусственного 403/404.
 */
final class LoginControllerTest extends WebTestCase
{
    public function testLoginPageRendersOnlyYandexAndVkForRuLocale(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Вход');

        $html = (string) $client->getResponse()->getContent();
        foreach (['yandex', 'vk'] as $provider) {
            self::assertStringContainsString('/api/v1/auth/oauth/' . $provider . '/start', $html);
        }
        foreach (['google', 'github'] as $provider) {
            self::assertStringNotContainsString('/api/v1/auth/oauth/' . $provider . '/start', $html);
        }

        // Telegram скрыт для RU (home-08) — кнопка не рендерится (JS-компонент
        // loginPage() в <script> присутствует всегда, т.к. на нём же держится
        // x-data оболочки с баннером authError, но сама кнопка/разделитель — нет).
        self::assertStringNotContainsString('Войти через Telegram', $html);
        self::assertStringNotContainsString('@click="startLogin()"', $html);

        // Общий хедер (home-01-header-nav) переиспользован и на /login — Docs.
        self::assertStringContainsString('href="/api/doc"', $html);

        self::assertStringNotContainsString('data-testid="oauth-error"', $html);
    }

    public function testLoginPageRendersGoogleGithubTelegramForNonRuLocale(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        foreach (['google', 'github'] as $provider) {
            self::assertStringContainsString('/api/v1/auth/oauth/' . $provider . '/start', $html);
        }
        foreach (['yandex', 'vk'] as $provider) {
            self::assertStringNotContainsString('/api/v1/auth/oauth/' . $provider . '/start', $html);
        }

        // Telegram — переиспользован тот же magic-link флоу, что на `/`.
        self::assertStringContainsString('startLogin()', $html);
        self::assertStringContainsString('/api/v1/auth/telegram/start', $html);
    }

    public function testLoginPageRendersOauthErrorBanner(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login?oauth_error=state', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="oauth-error"', $html);
        self::assertStringContainsString('Сессия входа истекла или недействительна', $html);
    }

    public function testLoginPageRendersFallbackForUnknownOauthErrorReason(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login?oauth_error=totally_unknown_reason', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="oauth-error"', $html);
        self::assertStringContainsString('Не удалось войти. Попробуйте ещё раз.', $html);
    }

    /**
     * Фильтрация в home-08 — только рендер `/login`, НЕ access-контроль:
     * `/api/v1/auth/oauth/google/start` (Google скрыт для RU в шаблоне)
     * продолжает 302-редиректить на authorize-URL провайдера при прямом
     * переходе, как и без RU-локали — маршрут/реестр не тронуты.
     */
    public function testHiddenProviderStartUrlStillRedirectsUnderRuLocale(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/auth/oauth/google/start', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('accounts.google.com', $location);
    }

    public function testLoginPageDefaultsToEnglishWithoutAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Sign in');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Log in with Google', $html);
        self::assertStringNotContainsString('Войти через Google', $html);
    }
}
