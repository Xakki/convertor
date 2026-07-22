<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /privacy и GET /terms (home-06-legal-docs) — доступны анонимно, отдают
 * контент на EN (default) и RU (по Accept-Language, i18n-фундамент home-07).
 */
final class LegalControllerTest extends WebTestCase
{
    public function testPrivacyPageIsPubliclyAccessibleInEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Privacy Policy');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('guest_id', $html);
        self::assertStringNotContainsString('{{', $html);
    }

    public function testPrivacyPageIsPubliclyAccessibleInRussian(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Политика конфиденциальности');
    }

    public function testTermsPageIsPubliclyAccessibleInEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Terms of Use');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('{{', $html);
    }

    public function testTermsPageIsPubliclyAccessibleInRussian(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Условия использования');
    }

    public function testHomePageFooterLinksToLegalPages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/privacy"', $html);
        self::assertStringContainsString('href="/terms"', $html);
    }

    public function testCookieConsentBannerRendersOnHomePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="cookie-consent-banner"', $html);
        self::assertStringContainsString('cookieConsent()', $html);
    }
}
