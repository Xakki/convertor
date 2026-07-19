<?php

declare(strict_types=1);

namespace App\Service\Oauth;

/**
 * Реестр OAuth-провайдеров: резолвит адаптер по строковому ключу (`google`…).
 * Провайдеры инжектятся тегированным итератором `app.oauth_provider` (см.
 * services.yaml) — новый провайдер (oauth-02…04) достаточно пометить тегом,
 * контроллер и реестр при этом не меняются.
 *
 * В oauth-01 конкретных провайдеров ещё нет → реестр пуст → `get()` кидает
 * {@see UnknownOauthProviderException} (контроллер отдаёт 404). Тесты кладут
 * стаб-провайдер, подменяя весь реестр в контейнере.
 */
final class OauthProviderRegistry
{
    /** @var array<string, OauthProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<OauthProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @throws UnknownOauthProviderException если провайдер не сконфигурирован
     */
    public function get(string $key): OauthProviderInterface
    {
        return $this->providers[$key] ?? throw new UnknownOauthProviderException($key);
    }
}
