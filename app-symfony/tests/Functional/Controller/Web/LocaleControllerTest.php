<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /locale/{locale} — переключатель языка (i18n-фундамент, home-07).
 * Проверяем: ставит cookie `locale`, редиректит (Referer при том же хосте,
 * иначе `/`), и что cookie реально имеет приоритет над Accept-Language на
 * следующем запросе — см. App\EventListener\LocaleCookieListener.
 */
final class LocaleControllerTest extends WebTestCase
{
    public function testSwitchSetsCookieAndRedirectsToReferer(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/ru', server: ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('http://localhost/');

        $cookie = $client->getCookieJar()->get('locale');
        self::assertNotNull($cookie);
        self::assertSame('ru', $cookie->getValue());
    }

    public function testSwitchFallsBackToHomeWithoutReferer(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/en');

        self::assertResponseRedirects('/');
    }

    public function testSwitchIgnoresRefererFromDifferentHost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/en', server: ['HTTP_REFERER' => 'https://evil.example/steal']);

        self::assertResponseRedirects('/');
    }

    public function testUnsupportedLocaleIsRejectedByRouting(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/fr');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCookieTakesPriorityOverAcceptLanguage(): void
    {
        $client = static::createClient();
        // Явный выбор EN, затем визит с Accept-Language: ru — cookie должен победить.
        $client->request('GET', '/locale/en');
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'File Converter');
    }

    public function testSubsequentVisitWithoutAcceptLanguageUsesStoredCookie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/ru');
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Конвертер');
    }
}
