<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\GuestAuthenticator;
use App\Service\Auth\AnonymousIdentityService;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class AnonymousApiPrivacyRegressionTest extends AnonymousIdentityTestCase
{
    private const DOCUMENTATION_PATH = __DIR__ . '/../../../Fixtures/api-privacy.md';

    public function testAnonymousIdentityIsOpaqueAndDoesNotContainSourceOrSecret(): void
    {
        $source   = '192.0.2.44';
        $secret   = 'test-only-identity-key';
        $identity = (new AnonymousIdentityService($secret))->fromRequest(
            Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $source]),
        );

        self::assertIsString($identity);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $identity);
        self::assertStringNotContainsString($source, $identity);
        self::assertStringNotContainsString($secret, $identity);
    }

    public function testUntrustedForwardedHeaderCannotChangeAnonymousOwner(): void
    {
        $service = new AnonymousIdentityService('test-only-identity-key');
        $direct  = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.10']);
        $spoofed = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.44',
            'HTTP_X_REAL_IP'       => '192.0.2.44',
        ]);

        self::assertSame($service->fromRequest($direct), $service->fromRequest($spoofed));
    }

    public function testBearerRequestsNeverFallThroughToAnonymousAuthentication(): void
    {
        $anonymous     = new AnonymousIdentityService('test-only-identity-key');
        $users         = $this->createMock(UserRepository::class);
        $authenticator = new GuestAuthenticator(
            new GuestTokenService('guest-key'),
            new GuestCookieFactory(false),
            $users,
            $anonymous,
            $this->createMock(LoggerInterface::class),
        );

        foreach (['jwt-value', 'cnv-personal-token'] as $bearer) {
            $request = Request::create('/api/v1/quota', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer,
            ]);
            self::assertFalse($authenticator->supports($request));
        }
    }

    public function testValidGuestCookiePrecedesAnonymousIdentity(): void
    {
        $guest  = (new User())->setIsGuest(true)->setGuestId('guest-owner');
        $tokens = new GuestTokenService('guest-key');
        $users  = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('findActiveGuestByGuestId')
            ->with('guest-owner')
            ->willReturn($guest);
        $users->expects(self::never())->method('findActiveAnonymousIpByIdentity');
        $anonymous     = new AnonymousIdentityService('test-only-identity-key');
        $authenticator = new GuestAuthenticator(
            $tokens,
            new GuestCookieFactory(false),
            $users,
            $anonymous,
            $this->createMock(LoggerInterface::class),
        );
        $request = Request::create('/api/v1/quota', 'GET', [], [
            'guest_id' => $tokens->sign('guest-owner'),
        ]);

        $passport = $authenticator->authenticate($request);

        self::assertSame($guest, $passport->getUser());
        self::assertSame($guest, $passport->getBadge(UserBadge::class)->getUser());
    }

    public function testAnonymousFallbackIsNotUsedForHistoryWithoutCookie(): void
    {
        $authenticator = new GuestAuthenticator(
            new GuestTokenService('guest-key'),
            new GuestCookieFactory(false),
            $this->createMock(UserRepository::class),
            new AnonymousIdentityService('test-only-identity-key'),
            $this->createMock(LoggerInterface::class),
        );

        self::assertFalse($authenticator->supports(Request::create('/api/v1/convert/history')));
    }

    public function testUserDocumentationStatesPrivacyBoundaryWithoutSecretsOrNetworkDetails(): void
    {
        $documentation = file_get_contents(self::DOCUMENTATION_PATH);
        self::assertIsString($documentation);
        self::assertStringContainsString('30 дней', $documentation);
        self::assertStringContainsString('по полю `createdAt`', $documentation);
        self::assertStringContainsString('не удаляет записи конвертаций', $documentation);
        self::assertStringContainsString('24 часа', $documentation);
        self::assertStringNotContainsString('неактивности', $documentation);
        self::assertStringContainsString('текущей операции', $documentation);
        self::assertStringContainsString('доверенной границе прокси', $documentation);
        self::assertStringNotContainsString('HMAC', $documentation);
        self::assertStringNotContainsString('X-Forwarded-For', $documentation);
        self::assertStringNotContainsString('APP_SECRET', $documentation);
        self::assertStringNotContainsString('test-only-identity-key', $documentation);
    }
}
