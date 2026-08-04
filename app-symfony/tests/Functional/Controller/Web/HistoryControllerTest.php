<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /history — страница «Мои конвертации» (CNV-61). Открытая оболочка
 * (как `/` и `/dashboard`): анонимный GET отдаёт 200; auth клиентский.
 */
final class HistoryControllerTest extends WebTestCase
{
    public function testHistoryPageRendersHeadingInRussianViaAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/history', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Мои конвертации');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Мои конвертации', $html);
        self::assertStringContainsString('x-data="historyApp()"', $html);
        self::assertStringContainsString('function historyApp()', $html);
        self::assertStringContainsString('href="/login"', $html);
    }

    public function testHistoryPageDefaultsToEnglishWithoutAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'My conversions');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('My conversions', $html);
        self::assertStringContainsString('No conversions yet.', $html);
    }

    public function testHomeHasHistoryLinkNearConvertAndNoHistoryListBlock(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-testid="home-history-link"', $html);
        self::assertStringContainsString('href="/history"', $html);
        self::assertStringContainsString('>Мои конвертации<', $html);
        self::assertStringContainsString('>Конвертировать<', $html);

        // Блок списка истории снят с главной (секция живёт на /history).
        self::assertStringNotContainsString('home.history_heading_guest', $html);
        self::assertStringNotContainsString('История на этом устройстве', $html);
        self::assertStringNotContainsString('Видна только в этом браузере благодаря cookie', $html);
    }
}
