<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider;

use App\DTO\OAuthUserInfo;
use App\Service\Oauth\OauthProviderInterface;
use App\Service\Oauth\Provider\Yandex\YandexOauth2Provider;
use App\Service\Oauth\Provider\Yandex\YandexResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Адаптер Yandex — тонкая обёртка над {@see YandexOauth2Provider} (кастомный
 * `AbstractProvider`, т.к. поддерживаемого league-пакета для Yandex нет, см.
 * карточку oauth-03). Не-PKCE (`usesPkce() === false`), контракт —
 * {@see OauthProviderInterface}, форма — как {@see GoogleProvider}/{@see GithubProvider}.
 *
 * EMAIL-КВИРК: userinfo-ответ Yandex не содержит top-level `email` — только
 * `default_email` (string|null) и `emails` (list<string>). Резолвинг:
 *  - `default_email` присутствует → email = default_email, emailVerified = true
 *    (Yandex отдаёt его как подтверждённый primary-адрес аккаунта);
 *  - `default_email` отсутствует, но `emails[]` не пуст → email = первый адрес
 *    списка, emailVerified = **false** (Yandex не подтверждает явным флагом,
 *    что это тот же подтверждённый primary — доверять для линковки нельзя);
 *  - email вовсе отсутствует → email = null, emailVerified = false (foundation
 *    сама уходит по synthetic-email/new-user пути, см. SocialIdentityResolver).
 *
 * `$client` — seam для тестов: юнит-тесты подставляют {@see YandexOauth2Provider},
 * сконструированный с mock-Guzzle-хендлером вместо реальной сети.
 */
final class YandexProvider implements OauthProviderInterface
{
    use BuildsAbsoluteRedirectUri;

    private readonly YandexOauth2Provider $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        private readonly string $redirectBaseUrl,
        ?YandexOauth2Provider $client = null,
    ) {
        $this->client = $client ?? new YandexOauth2Provider([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $this->buildRedirectUri($this->redirectBaseUrl, $this->key()),
        ]);
    }

    public function key(): string
    {
        return 'yandex';
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
        assert($owner instanceof YandexResourceOwner);

        [$email, $emailVerified] = $this->resolveEmail($owner);

        return new OAuthUserInfo(
            providerUid: (string) $owner->getId(),
            email: $email,
            emailVerified: $emailVerified,
            username: $owner->getLogin(),
            displayName: $owner->getDisplayName(),
        );
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveEmail(YandexResourceOwner $owner): array
    {
        $default = $owner->getDefaultEmail();
        if ($default !== null) {
            return [$default, true];
        }

        $emails = $owner->getEmails();
        if ($emails !== []) {
            // Фоллбек без явного подтверждения Yandex — НЕ считаем verified.
            return [$emails[0], false];
        }

        return [null, false];
    }
}
