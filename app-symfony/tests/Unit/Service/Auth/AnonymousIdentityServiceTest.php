<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AnonymousIdentityService;
use Symfony\Component\HttpFoundation\Request;

final class AnonymousIdentityServiceTest extends AnonymousIdentityTestCase
{
    public function testSameClientIpProducesStableOpaqueIdentity(): void
    {
        self::assertSame([], Request::getTrustedProxies());
        self::assertSame(0, Request::getTrustedHeaderSet());

        $service = new AnonymousIdentityService('identity-secret');

        $first  = $service->fromRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']));
        $second = $service->fromRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']));

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertStringNotContainsString('192.0.2.10', $first);
    }

    public function testDifferentClientIpsDoNotShareIdentity(): void
    {
        $service = new AnonymousIdentityService('identity-secret');

        $first  = $service->fromRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']));
        $second = $service->fromRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.11']));

        self::assertNotSame($first, $second);
    }

    public function testForwardedHeadersAreUsedOnlyWhenRequestIsFromTrustedProxy(): void
    {
        $service = new AnonymousIdentityService('identity-secret');
        $spoofed = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.10',
        ]);

        self::assertSame(
            $service->fromRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.10'])),
            $service->fromRequest($spoofed),
        );
    }
}
