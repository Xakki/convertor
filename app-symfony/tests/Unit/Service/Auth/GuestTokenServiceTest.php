<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\GuestTokenService;
use PHPUnit\Framework\TestCase;

final class GuestTokenServiceTest extends TestCase
{
    private function service(string $secret = 'test-secret'): GuestTokenService
    {
        return new GuestTokenService($secret);
    }

    public function testGenerateGuestIdIsHexAndFitsColumn(): void
    {
        $id = $this->service()->generateGuestId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertLessThanOrEqual(64, \strlen($id));
    }

    public function testSignThenVerifyRoundTrips(): void
    {
        $svc     = $this->service();
        $guestId = $svc->generateGuestId();

        $signed = $svc->sign($guestId);

        self::assertStringStartsWith($guestId . '.', $signed);
        self::assertSame($guestId, $svc->verify($signed));
    }

    public function testVerifyRejectsTamperedGuestId(): void
    {
        $svc    = $this->service();
        $signed = $svc->sign('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        // Подменяем сырой id, оставляя старую подпись.
        [$_, $sig] = explode('.', $signed, 2);
        $forged    = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.' . $sig;

        self::assertNull($svc->verify($forged));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $guestId = 'cccccccccccccccccccccccccccccccc';
        $signed  = $this->service('secret-A')->sign($guestId);

        self::assertNull($this->service('secret-B')->verify($signed));
    }

    public function testVerifyRejectsMalformedValues(): void
    {
        $svc = $this->service();

        self::assertNull($svc->verify(''));
        self::assertNull($svc->verify('no-separator'));
        self::assertNull($svc->verify('.only-signature'));
    }
}
