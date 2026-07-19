<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth\Provider;

use App\Service\Oauth\InvalidOauthRedirectBaseUrlException;
use App\Service\Oauth\Provider\GoogleProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Google;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты GoogleProvider на MOCK Guzzle-хендлере (без реальной сети): токен
 * + userinfo-ответ подставляются canned-JSON'ом через seam-конструктор ($client).
 */
final class GoogleProviderTest extends TestCase
{
    public function testKeyAndPkce(): void
    {
        $provider = $this->makeProvider([]);

        self::assertSame('google', $provider->key());
        self::assertFalse($provider->usesPkce());
    }

    public function testFetchUserInfoMapsVerifiedGoogleAccount(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'sub'            => 'g-123',
                'email'          => 'user@example.com',
                'email_verified' => true,
                'name'           => 'Jane Doe',
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('g-123', $info->providerUid);
        self::assertSame('user@example.com', $info->email);
        self::assertTrue($info->emailVerified);
        self::assertNull($info->username);
        self::assertSame('Jane Doe', $info->displayName);
    }

    public function testFetchUserInfoUnverifiedEmailIsNotTrusted(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'sub'            => 'g-456',
                'email'          => 'unverified@example.com',
                'email_verified' => false,
                'name'           => 'John Roe',
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('unverified@example.com', $info->email);
        self::assertFalse($info->emailVerified);
    }

    public function testGetAuthorizationUrlBuildsRealRedirectUriAndScope(): void
    {
        // БЕЗ 4-го аргумента $client — конструктор сам собирает
        // League\...\Google, включая buildRedirectUri(). getAuthorizationUrl()
        // сети не требует, поэтому это честная проверка прод-пути (не seam'а).
        $provider = new GoogleProvider('client-id', 'client-secret', 'https://example.test');

        $url = $provider->getAuthorizationUrl('STATE123', null);

        $parts = parse_url($url);
        self::assertSame('accounts.google.com', $parts['host'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('client-id', $query['client_id'] ?? null);
        self::assertSame(
            'https://example.test/api/v1/auth/oauth/google/callback',
            $query['redirect_uri'] ?? null,
        );
        self::assertSame('STATE123', $query['state'] ?? null);
        self::assertStringContainsString('email', $query['scope'] ?? '');
        self::assertStringContainsString('profile', $query['scope'] ?? '');
    }

    public function testEmptyRedirectBaseUrlFailsLoudInsteadOfRelativeRedirectUri(): void
    {
        // Fail loud (см. 2026-07-19 fix): APP_URL пустой/относительный → сразу
        // исключение при конструировании, а не молчаливый относительный redirect_uri.
        self::expectException(InvalidOauthRedirectBaseUrlException::class);

        new GoogleProvider('client-id', 'client-secret', '');
    }

    public function testFetchUserInfoMissingClaimIsFailClosed(): void
    {
        // Fail-closed: если claim email_verified отсутствует (напр. Workspace-аккаунт,
        // провизированный админом, может слать email без email_verified), НЕ считаем
        // email подтверждённым — иначе account-linking в SocialIdentityResolver
        // доверился бы неверифицированному email.
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'sub'   => 'g-789',
                'email' => 'noclaim@example.com',
                'name'  => 'No Claim',
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertFalse($info->emailVerified);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeProvider(array $responses): GoogleProvider
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client       = new Google(
            [
                'clientId'     => 'client-id',
                'clientSecret' => 'client-secret',
                'redirectUri'  => 'https://example.test/api/v1/auth/oauth/google/callback',
            ],
            ['httpClient' => new Client(['handler' => $handlerStack])],
        );

        return new GoogleProvider('client-id', 'client-secret', 'https://example.test', $client);
    }

    private function tokenResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'AT-1',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
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
