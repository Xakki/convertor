<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider\Vk;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

/**
 * Кастомная реализация `league/oauth2-client` `AbstractProvider` для VK ID —
 * актуального league-пакета нет (карточка oauth-04, `yasinovsky/oauth2-vkontakte`
 * v3.0.3 использован ТОЛЬКО как референс по эндпоинтам/PKCE-механике при
 * написании, НЕ как зависимость — его завязку на `$_SESSION` не переносим:
 * state/code_verifier/device_id идут через инфраструктуру oauth-01
 * (`OauthStateStore`/KeyDB), см. PHPDoc {@see \App\Service\Oauth\Provider\VkProvider}).
 *
 * Endpoints (VK ID, OAuth 2.1):
 *  - authorize: https://id.vk.com/authorize
 *  - token:     POST https://id.vk.com/oauth2/auth
 *  - userinfo:  POST https://id.vk.com/oauth2/user_info
 *
 * Три отличия от Bearer-провайдеров (Google/GitHub) и от Yandex:
 *  1. PKCE (S256) ОБЯЗАТЕЛЕН, но `code_verifier` генерирует КОНТРОЛЛЕР
 *     ({@see \App\Controller\Api\OauthController::newCodeVerifier()}), а не
 *     сам league-клиент — встроенный PKCE-механизм `AbstractProvider`
 *     (`getPkceMethod()`) НЕ используется (он сам генерирует code_verifier при
 *     каждом `getAuthorizationUrl()` и не даёт передать готовый снаружи).
 *     Вместо этого `code_challenge` считается вручную в
 *     {@see getAuthorizationParameters()} из code_verifier, переданного опцией
 *     `code_verifier` (сам verifier в query URL НЕ попадает — вырезается,
 *     наружу уходит только его S256-хэш).
 *  2. У VK ID НЕТ `client_secret` — {@see getAccessTokenRequest()} вырезает
 *     его из параметров перед сборкой запроса (базовый класс всегда кладёт
 *     его в params, см. `AbstractProvider::getAccessToken()`).
 *  3. userinfo — POST с `access_token` в теле формы (не Bearer-заголовок и не
 *     GET) — {@see fetchResourceOwnerDetails()} переопределён целиком.
 *
 * Маппинг сырого userinfo-ответа в {@see \App\DTO\OAuthUserInfo} — в
 * {@see \App\Service\Oauth\Provider\VkProvider}, этот класс — только транспорт
 * (сырые запросы к VK ID).
 */
class VkIdOauth2Provider extends AbstractProvider
{
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://id.vk.com/authorize';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://id.vk.com/oauth2/auth';
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return 'https://id.vk.com/oauth2/user_info';
    }

    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        return ['email', 'phone', 'vkid.personal_info'];
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * PKCE S256 из ВНЕШНЕГО code_verifier (опция `code_verifier`, см. class-level
     * PHPDoc п.1). Встроенный `getPkceMethod()`-механизм базового класса не
     * используем — он бы сгенерировал СВОЙ code_verifier вместо переданного
     * контроллером.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options): array
    {
        $codeVerifier = is_string($options['code_verifier'] ?? null) ? $options['code_verifier'] : null;
        unset($options['code_verifier']);

        $options = parent::getAuthorizationParameters($options);

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $options['code_challenge']        = $this->codeChallenge($codeVerifier);
            $options['code_challenge_method'] = 'S256';
        }

        return $options;
    }

    /**
     * Вырезает `client_secret` (у VK ID его нет — PKCE полностью его заменяет,
     * см. class-level PHPDoc п.2). `device_id`/`code_verifier`/`state` в
     * `$params` уже присутствуют — их добавляет
     * {@see \App\Service\Oauth\Provider\VkProvider::fetchUserInfo()} через
     * опции `getAccessToken(..., $options)` (мержатся в params грантом раньше
     * этого хука).
     *
     * @param array<string, mixed> $params
     */
    protected function getAccessTokenRequest(array $params): RequestInterface
    {
        unset($params['client_secret']);

        return parent::getAccessTokenRequest($params);
    }

    /**
     * @param array<string, mixed>|string $data
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (! is_array($data) || ! isset($data['error'])) {
            return;
        }

        $error       = is_string($data['error']) ? $data['error'] : 'vk_oauth_error';
        $description = is_string($data['error_description'] ?? null) ? $data['error_description'] : $error;

        throw new IdentityProviderException($description, $response->getStatusCode(), $data);
    }

    /**
     * userinfo VK ID — POST с `access_token` в теле формы (см. class-level
     * PHPDoc п.3), полностью переопределяет базовую GET+Bearer реализацию.
     *
     * @return array<string, mixed>
     */
    protected function fetchResourceOwnerDetails(AccessToken $token): array
    {
        $request = $this->getRequest(self::METHOD_POST, $this->getResourceOwnerDetailsUrl($token), [
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'access_token' => $token->getToken(),
                'client_id'    => $this->clientId,
            ]),
        ]);

        $response = $this->getParsedResponse($request);
        if (! is_array($response)) {
            throw new UnexpectedValueException('Invalid response received from VK ID userinfo. Expected JSON.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function createResourceOwner(array $response, AccessToken $token): VkResourceOwner
    {
        return new VkResourceOwner($response);
    }

    private function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
