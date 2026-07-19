<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth\Provider;

use App\Service\Oauth\InvalidOauthRedirectBaseUrlException;
use App\Service\Oauth\Provider\Vk\VkIdOauth2Provider;
use App\Service\Oauth\Provider\VkProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Юнит-тесты VkProvider на MOCK Guzzle-хендлере (без реальной сети): токен +
 * userinfo-ответ подставляются canned-JSON'ом через seam-конструктор ($client).
 *
 * Ключевой фокус — три квирка карточки oauth-04: PKCE (S256) на authorize,
 * `device_id`+`code_verifier` в теле token-обмена БЕЗ `client_secret` (его у
 * VK ID вообще нет), userinfo — POST, а не GET.
 */
final class VkProviderTest extends TestCase
{
    public function testKeyAndPkce(): void
    {
        $provider = $this->makeProvider([]);

        self::assertSame('vk', $provider->key());
        self::assertTrue($provider->usesPkce());
    }

    public function testGetAuthorizationUrlIncludesPkceChallengeAndScope(): void
    {
        // БЕЗ 3-го аргумента $client — конструктор сам собирает VkIdOauth2Provider,
        // включая buildRedirectUri(). getAuthorizationUrl() сети не требует,
        // поэтому это честная проверка прод-пути (не seam'а).
        $provider = new VkProvider('client-id', 'https://example.test');

        // Валидный RFC 7636 code_verifier (43-128 симв. unreserved-набора).
        $codeVerifier = str_repeat('a', 43);
        $url          = $provider->getAuthorizationUrl('STATE123', $codeVerifier);

        $parts = parse_url($url);
        self::assertSame('id.vk.com', $parts['host'] ?? null);
        self::assertSame('/authorize', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);

        self::assertSame('client-id', $query['client_id'] ?? null);
        self::assertSame(
            'https://example.test/api/v1/auth/oauth/vk/callback',
            $query['redirect_uri'] ?? null,
        );
        self::assertSame('STATE123', $query['state'] ?? null);
        self::assertSame('email phone vkid.personal_info', $query['scope'] ?? null);

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        self::assertSame($expectedChallenge, $query['code_challenge'] ?? null);

        // Сам code_verifier НИКОГДА не должен уходить в query (только его S256-хэш).
        self::assertArrayNotHasKey('code_verifier', $query);
    }

    public function testEmptyRedirectBaseUrlFailsLoudInsteadOfRelativeRedirectUri(): void
    {
        // Fail loud (см. 2026-07-19 fix): APP_URL пустой/относительный → сразу
        // исключение при конструировании, а не молчаливый относительный redirect_uri.
        // Это же поведение объясняет обнаруженную "пропажу" client_id/redirect_uri
        // в проде: причина была не в VK-коде, а в пустом base URL для ВСЕХ провайдеров.
        self::expectException(InvalidOauthRedirectBaseUrlException::class);

        new VkProvider('client-id', '');
    }

    public function testFetchUserInfoSendsDeviceIdAndVerifierWithoutSecretAndPostsUserinfo(): void
    {
        /** @var list<array{request: RequestInterface}> $history */
        $history  = [];
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'user_id'    => '123456',
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'email'      => 'jane@vk.com',
                'phone'      => '+70000000000',
            ]),
        ], $history);

        $info = $provider->fetchUserInfo(
            ['code' => 'auth-code', 'device_id' => 'DEVICE-1', 'state' => 'STATE123'],
            'verifier-value',
        );

        self::assertCount(2, $history);

        // 1. Token-обмен — POST, тело без client_secret, с device_id/code_verifier/state.
        $tokenRequest = $history[0]['request'];
        self::assertSame('POST', $tokenRequest->getMethod());
        self::assertSame('id.vk.com', $tokenRequest->getUri()->getHost());
        self::assertSame('/oauth2/auth', $tokenRequest->getUri()->getPath());

        parse_str((string) $tokenRequest->getBody(), $tokenBody);
        self::assertSame('authorization_code', $tokenBody['grant_type'] ?? null);
        self::assertSame('auth-code', $tokenBody['code'] ?? null);
        self::assertSame('DEVICE-1', $tokenBody['device_id'] ?? null);
        self::assertSame('verifier-value', $tokenBody['code_verifier'] ?? null);
        self::assertSame('STATE123', $tokenBody['state'] ?? null);
        self::assertSame('client-id', $tokenBody['client_id'] ?? null);
        self::assertArrayNotHasKey('client_secret', $tokenBody);

        // 2. Userinfo — POST (не GET), access_token в теле формы.
        $userinfoRequest = $history[1]['request'];
        self::assertSame('POST', $userinfoRequest->getMethod());
        self::assertSame('id.vk.com', $userinfoRequest->getUri()->getHost());
        self::assertSame('/oauth2/user_info', $userinfoRequest->getUri()->getPath());

        parse_str((string) $userinfoRequest->getBody(), $userinfoBody);
        self::assertSame('AT-1', $userinfoBody['access_token'] ?? null);
        self::assertSame('client-id', $userinfoBody['client_id'] ?? null);

        // 3. Маппинг в OAuthUserInfo.
        self::assertSame('123456', $info->providerUid);
        self::assertSame('jane@vk.com', $info->email);
        // VK ID не отдаёт verified-флаг для email — никогда не считаем verified.
        self::assertFalse($info->emailVerified);
        self::assertNull($info->username);
        self::assertSame('Jane Doe', $info->displayName);
    }

    public function testFetchUserInfoWithNoEmailYieldsNullEmailNotInvented(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'user_id'    => '999',
                'first_name' => 'No',
                'last_name'  => 'Email',
            ]),
        ]);

        $info = $provider->fetchUserInfo(
            ['code' => 'auth-code', 'device_id' => 'DEVICE-1', 'state' => 'STATE123'],
            'verifier-value',
        );

        self::assertNull($info->email);
        self::assertFalse($info->emailVerified);
        self::assertSame('999', $info->providerUid);
        self::assertSame('No Email', $info->displayName);
    }

    public function testFetchUserInfoFallsBackToUserIdKeyWhenUserIdKeyAbsent(): void
    {
        $provider = $this->makeProvider([
            $this->tokenResponse(),
            $this->userinfoResponse([
                'id'         => '555',
                'first_name' => 'Legacy',
            ]),
        ]);

        $info = $provider->fetchUserInfo(
            ['code' => 'auth-code', 'device_id' => 'DEVICE-1', 'state' => 'STATE123'],
            'verifier-value',
        );

        self::assertSame('555', $info->providerUid);
    }

    /**
     * @param list<Response>                    $responses
     * @param list<array{request: RequestInterface}>|null $history
     */
    private function makeProvider(array $responses, ?array &$history = null): VkProvider
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        if ($history !== null) {
            $handlerStack->push(Middleware::history($history));
        }

        $client = new VkIdOauth2Provider(
            [
                'clientId'    => 'client-id',
                'redirectUri' => 'https://example.test/api/v1/auth/oauth/vk/callback',
            ],
            ['httpClient' => new Client(['handler' => $handlerStack])],
        );

        return new VkProvider('client-id', 'https://example.test', $client);
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
     * @param array<string, mixed> $userPayload
     */
    private function userinfoResponse(array $userPayload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'user' => $userPayload,
        ], JSON_THROW_ON_ERROR));
    }
}
