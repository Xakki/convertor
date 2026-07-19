<?php

declare(strict_types=1);

namespace App\Service\Oauth;

use App\DTO\OAuthUserInfo;

/**
 * Контракт адаптера OAuth-провайдера. Реализации (Google/GitHub/Yandex/VK)
 * добавляются в oauth-02…04 БЕЗ изменения контроллеров: каждый адаптер —
 * самодостаточный сервис, сконфигурированный своими client_id/secret/redirect_uri,
 * и помечается тегом `app.oauth_provider`, чтобы попасть в {@see OauthProviderRegistry}.
 *
 * Этот интерфейс — точка расширения эпика; менять его в oauth-02…04 можно только
 * с ревизией карточки oauth-01.
 *
 * PKCE-провайдеры (VK ID, OAuth 2.1): {@see usesPkce()} === true. Тогда контроллер
 * генерирует `code_verifier`, кладёт его в {@see OauthStateStore} и прокидывает
 * обратно в {@see getAuthorizationUrl()} / {@see fetchUserInfo()}. Не-PKCE
 * провайдеры получают `$codeVerifier === null`.
 */
interface OauthProviderInterface
{
    /** Строковый ключ провайдера в URL/конфиге: `google`|`github`|`yandex`|`vk`. */
    public function key(): string;

    /** true → нужен PKCE code_verifier (VK ID). false → классический code-flow. */
    public function usesPkce(): bool;

    /**
     * URL авторизации провайдера, куда контроллер `start` делает 302-редирект.
     * `$state` — одноразовый CSRF-токен (уже сохранён в state-store). `$codeVerifier`
     * не null только для PKCE-провайдеров.
     */
    public function getAuthorizationUrl(string $state, ?string $codeVerifier): string;

    /**
     * Обменять callback провайдера на нормализованный профиль. `$callbackParams` —
     * сырые query-параметры callback'а ($request->query->all()): как минимум `code`,
     * а для VK — ещё `device_id`. `$codeVerifier` не null для PKCE-провайдеров.
     *
     * @param array<string, mixed> $callbackParams
     */
    public function fetchUserInfo(array $callbackParams, ?string $codeVerifier): OAuthUserInfo;
}
