<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /dashboard — личный кабинет (view-only, dashboard-page-01). Открытая
 * оболочка (см. App\Controller\Web\DashboardController) — auth целиком
 * клиентский, поэтому анонимный GET ДОЛЖЕН отдавать 200 (как и `/`).
 *
 * i18n: default_locale — `en` (framework.yaml), поэтому без Accept-Language
 * страница EN; RU-версия проверяется явным заголовком (см. HomeControllerTest).
 */
final class DashboardControllerTest extends WebTestCase
{
    public function testDashboardPageRendersSharedHeaderInRussianViaAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Личный кабинет');

        $html = (string) $client->getResponse()->getContent();
        // Общий хедер (partials/_header.html.twig) подключён как и на `/`.
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('t.me/', $html);
        // Заголовок страницы и переведённые ключевые строки кабинета.
        self::assertStringContainsString('Личный кабинет', $html);
        self::assertStringContainsString('Войдите, чтобы видеть данные аккаунта', $html);
        self::assertStringContainsString('Пока нет конвертаций.', $html);
    }

    public function testDashboardPageDefaultsToEnglishWithoutAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Dashboard');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('Sign in to see your account details', $html);
        self::assertStringContainsString('No conversions yet.', $html);
    }

    /**
     * Каркас Alpine-компонента dashboardApp() и модалка предпросмотра
     * (переиспользованный partials/_converter_preview_modal.html.twig,
     * ВНУТРИ x-data — см. комментарий в шаблоне) присутствуют на странице.
     */
    public function testDashboardPageWiresUpDashboardAppAndReusesPreviewModal(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('x-data="dashboardApp()"', $html);
        self::assertStringContainsString('function dashboardApp()', $html);
        self::assertStringContainsString('preview.open', $html);
    }

    /**
     * Ссылка на /dashboard в общем хедере видна только залогиненным
     * (x-show="loggedIn"), поэтому проверяем НЕ на анонимном GET /dashboard,
     * а что сам общий хедер (partials/_header.html.twig) остался рабочим на
     * `/` после правки (nav.dashboard добавлен внутрь того же блока).
     */
    public function testHomePageStillRendersAfterHeaderDashboardLinkAdded(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/dashboard"', $html);
    }
}
