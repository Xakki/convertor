<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Oauth;

use App\Service\Oauth\Provider\VkProvider;
use App\Service\Oauth\Provider\YandexProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Регрессия на инцидент 2026-07-19: `App\:`-ресурс в services.yaml (без exclude
 * для Yandex/VK transport-классов) регистрировал `YandexOauth2Provider`/
 * `VkIdOauth2Provider` как autowireable-сервисы — Symfony инжектил СВЕЖИЙ
 * `new YandexOauth2Provider()` (БЕЗ clientId/redirectUri) в nullable
 * `$client`-seam {@see YandexProvider}/{@see VkProvider} ВМЕСТО `null`, что
 * молча ломало `?? new ...([...])` fallback в их конструкторах: `client_id` и
 * `redirect_uri` пропадали из authorize-URL целиком (не относительными —
 * ОТСУТСТВОВАЛИ, `http_build_query` дропает null-значения). Google/GitHub эту
 * ловушку избегали чисто случайно — их `$client` типизирован vendor-классом
 * `League\...`, для которого сервиса в контейнере вообще нет.
 *
 * Юнит-тесты (VkProviderTest/YandexProviderTest) поймать это НЕ могут — они
 * конструируют провайдер напрямую, В ОБХОД контейнера. Только тест через
 * РЕАЛЬНЫЙ DI (bootKernel + getContainer()->get()) ловит autowiring-баг
 * такого рода — см. exclude-блок в services.yaml (fix).
 */
#[Group('integration')]
final class OauthProviderDiWiringTest extends KernelTestCase
{
    public function testVkProviderResolvedFromContainerHasAbsoluteRedirectUri(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(VkProvider::class);

        $url   = $provider->getAuthorizationUrl('STATE123', str_repeat('a', 43));
        $query = $this->query($url);

        self::assertArrayHasKey('client_id', $query, 'client_id пропал — DI заинжектил пустой $client-seam');
        self::assertArrayHasKey('redirect_uri', $query, 'redirect_uri пропал — DI заинжектил пустой $client-seam');
        self::assertStringStartsWith('https://', $query['redirect_uri'] ?? '', 'redirect_uri должен быть абсолютным (APP_URL)');
    }

    public function testYandexProviderResolvedFromContainerHasAbsoluteRedirectUri(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(YandexProvider::class);

        $url   = $provider->getAuthorizationUrl('STATE123', null);
        $query = $this->query($url);

        self::assertArrayHasKey('client_id', $query, 'client_id пропал — DI заинжектил пустой $client-seam');
        self::assertArrayHasKey('redirect_uri', $query, 'redirect_uri пропал — DI заинжектил пустой $client-seam');
        self::assertStringStartsWith('https://', $query['redirect_uri'] ?? '', 'redirect_uri должен быть абсолютным (APP_URL)');
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
