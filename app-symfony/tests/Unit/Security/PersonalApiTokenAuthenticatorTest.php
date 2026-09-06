<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Repository\PersonalApiTokenRepository;
use App\Security\PersonalApiTokenAuthenticator;
use App\Service\Auth\PersonalApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PersonalApiTokenAuthenticatorTest extends TestCase
{
    public function testOnlyPersonalBearerOnApprovedMethodAndRouteIsSupported(): void
    {
        $authenticator = new PersonalApiTokenAuthenticator(new PersonalApiTokenService(
            $this->createMock(PersonalApiTokenRepository::class),
            $this->createMock(EntityManagerInterface::class),
        ));
        $secret = 'cnv_' . str_repeat('A', 43);

        $allowed = [
            ['POST', '/api/v1/convert'],
            ['GET', '/api/v1/convert/history'],
            ['GET', '/api/v1/convert/12/status'],
            ['GET', '/api/v1/convert/12/download'],
            ['GET', '/api/v1/convert/12/preview'],
            ['GET', '/api/v1/quota'],
            ['GET', '/api/v1/audit/history'],
        ];
        foreach ($allowed as [$method, $path]) {
            $request = Request::create($path, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
            self::assertTrue($authenticator->supports($request), $method . ' ' . $path);
        }
    }

    public function testJwtMalformedAndEveryUnapprovedRouteOrMethodIsRejectedByPersonalAuthenticator(): void
    {
        $authenticator = new PersonalApiTokenAuthenticator(new PersonalApiTokenService(
            $this->createMock(PersonalApiTokenRepository::class),
            $this->createMock(EntityManagerInterface::class),
        ));
        $secret   = 'cnv_' . str_repeat('A', 43);
        $rejected = [
            ['GET', '/api/v1/convert'],
            ['POST', '/api/v1/convert/12/status'],
            ['GET', '/api/v1/convert/12/source'],
            ['POST', '/api/v1/convert/12/retry'],
            ['DELETE', '/api/v1/convert/12'],
            ['GET', '/api/v1/payment/history'],
            ['GET', '/api/v1/me'],
            ['GET', '/api/v1/auth/tokens'],
            ['GET', '/api/v1/admin/users'],
            ['GET', '/api/v1/worker/jobs'],
            ['GET', '/api/v1/internal/health'],
        ];
        foreach ($rejected as [$method, $path]) {
            $request = Request::create($path, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
            self::assertFalse($authenticator->supports($request), $method . ' ' . $path);
        }

        $jwt = Request::create('/api/v1/convert/history', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer eyJ.test.jwt']);
        self::assertFalse($authenticator->supports($jwt));
    }
}
