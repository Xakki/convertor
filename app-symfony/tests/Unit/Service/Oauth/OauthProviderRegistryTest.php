<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oauth;

use App\DTO\OAuthUserInfo;
use App\Service\Oauth\OauthProviderInterface;
use App\Service\Oauth\OauthProviderRegistry;
use App\Service\Oauth\UnknownOauthProviderException;
use PHPUnit\Framework\TestCase;

final class OauthProviderRegistryTest extends TestCase
{
    public function testResolvesByKeyAndReportsPresence(): void
    {
        $provider = $this->provider('google');
        $registry = new OauthProviderRegistry([$provider]);

        self::assertTrue($registry->has('google'));
        self::assertFalse($registry->has('github'));
        self::assertSame($provider, $registry->get('google'));
    }

    public function testUnknownProviderThrows(): void
    {
        $registry = new OauthProviderRegistry([]);

        $this->expectException(UnknownOauthProviderException::class);
        $registry->get('nope');
    }

    private function provider(string $key): OauthProviderInterface
    {
        return new class ($key) implements OauthProviderInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function usesPkce(): bool
            {
                return false;
            }

            public function getAuthorizationUrl(string $state, ?string $codeVerifier): string
            {
                return 'https://example.test/authorize';
            }

            public function fetchUserInfo(array $callbackParams, ?string $codeVerifier): OAuthUserInfo
            {
                return new OAuthUserInfo('uid', null, false);
            }
        };
    }
}
