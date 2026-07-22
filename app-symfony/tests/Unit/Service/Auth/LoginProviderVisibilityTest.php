<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\LoginProviderVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тест правила видимости кнопок логина по локали (home-08): `ru` →
 * только Yandex+VK; любая другая локаль (включая пустую строку, неизвестный
 * код языка) → Google+GitHub+Telegram (fail-safe дефолт).
 */
final class LoginProviderVisibilityTest extends TestCase
{
    public function testRuLocaleShowsOnlyYandexAndVk(): void
    {
        $visibility = new LoginProviderVisibility();

        self::assertSame(['yandex', 'vk'], $visibility->visibleFor('ru'));
    }

    #[DataProvider('nonRuLocaleProvider')]
    public function testNonRuLocaleShowsGoogleGithubTelegram(string $locale): void
    {
        $visibility = new LoginProviderVisibility();

        self::assertSame(['google', 'github', 'telegram'], $visibility->visibleFor($locale));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonRuLocaleProvider(): iterable
    {
        yield 'en' => ['en'];
        yield 'empty string' => [''];
        yield 'unknown locale code' => ['xx'];
        yield 'ru-like but not exact match' => ['ru_RU'];
    }
}
