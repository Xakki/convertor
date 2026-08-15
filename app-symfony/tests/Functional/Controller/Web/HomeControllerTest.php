<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET / — публичная страница загрузки/конвертации. Проверяем, что маршрут
 * отдаёт 200, рендерит Twig, отдаёт общий хедер (home-01-header-nav) со
 * ссылкой «Войти» → /login (БЕЗ popup — старая инлайн-кнопка Telegram и
 * `startLogin()` сняты вместе со старым виджетом Telegram).
 *
 * i18n (home-07): default_locale — `en` (framework.yaml), поэтому без
 * Accept-Language страница теперь EN; RU-версия проверяется явным заголовком.
 */
final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRendersSharedHeaderInRussianViaAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Конвертер');

        $html = (string) $client->getResponse()->getContent();
        // Общий хедер: «Войти» → /login (без popup), Docs, Bot, БЕЗ Admin (не ROLE_ADMIN).
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('>Войти<', $html);
        self::assertStringContainsString('href="/api/doc"', $html);
        self::assertStringContainsString('t.me/', $html);
        // Старый инлайн-флоу Telegram снят целиком (home-01-header-nav): ни
        // одного вызова/определения startLogin() на странице не остаётся.
        self::assertStringNotContainsString('startLogin(', $html);
        self::assertStringNotContainsString('data-telegram-login', $html);
        self::assertStringNotContainsString('telegram-widget.js', $html);
    }

    public function testHomePageDefaultsToEnglishWithoutAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'File Converter');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('>Log in<', $html);
        self::assertStringNotContainsString('>Войти<', $html);
        self::assertStringNotContainsString('startLogin(', $html);
    }

    public function testHomePageRendersPollMessageWithConfiguredClaimTimeoutInEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $timeoutMinutes = self::getContainer()->getParameter('app.worker_claim_timeout_minutes');
        self::assertSame(60, $timeoutMinutes);
        self::assertStringContainsString("server may take up to {$timeoutMinutes} minutes", (string) $client->getResponse()->getContent());
    }

    public function testHomePageRendersPollMessageWithConfiguredClaimTimeoutInRussian(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();

        $timeoutMinutes = self::getContainer()->getParameter('app.worker_claim_timeout_minutes');
        self::assertSame(60, $timeoutMinutes);
        self::assertStringContainsString("сервер может обрабатывать её ещё до {$timeoutMinutes} мин.", (string) $client->getResponse()->getContent());
    }

    /**
     * home-09-seo-conversion-pages: дропдаун «Conversions» в общем хедере —
     * курируемый подсписок пар (App\Service\Conversion\CuratedConversionPairs),
     * НЕ полная матрица. Проверяем, что рендерится интерактивный дропдаун
     * (не старая неинтерактивная заглушка) со ссылками на /convert/{from}-to-{to}.
     */
    public function testHomePageHeaderHasInteractiveConversionsDropdown(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nav-conversions"', $html);
        self::assertStringContainsString('data-testid="nav-conversions-menu"', $html);
        self::assertStringContainsString('href="/convert/csv-to-json"', $html);
        self::assertStringContainsString('href="/convert/pdf-to-docx"', $html);
        // Заглушка home-01 снята целиком.
        self::assertStringNotContainsString('conversions_soon', $html);
    }
}
