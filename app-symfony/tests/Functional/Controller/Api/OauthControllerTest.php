<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\DTO\OAuthUserInfo;
use App\Entity\User;
use App\Service\Oauth\InvalidOauthStateException;
use App\Service\Oauth\OauthProviderInterface;
use App\Service\Oauth\OauthProviderRegistry;
use App\Service\Oauth\OauthStateData;
use App\Service\Oauth\OauthStateStore;
use App\Service\Oauth\SocialIdentityResolver;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты start→redirect и callback→JWT(refresh-cookie)+redirect на
 * СТАБ-провайдере (без сети): реестр с фейковым адаптером + мок state-store +
 * стаб resolver'а подменяются в контейнере. RefreshTokenService — настоящий
 * (как в TelegramLoginControllerTest): важно лишь, что refresh-cookie выставлена.
 */
final class OauthControllerTest extends WebTestCase
{
    private const AUTH_URL = 'https://provider.test/authorize?client_id=x&state=STATE';

    public function testStartRedirectsToProviderAuthorizeUrl(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $provider = $this->createMock(OauthProviderInterface::class);
        $provider->method('key')->willReturn('fake');
        $provider->method('usesPkce')->willReturn(false);
        $provider->expects(self::once())->method('getAuthorizationUrl')->with('STATE', null)->willReturn(self::AUTH_URL);
        $container->set(OauthProviderRegistry::class, new OauthProviderRegistry([$provider]));

        $store = $this->createMock(OauthStateStore::class);
        $store->expects(self::once())->method('mint')->with('fake', null)->willReturn('STATE');
        $container->set(OauthStateStore::class, $store);

        $client->request('GET', '/api/v1/auth/oauth/fake/start');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(self::AUTH_URL, $client->getResponse()->headers->get('Location'));
    }

    public function testUnknownProviderReturns404(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        // Реестр только с 'fake' → 'unknown' не резолвится → 404.
        $container->set(OauthProviderRegistry::class, new OauthProviderRegistry([$this->fakeProvider()]));

        $client->request('GET', '/api/v1/auth/oauth/unknown/start');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCallbackSuccessMintsSessionAndRedirects(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $provider = $this->fakeProvider();
        $provider->method('fetchUserInfo')->willReturn(new OAuthUserInfo('uid-777', 'u@b.com', true, 'nick', 'Nick'));
        $container->set(OauthProviderRegistry::class, new OauthProviderRegistry([$provider]));

        $store = $this->createMock(OauthStateStore::class);
        $store->expects(self::once())->method('consume')->with('STATE', 'fake')->willReturn(new OauthStateData(null));
        $container->set(OauthStateStore::class, $store);

        $resolver = $this->createStub(SocialIdentityResolver::class);
        $resolver->method('findOrCreateUser')->willReturn($this->makeUser(777));
        $container->set(SocialIdentityResolver::class, $resolver);

        $client->request('GET', '/api/v1/auth/oauth/fake/callback?code=CODE&state=STATE');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/', $client->getResponse()->headers->get('Location'));

        $names = array_map(static fn ($c) => $c->getName(), $client->getResponse()->headers->getCookies());
        self::assertContains('refresh_token', $names, 'сессия выдана refresh-cookie (JWT в URL не уходит)');
    }

    public function testCallbackInvalidStateRedirectsToLoginError(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $container->set(OauthProviderRegistry::class, new OauthProviderRegistry([$this->fakeProvider()]));

        $store = $this->createStub(OauthStateStore::class);
        $store->method('consume')->willThrowException(new InvalidOauthStateException());
        $container->set(OauthStateStore::class, $store);

        $client->request('GET', '/api/v1/auth/oauth/fake/callback?code=CODE&state=BAD');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/login?oauth_error=state', $client->getResponse()->headers->get('Location'));
    }

    /**
     * Стаб-адаптер с ключом `fake` (без счётчиков вызовов — createStub, не Mock).
     *
     * @return OauthProviderInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private function fakeProvider(): OauthProviderInterface
    {
        $provider = $this->createStub(OauthProviderInterface::class);
        $provider->method('key')->willReturn('fake');

        return $provider;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
