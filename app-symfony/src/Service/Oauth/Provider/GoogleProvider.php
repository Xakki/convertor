<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider;

use App\DTO\OAuthUserInfo;
use App\Service\Oauth\OauthProviderInterface;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Адаптер Google — тонкая обёртка над `league/oauth2-google`. Не-PKCE
 * (`usesPkce() === false`), контракт — {@see OauthProviderInterface}.
 *
 * Google userinfo-эндпоинт (OpenID Connect) отдаёт `email` + булевый
 * `email_verified` прямо в ответе — маппинг в {@see OAuthUserInfo} прямой, без
 * дополнительных запросов (в отличие от GitHub, см. {@see GithubProvider}).
 * `email_verified` отсутствует — считаем email НЕ верифицированным (fail-closed):
 * Workspace-аккаунты, провизированные админом, могут иметь `email_verified=false`,
 * поэтому отсутствие claim нельзя трактовать как подтверждение.
 *
 * `$client` — seam для тестов: юнит-тесты подставляют `Google`, сконструированный
 * с mock-Guzzle-хендлером вместо реальной сети.
 */
final class GoogleProvider implements OauthProviderInterface
{
    use BuildsAbsoluteRedirectUri;

    private readonly Google $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        private readonly string $redirectBaseUrl,
        ?Google $client = null,
    ) {
        $this->client = $client ?? new Google([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $this->buildRedirectUri($this->redirectBaseUrl, $this->key()),
        ]);
    }

    public function key(): string
    {
        return 'google';
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(string $state, ?string $codeVerifier): string
    {
        return $this->client->getAuthorizationUrl(['state' => $state]);
    }

    public function fetchUserInfo(array $callbackParams, ?string $codeVerifier): OAuthUserInfo
    {
        $code = is_string($callbackParams['code'] ?? null) ? $callbackParams['code'] : '';

        $token = $this->client->getAccessToken('authorization_code', ['code' => $code]);
        assert($token instanceof AccessToken);

        $owner = $this->client->getResourceOwner($token);
        assert($owner instanceof GoogleUser);

        // Fail-closed: emailVerified гейтит account-linking по email в SocialIdentityResolver,
        // отсутствие claim НЕ считаем подтверждением — доверяем только явному true.
        $email         = $owner->getEmail();
        $emailVerified = $email !== null && $owner->getEmailVerified() === true;

        return new OAuthUserInfo(
            providerUid: (string) $owner->getId(),
            email: $email,
            emailVerified: $emailVerified,
            username: null,
            displayName: $owner->getName(),
        );
    }
}
