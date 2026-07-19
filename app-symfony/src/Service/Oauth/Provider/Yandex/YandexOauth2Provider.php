<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider\Yandex;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * Кастомная реализация `league/oauth2-client` `AbstractProvider` для Yandex —
 * у Yandex нет поддерживаемого готового league-пакета (карточка oauth-03,
 * `rakeev/oauth2-yandex` не обновлялся с 2018 — используется только как
 * референс при написании, НЕ как зависимость).
 *
 * Endpoints (см. https://yandex.ru/dev/id/doc/ru/):
 *  - authorize: https://oauth.yandex.ru/authorize
 *  - token:     https://oauth.yandex.ru/token
 *  - userinfo:  https://login.yandex.ru/info?format=json
 *
 * Ключевое отличие от Bearer-провайдеров (Google/GitHub): userinfo-эндпоинт
 * Yandex ожидает заголовок `Authorization: OAuth <token>` — НЕ `Bearer`
 * (RFC 6750). Поэтому `getAuthorizationHeaders()` переопределён вручную вместо
 * подключения `League\OAuth2\Client\Tool\BearerAuthorizationTrait`.
 *
 * Маппинг сырого userinfo-ответа в {@see \App\DTO\OAuthUserInfo} (в частности
 * email-квирк `default_email`/`emails[]`) — в {@see \App\Service\Oauth\Provider\YandexProvider},
 * этот класс — только транспорт (сырые запросы к Yandex).
 */
class YandexOauth2Provider extends AbstractProvider
{
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://oauth.yandex.ru/authorize';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://oauth.yandex.ru/token';
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return 'https://login.yandex.ru/info?format=json';
    }

    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        return ['login:email'];
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * Yandex требует схему `OAuth <token>` вместо стандартного `Bearer` — см.
     * class-level PHPDoc. Переопределяет пустую реализацию базового класса
     * (там `getAuthorizationHeaders()` по умолчанию возвращает `[]`, схему
     * задаёт только подключаемый провайдером трейт).
     *
     * @param mixed $token
     *
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        return ['Authorization' => 'OAuth ' . $token];
    }

    /**
     * @param array<string, mixed>|string $data
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (! is_array($data) || ! isset($data['error'])) {
            return;
        }

        $error       = is_string($data['error']) ? $data['error'] : 'yandex_oauth_error';
        $description = is_string($data['error_description'] ?? null) ? $data['error_description'] : $error;

        throw new IdentityProviderException($description, $response->getStatusCode(), $data);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function createResourceOwner(array $response, AccessToken $token): YandexResourceOwner
    {
        return new YandexResourceOwner($response);
    }
}
