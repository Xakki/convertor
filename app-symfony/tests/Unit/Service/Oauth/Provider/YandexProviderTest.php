<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth\Provider;

use App\Service\Oauth\InvalidOauthRedirectBaseUrlException;
use App\Service\Oauth\Provider\Yandex\YandexOauth2Provider;
use App\Service\Oauth\Provider\YandexProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Юнит-тесты YandexProvider на MOCK Guzzle-хендлере (без реальной сети): токен +
 * userinfo-ответ подставляются canned-JSON'ом через seam-конструктор ($client).
 *
 * Ключевой фокус — email-квирк карточки oauth-03: userinfo-ответ Yandex не
 * содержит top-level `email`, только `default_email`/`emails[]` (см. PHPDoc
 * {@see YandexProvider}).
 */
final class YandexProviderTest extends TestCase
{
    public function testKeyAndPkce(): void
    {
        $provider = $this->makeProvider([]);

        self::assertSame('yandex', $provider->key());
        self::assertFalse($provider->usesPkce());
    }

    public function testGetAuthorizationUrlBuildsRealRedirectUriAndScope(): void
    {
        // БЕЗ 4-го аргумента $client — конструктор сам собирает
        // YandexOauth2Provider, включая buildRedirectUri(). getAuthorizationUrl()
        // сети не требует, поэтому это честная проверка прод-пути (не seam'а).
        $provider = new YandexProvider('client-id', 'client-secret', 'https://example.test');

        $url = $provider->getAuthorizationUrl('STATE123', null);

        $parts = parse_url($url);
        self::assertSame('oauth.yandex.ru', $parts['host'] ?? null);
        self::assertSame('/authorize', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('client-id', $query['client_id'] ?? null);
        self::assertSame(
            'https://example.test/api/v1/auth/oauth/yandex/callback',
            $query['redirect_uri'] ?? null,
        );
        self::assertSame('STATE123', $query['state'] ?? null);
        self::assertSame('login:email', $query['scope'] ?? null);
    }

    public function testEmptyRedirectBaseUrlFailsLoudInsteadOfRelativeRedirectUri(): void
    {
        // Fail loud (см. 2026-07-19 fix): APP_URL пустой/относительный → сразу
        // исключение при конструировании, а не молчаливый относительный redirect_uri.
        self::expectException(InvalidOauthRedirectBaseUrlException::class);

        new YandexProvider('client-id', 'client-secret', '');
    }

    public function testFetchUserInfoMapsDefaultEmailAsVerified(): void
    {
        $capturedAuthHeader = null;
        $provider           = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'id'            => '123456',
                'login'         => 'jdoe',
                'display_name'  => 'Jane Doe',
                'default_email' => 'jane@yandex.ru',
                'emails'        => ['jane@yandex.ru', 'other@example.com'],
            ]),
        ], $capturedAuthHeader);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('123456', $info->providerUid);
        self::assertSame('jane@yandex.ru', $info->email);
        self::assertTrue($info->emailVerified);
        self::assertSame('jdoe', $info->username);
        self::assertSame('Jane Doe', $info->displayName);
        // Схема заголовка Yandex — `OAuth`, НЕ `Bearer` (ключевой квирк карточки).
        self::assertSame('OAuth AT-1', $capturedAuthHeader);
    }

    public function testFetchUserInfoFallsBackToFirstEmailWhenNoDefaultEmail(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'id'           => '111',
                'login'        => 'noprimary',
                'display_name' => 'No Primary',
                'emails'       => ['first@example.com', 'second@example.com'],
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('first@example.com', $info->email);
        // Фоллбек без default_email — Yandex явно НЕ подтверждает этот адрес
        // как primary, поэтому НЕ считаем verified (решение карточки oauth-03).
        self::assertFalse($info->emailVerified);
    }

    public function testFetchUserInfoWithNoEmailAtAllYieldsNullEmailNotInvented(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'id'           => '999',
                'login'        => 'noemail',
                'display_name' => 'No Email',
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertNull($info->email);
        self::assertFalse($info->emailVerified);
        self::assertSame('999', $info->providerUid);
        self::assertSame('noemail', $info->username);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeProvider(array $responses, ?string &$capturedAuthHeader = null): YandexProvider
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(static function (callable $handler) use (&$capturedAuthHeader) {
            return static function (RequestInterface $request, array $options) use ($handler, &$capturedAuthHeader) {
                if ($request->hasHeader('Authorization')) {
                    $capturedAuthHeader = $request->getHeaderLine('Authorization');
                }

                return $handler($request, $options);
            };
        });

        $client = new YandexOauth2Provider(
            [
                'clientId'     => 'client-id',
                'clientSecret' => 'client-secret',
                'redirectUri'  => 'https://example.test/api/v1/auth/oauth/yandex/callback',
            ],
            ['httpClient' => new Client(['handler' => $handlerStack])],
        );

        return new YandexProvider('client-id', 'client-secret', 'https://example.test', $client);
    }

    private function tokenResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'AT-1',
            'expires_in'   => 3600,
            'token_type'   => 'bearer',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function userinfoResponse(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
