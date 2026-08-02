<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\RateLimit;

use App\Entity\User;
use App\Service\RateLimit\ApiRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * CNV-34: per-IP + per-user/guest keys, 429 JSON shape + Retry-After.
 */
final class ApiRateLimiterTest extends TestCase
{
    public function testGuestConvertRejectsOnIpThenGuestId(): void
    {
        $limiter = $this->service(anonConvertLimit: 1);

        $guest = (new User())->setIsGuest(true)->setGuestId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $req   = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);

        self::assertNull($limiter->enforceConvert($req, $guest, false));

        $denied = $limiter->enforceConvert($req, $guest, false);
        self::assertNotNull($denied);
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame(
            ['error' => 'Too many anonymous conversions, please try later or log in'],
            json_decode((string) $denied->getContent(), true),
        );
        self::assertTrue($denied->headers->has('Retry-After'));
    }

    public function testGuestConvertSeparateGuestIdsShareIpBucket(): void
    {
        // limit=1: second guest on same IP is rejected by IP key (not guestId).
        $limiter = $this->service(anonConvertLimit: 1);

        $g1  = (new User())->setIsGuest(true)->setGuestId('11111111111111111111111111111111');
        $g2  = (new User())->setIsGuest(true)->setGuestId('22222222222222222222222222222222');
        $req = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.0.0.2']);

        self::assertNull($limiter->enforceConvert($req, $g1, false));
        self::assertNotNull($limiter->enforceConvert($req, $g2, false));
    }

    public function testGuestConvertSameGuestIdRejectedAcrossIps(): void
    {
        // After IP+guestId consume on first request, same guestId on another IP
        // still hits guest: key (IP buckets are independent; guestId shared).
        $limiter = $this->service(anonConvertLimit: 2);

        $guest = (new User())->setIsGuest(true)->setGuestId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $req1  = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.0.0.3']);
        $req2  = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.0.0.4']);

        // Each request consumes IP(1) + guestId(1). limit=2 → second request's
        // guestId consume is the 2nd hit on guest key → still accepted; third fails.
        self::assertNull($limiter->enforceConvert($req1, $guest, false));
        self::assertNull($limiter->enforceConvert($req2, $guest, false));

        $req3 = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.0.0.5']);
        self::assertNotNull($limiter->enforceConvert($req3, $guest, false));
    }

    public function testUserConvertRejectsWhenIpExceededEvenIfUserFresh(): void
    {
        $limiter = $this->service(userConvertLimit: 1);

        $u1  = $this->user(10);
        $u2  = $this->user(11);
        $req = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.1.0.1']);

        self::assertNull($limiter->enforceConvert($req, $u1, true));

        // Same IP, different user → IP bucket exhausted.
        $denied = $limiter->enforceConvert($req, $u2, true);
        self::assertNotNull($denied);
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame(
            ['error' => 'Too many requests, please try later'],
            json_decode((string) $denied->getContent(), true),
        );
        self::assertMatchesRegularExpression('/^\d+$/', (string) $denied->headers->get('Retry-After'));
    }

    public function testUserConvertRejectsWhenUserExceededEvenIfIpFresh(): void
    {
        $limiter = $this->service(userConvertLimit: 1);

        $user = $this->user(42);
        $req1 = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.2.0.1']);
        $req2 = Request::create('/api/v1/convert', 'POST', server: ['REMOTE_ADDR' => '10.2.0.2']);

        self::assertNull($limiter->enforceConvert($req1, $user, true));
        self::assertNotNull($limiter->enforceConvert($req2, $user, true));
    }

    public function testUserQuotaSameShape(): void
    {
        $limiter = $this->service(userQuotaLimit: 1);

        $user = $this->user(7);
        $req  = Request::create('/api/v1/quota', 'GET', server: ['REMOTE_ADDR' => '10.3.0.1']);

        self::assertNull($limiter->enforceQuota($req, $user, true));
        $denied = $limiter->enforceQuota($req, $user, true);
        self::assertNotNull($denied);
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame(
            ['error' => 'Too many requests, please try later'],
            json_decode((string) $denied->getContent(), true),
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function service(
        int $anonConvertLimit = 20,
        int $anonQuotaLimit = 60,
        int $userConvertLimit = 120,
        int $userQuotaLimit = 120,
    ): ApiRateLimiter {
        $storage = new InMemoryStorage();

        return new ApiRateLimiter(
            $this->factory('anon_convert', $anonConvertLimit, $storage),
            $this->factory('anon_quota', $anonQuotaLimit, $storage),
            $this->factory('user_convert', $userConvertLimit, $storage),
            $this->factory('user_quota', $userQuotaLimit, $storage),
        );
    }

    private function factory(string $id, int $limit, InMemoryStorage $storage): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id'       => $id,
                'policy'   => 'sliding_window',
                'limit'    => $limit,
                'interval' => '1 hour',
            ],
            $storage,
        );
    }
}
