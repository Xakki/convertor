<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth\Provider;

use App\Service\Oauth\InvalidOauthRedirectBaseUrlException;
use App\Service\Oauth\Provider\GithubProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Github;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты GithubProvider на MOCK Guzzle-хендлере (без реальной сети). Каждый
 * fetchUserInfo() делает РОВНО 3 запроса в этом порядке: token exchange →
 * `GET /user` → `GET /user/emails` (свой запрос, НЕ встроенный fallback
 * League\Github — см. комментарий в GithubProvider).
 */
final class GithubProviderTest extends TestCase
{
    public function testKeyAndPkce(): void
    {
        $provider = $this->makeProvider([]);

        self::assertSame('github', $provider->key());
        self::assertFalse($provider->usesPkce());
    }

    public function testGetAuthorizationUrlBuildsRealRedirectUriAndScope(): void
    {
        // БЕЗ 4-го аргумента $client — конструктор сам собирает
        // League\...\Github, включая buildRedirectUri(). getAuthorizationUrl()
        // сети не требует, поэтому это честная проверка прод-пути (не seam'а).
        $provider = new GithubProvider('client-id', 'client-secret', 'https://example.test');

        $url = $provider->getAuthorizationUrl('STATE123', null);

        $parts = parse_url($url);
        self::assertSame('github.com', $parts['host'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('client-id', $query['client_id'] ?? null);
        self::assertSame(
            'https://example.test/api/v1/auth/oauth/github/callback',
            $query['redirect_uri'] ?? null,
        );
        self::assertSame('STATE123', $query['state'] ?? null);
        self::assertSame('user:email', $query['scope'] ?? null);
    }

    public function testEmptyRedirectBaseUrlFailsLoudInsteadOfRelativeRedirectUri(): void
    {
        // Fail loud (см. 2026-07-19 fix): APP_URL пустой/относительный → сразу
        // исключение при конструировании, а не молчаливый относительный redirect_uri.
        self::expectException(InvalidOauthRedirectBaseUrlException::class);

        new GithubProvider('client-id', 'client-secret', '');
    }

    public function testFetchUserInfoWithPublicPrimaryVerifiedEmail(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userResponse([
                'id'    => 555,
                'login' => 'octocat',
                'name'  => 'Octo Cat',
                'email' => 'public@example.com',
            ]),
            $this->emailsResponse([
                ['email' => 'public@example.com', 'primary' => true, 'verified' => true, 'visibility' => 'public'],
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('555', $info->providerUid);
        self::assertSame('public@example.com', $info->email);
        self::assertTrue($info->emailVerified);
        self::assertSame('octocat', $info->username);
        self::assertSame('Octo Cat', $info->displayName);
    }

    public function testFetchUserInfoWithPrivateEmailOnlyViaEmailsEndpoint(): void
    {
        // /user.email = null (адрес не публичный) → email приходит ТОЛЬКО из
        // /user/emails — ключевой сценарий карточки oauth-02.
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userResponse([
                'id'    => 777,
                'login' => 'privateuser',
                'name'  => 'Private User',
                'email' => null,
            ]),
            $this->emailsResponse([
                ['email' => 'private@example.com', 'primary' => true, 'verified' => true, 'visibility' => null],
                ['email' => 'other@example.com', 'primary' => false, 'verified' => true, 'visibility' => null],
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('777', $info->providerUid);
        self::assertSame('private@example.com', $info->email);
        self::assertTrue($info->emailVerified);
        self::assertSame('privateuser', $info->username);
    }

    public function testFetchUserInfoWithNoVerifiedEmailReportsUnverified(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userResponse([
                'id'    => 999,
                'login' => 'unverifieduser',
                'name'  => 'Unverified User',
                'email' => null,
            ]),
            $this->emailsResponse([
                ['email' => 'unverified@example.com', 'primary' => true, 'verified' => false, 'visibility' => null],
            ]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertSame('unverified@example.com', $info->email);
        self::assertFalse($info->emailVerified);
    }

    public function testFetchUserInfoWithEmptyEmailsListYieldsNullEmail(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userResponse([
                'id'    => 111,
                'login' => 'noemailuser',
                'name'  => 'No Email User',
                'email' => null,
            ]),
            $this->emailsResponse([]),
        ]);

        $info = $provider->fetchUserInfo(['code' => 'auth-code'], null);

        self::assertNull($info->email);
        self::assertFalse($info->emailVerified);
    }

    /**
     * @param list<Response> $responses
     */
    private function makeProvider(array $responses): GithubProvider
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client       = new Github(
            [
                'clientId'     => 'client-id',
                'clientSecret' => 'client-secret',
                'redirectUri'  => 'https://example.test/api/v1/auth/oauth/github/callback',
            ],
            ['httpClient' => new Client(['handler' => $handlerStack])],
        );

        return new GithubProvider('client-id', 'client-secret', 'https://example.test', $client);
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
    private function userResponse(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $emails
     */
    private function emailsResponse(array $emails): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($emails, JSON_THROW_ON_ERROR));
    }
}
