<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider;

use App\DTO\OAuthUserInfo;
use App\Service\Oauth\OauthProviderInterface;
use App\Service\Oauth\Provider\Vk\VkIdOauth2Provider;
use App\Service\Oauth\Provider\Vk\VkResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Адаптер VK ID (карточка oauth-04) — тонкая обёртка над {@see VkIdOauth2Provider}
 * (кастомный `AbstractProvider`, готового league-пакета для VK ID 2.1 нет).
 * PKCE (`usesPkce() === true`) — `code_verifier` генерирует
 * {@see \App\Controller\Api\OauthController} (хранится в {@see \App\Service\Oauth\OauthStateStore}
 * между /start и /callback), этот класс его только использует.
 *
 * ДВА КВИРКА VK ID (см. карточку oauth-04):
 *  - НЕТ `client_secret` — конструктор его не принимает вовсе (в отличие от
 *    Google/GitHub/Yandex), PKCE его полностью заменяет.
 *  - `device_id` возвращается VK на callback вместе с `code` в query — НЕ
 *    известен на этапе /start, поэтому не хранится в state-store (в отличие
 *    от code_verifier), а читается прямо из `$callbackParams`
 *    ({@see fetchUserInfo()}) и прокидывается в token-обмен.
 *
 * EMAIL: VK ID не отдаёт явный verified-флаг для email в userinfo-ответе (в
 * отличие от Yandex, у которого `default_email` трактуется как подтверждённый
 * primary-адрес) — поэтому email ВСЕГДА маппится с `emailVerified = false`,
 * даже если сам адрес присутствует: доверять ему для линковки к существующему
 * User (см. `SocialIdentityResolver`) нельзя, только для отображения/create.
 * Email отсутствует → email = null (тот же случай, просто в частном виде).
 *
 * `$client` — seam для тестов: юнит-тесты подставляют {@see VkIdOauth2Provider},
 * сконструированный с mock-Guzzle-хендлером вместо реальной сети.
 */
final class VkProvider implements OauthProviderInterface
{
    use BuildsAbsoluteRedirectUri;

    private readonly VkIdOauth2Provider $client;

    public function __construct(
        string $clientId,
        private readonly string $redirectBaseUrl,
        ?VkIdOauth2Provider $client = null,
    ) {
        $this->client = $client ?? new VkIdOauth2Provider([
            'clientId'    => $clientId,
            'redirectUri' => $this->buildRedirectUri($this->redirectBaseUrl, $this->key()),
        ]);
    }

    public function key(): string
    {
        return 'vk';
    }

    public function usesPkce(): bool
    {
        return true;
    }

    public function getAuthorizationUrl(string $state, ?string $codeVerifier): string
    {
        $options = ['state' => $state];
        if ($codeVerifier !== null) {
            $options['code_verifier'] = $codeVerifier;
        }

        return $this->client->getAuthorizationUrl($options);
    }

    public function fetchUserInfo(array $callbackParams, ?string $codeVerifier): OAuthUserInfo
    {
        $code     = is_string($callbackParams['code'] ?? null) ? $callbackParams['code'] : '';
        $deviceId = is_string($callbackParams['device_id'] ?? null) ? $callbackParams['device_id'] : '';
        $state    = is_string($callbackParams['state'] ?? null) ? $callbackParams['state'] : '';

        $params = [
            'code'      => $code,
            'device_id' => $deviceId,
            'state'     => $state,
        ];
        if ($codeVerifier !== null) {
            $params['code_verifier'] = $codeVerifier;
        }

        $token = $this->client->getAccessToken('authorization_code', $params);
        assert($token instanceof AccessToken);

        $owner = $this->client->getResourceOwner($token);
        assert($owner instanceof VkResourceOwner);

        return new OAuthUserInfo(
            providerUid: (string) $owner->getId(),
            // Verified-флага для email в userinfo VK ID нет — см. class-level PHPDoc.
            email: $owner->getEmail(),
            emailVerified: false,
            username: null,
            displayName: $this->displayName($owner),
        );
    }

    private function displayName(VkResourceOwner $owner): ?string
    {
        $parts = array_filter(
            [$owner->getFirstName(), $owner->getLastName()],
            static fn (?string $part): bool => $part !== null && $part !== '',
        );

        return $parts === [] ? null : implode(' ', $parts);
    }
}
