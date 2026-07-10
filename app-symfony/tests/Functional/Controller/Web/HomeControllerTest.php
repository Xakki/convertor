<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET / — публичная страница загрузки/конвертации. Проверяем, что маршрут
 * отдаёт 200, рендерит Twig и что на странице есть magic-link кнопка входа
 * (виджет Telegram снят — вход инициируется через POST /auth/telegram/start).
 */
final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRendersWithLoginButton(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Конвертер');

        $html = (string) $client->getResponse()->getContent();
        // Кнопка magic-link входа + отсутствие снятого виджета Telegram.
        self::assertStringContainsString('startLogin()', $html);
        self::assertStringContainsString('Войти через Telegram', $html);
        self::assertStringNotContainsString('data-telegram-login', $html);
        self::assertStringNotContainsString('telegram-widget.js', $html);
    }
}
