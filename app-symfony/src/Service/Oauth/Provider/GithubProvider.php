<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider;

use App\DTO\OAuthUserInfo;
use App\Service\Oauth\OauthProviderInterface;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Адаптер GitHub — тонкая обёртка над `league/oauth2-github`. Не-PKCE
 * (`usesPkce() === false`), контракт — {@see OauthProviderInterface}.
 *
 * GitHub НЕ отдаёт verified-флаг в основном `/user`-ответе (только `email`,
 * который к тому же может быть `null`, если адрес не публичный). Поэтому
 * `fetchUserInfo` всегда делает СВОЙ отдельный запрос `GET /user/emails`
 * (scope `user:email`) и выбирает primary+verified адрес оттуда — вместо
 * встроенного fallback'а `Github::fetchResourceOwnerDetails()` (который берёт
 * первый попавшийся email без проверки verified/primary). `providerUid` —
 * числовой GitHub id (стабилен), НЕ login (login можно сменить).
 *
 * `$client` — seam для тестов: юнит-тесты подставляют `Github`, сконструированный
 * с mock-Guzzle-хендлером вместо реальной сети.
 */
final class GithubProvider implements OauthProviderInterface
{
    use BuildsAbsoluteRedirectUri;

    private const EMAILS_URL = 'https://api.github.com/user/emails';

    private readonly Github $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        private readonly string $redirectBaseUrl,
        ?Github $client = null,
    ) {
        $this->client = $client ?? new Github([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $this->buildRedirectUri($this->redirectBaseUrl, $this->key()),
        ]);
    }

    public function key(): string
    {
        return 'github';
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(string $state, ?string $codeVerifier): string
    {
        return $this->client->getAuthorizationUrl(['state' => $state, 'scope' => ['user:email']]);
    }

    public function fetchUserInfo(array $callbackParams, ?string $codeVerifier): OAuthUserInfo
    {
        $code = is_string($callbackParams['code'] ?? null) ? $callbackParams['code'] : '';

        $token = $this->client->getAccessToken('authorization_code', ['code' => $code]);
        assert($token instanceof AccessToken);

        // Свой запрос /user — НЕ через getResourceOwner()/fetchResourceOwnerDetails(),
        // чтобы не задействовать встроенный fallback-запрос /user/emails внутри
        // League\Github (он берёт первый email без проверки verified/primary):
        // verified-адрес мы резолвим сами в resolvePrimaryEmail().
        $userRequest = $this->client->getAuthenticatedRequest(
            'GET',
            $this->client->getResourceOwnerDetailsUrl($token),
            $token,
        );
        $userData = $this->client->getParsedResponse($userRequest);
        $owner    = new GithubResourceOwner(is_array($userData) ? $userData : []);
        $owner->setDomain($this->client->domain);

        [$email, $emailVerified] = $this->resolvePrimaryEmail($token);

        return new OAuthUserInfo(
            providerUid: (string) $owner->getId(),
            email: $email,
            emailVerified: $emailVerified,
            username: $owner->getNickname(),
            displayName: $owner->getName(),
        );
    }

    /**
     * `GET /user/emails` → primary+verified адрес. Нет primary → первый из списка
     * (verified по его собственному флагу). Пустой список/битый ответ → [null, false].
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolvePrimaryEmail(AccessToken $token): array
    {
        $request = $this->client->getAuthenticatedRequest('GET', self::EMAILS_URL, $token);
        $emails  = $this->client->getParsedResponse($request);
        if (! is_array($emails)) {
            return [null, false];
        }

        $primary = null;
        foreach ($emails as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true) {
                $primary = $entry;
                break;
            }
        }
        $primary ??= is_array($emails[0] ?? null) ? $emails[0] : null;

        if (! is_array($primary)) {
            return [null, false];
        }

        $email    = isset($primary['email']) && is_string($primary['email']) ? $primary['email'] : null;
        $verified = $email !== null          && ($primary['verified'] ?? false) === true;

        return [$email, $verified];
    }
}
