<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Service\Auth\RefreshTokenService;
use App\Service\Queue\RedisConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Drives the full refresh-family state machine against an in-memory \Redis
 * double. The double re-implements ROTATE_LUA in PHP, so these tests cover the
 * service wrapper (parse/hash/result-mapping) and the *intended* state-machine
 * shape; the authoritative server-side Lua is exercised against a real KeyDB in
 * tests/Integration/Service/Auth/RefreshTokenServiceKeyDbTest.php, which mirrors
 * the same scenarios so a divergence between fake and Lua surfaces there.
 */
final class RefreshTokenServiceTest extends TestCase
{
    private const SECRET  = 'unit-test-app-secret';
    private const USER_ID = 42;
    private const TTL     = 2592000;

    public function testIssueFamilyReturnsCookieShapeAndStoresHashes(): void
    {
        $redis  = $this->makeRedis();
        $before = time();
        $cookie = $this->makeService($redis)->issueFamily($this->makeUser());

        [$familyId, $secret] = explode('.', $cookie, 2);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $familyId,
        );
        self::assertNotSame('', $secret);

        $stored = $redis->peek($familyId);
        self::assertNotNull($stored);
        self::assertSame(self::USER_ID, $stored['userId']);
        self::assertNull($stored['prevSecretHash']);
        self::assertSame(0, $stored['graceUntil']);
        self::assertSame(64, strlen((string) $stored['secretHash']));
        // exp ≈ now + TTL (allow a couple of seconds of wall-clock slack).
        self::assertGreaterThanOrEqual($before + self::TTL, $stored['exp']);
        self::assertLessThanOrEqual(time() + self::TTL + 2, $stored['exp']);
    }

    public function testRotateWithCurrentSecretRotatesAndPreservesExp(): void
    {
        $redis      = $this->makeRedis();
        $service    = $this->makeService($redis);
        $cookie     = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie, 2);

        $before = $redis->peek($familyId);
        $result = $service->rotate($cookie);

        self::assertTrue($result->valid);
        self::assertSame(self::USER_ID, $result->userId);
        self::assertNotNull($result->cookieValue);
        self::assertNotSame($cookie, $result->cookieValue);
        self::assertStringStartsWith($familyId . '.', (string) $result->cookieValue);

        $after = $redis->peek($familyId);
        self::assertNotSame($before['secretHash'], $after['secretHash']);
        self::assertSame($before['secretHash'], $after['prevSecretHash']);
        self::assertGreaterThan(0, $after['graceUntil']);
        self::assertSame($before['exp'], $after['exp'], 'exp must never be extended on rotation');
    }

    public function testRotateAgainWithNewSecretRotatesAgain(): void
    {
        $redis   = $this->makeRedis();
        $service = $this->makeService($redis);
        $cookie1 = $service->issueFamily($this->makeUser());

        $r1 = $service->rotate($cookie1);
        self::assertTrue($r1->valid);

        $r2 = $service->rotate((string) $r1->cookieValue);
        self::assertTrue($r2->valid);
        self::assertNotNull($r2->cookieValue);
        self::assertNotSame($r1->cookieValue, $r2->cookieValue);
    }

    public function testBenignReplayWithinGraceLeavesCookieAndFamilyIntact(): void
    {
        $redis = $this->makeRedis();
        // Generous grace so the immediate replay falls inside the window.
        $service    = $this->makeService($redis, grace: 60);
        $cookie1    = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie1, 2);

        $service->rotate($cookie1); // cookie1 → cookie2; cookie1's secret becomes prev

        $replay = $service->rotate($cookie1); // replay the now-previous secret, within grace
        self::assertTrue($replay->valid);
        self::assertSame(self::USER_ID, $replay->userId);
        self::assertNull($replay->cookieValue, 'benign replay must leave the incoming cookie unchanged');
        self::assertNotNull($redis->peek($familyId), 'benign replay must NOT revoke the family');
    }

    public function testReuseOfTwoRotationsOldSecretRevokesFamily(): void
    {
        // Time-independent reuse: an old secret is neither current nor prev.
        $redis      = $this->makeRedis();
        $service    = $this->makeService($redis);
        $cookie1    = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie1, 2);

        $r1 = $service->rotate($cookie1); // cookie1 → cookie2
        $r2 = $service->rotate((string) $r1->cookieValue); // cookie2 → cookie3
        self::assertTrue($r2->valid);

        $reuse = $service->rotate($cookie1); // cookie1 is two rotations old
        self::assertFalse($reuse->valid);
        self::assertNull($redis->peek($familyId), 'reuse detection must delete the family key');

        // Family is gone → even the then-current secret no longer works.
        $afterRevoke = $service->rotate((string) $r2->cookieValue);
        self::assertFalse($afterRevoke->valid);
    }

    public function testReuseOfPreviousSecretAfterGraceExpiryRevokesFamily(): void
    {
        // Drive elapsed time via an injected MockClock instead of poking the store.
        $redis      = $this->makeRedis();
        $clock      = new MockClock('2026-01-01T00:00:00+00:00');
        $service    = $this->makeService($redis, grace: 60, clock: $clock);
        $cookie1    = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie1, 2);

        $service->rotate($cookie1); // cookie1 → cookie2 (cookie1 is prev, within grace)
        $clock->modify('+61 seconds'); // advance past the grace window

        $reuse = $service->rotate($cookie1); // prev secret, but grace expired
        self::assertFalse($reuse->valid);
        self::assertNull($redis->peek($familyId), 'prev-after-grace must be treated as reuse and revoke the family');
    }

    public function testReplayOneSecondBeforeGraceExpiryStillValid(): void
    {
        // Boundary check on the other side: MockClock lets us land exactly one
        // second inside the grace window, still a benign replay.
        $redis      = $this->makeRedis();
        $clock      = new MockClock('2026-01-01T00:00:00+00:00');
        $service    = $this->makeService($redis, grace: 60, clock: $clock);
        $cookie1    = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie1, 2);

        $service->rotate($cookie1); // cookie1 → cookie2 (cookie1 is prev, grace = now+60)
        $clock->modify('+59 seconds'); // still inside the grace window

        $replay = $service->rotate($cookie1);
        self::assertTrue($replay->valid);
        self::assertNull($replay->cookieValue, 'benign replay must leave the incoming cookie unchanged');
        self::assertNotNull($redis->peek($familyId), 'family must survive a replay within grace');
    }

    public function testUnknownFamilyIsInvalid(): void
    {
        $redis   = $this->makeRedis();
        $service = $this->makeService($redis);

        $result = $service->rotate('00000000-0000-4000-8000-000000000000.somesecret');
        self::assertFalse($result->valid);
        self::assertNull($result->userId);
    }

    #[DataProvider('malformedCookies')]
    public function testMalformedCookieIsInvalidWithoutCrash(string $cookie): void
    {
        $redis  = $this->makeRedis();
        $result = $this->makeService($redis)->rotate($cookie);

        self::assertFalse($result->valid);
        self::assertNull($result->userId);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCookies(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['nodothere'];
        yield 'only dot' => ['.'];
        yield 'empty family' => ['.secret'];
        yield 'empty secret' => ['family.'];
    }

    public function testRevokeDeletesFamilyAndIsIdempotent(): void
    {
        $redis      = $this->makeRedis();
        $service    = $this->makeService($redis);
        $cookie     = $service->issueFamily($this->makeUser());
        [$familyId] = explode('.', $cookie, 2);

        $service->revoke($cookie);
        self::assertNull($redis->peek($familyId));

        self::assertFalse($service->rotate($cookie)->valid, 'rotate after revoke must be invalid');

        // Second revoke and a revoke of a malformed value must not error.
        $service->revoke($cookie);
        $service->revoke('garbage-no-dot');
        self::assertNull($redis->peek($familyId));
    }

    private function makeUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);

        return $user;
    }

    private function makeService(object $redis, int $grace = 60, ?ClockInterface $clock = null): RefreshTokenService
    {
        $factory = new class ($redis) extends RedisConnectionFactory {
            public function __construct(private \Redis $fake)
            {
                parent::__construct('redis://localhost:6379?dbindex=1');
            }

            public function create(): \Redis
            {
                return $this->fake;
            }
        };

        return new RefreshTokenService($factory, self::SECRET, self::TTL, $grace, $clock ?? new NativeClock());
    }

    /**
     * In-memory \Redis double that re-implements ROTATE_LUA in PHP. peek() lets
     * tests inspect the stored family JSON without a live server; elapsed time
     * is driven through the service's injected MockClock, not by aging the store.
     */
    private function makeRedis()
    {
        return new class () extends \Redis {
            /** @var array<string, string> */
            public array $store = [];

            public function get($key): mixed
            {
                return $this->store[$key] ?? false;
            }

            public function set($key, $value, $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }

            public function del($key, ...$other_keys): \Redis|int|false
            {
                $keys = is_array($key) ? $key : [$key, ...$other_keys];
                $n    = 0;
                foreach ($keys as $k) {
                    if (isset($this->store[$k])) {
                        unset($this->store[$k]);
                        $n++;
                    }
                }

                return $n;
            }

            public function eval($script, $args = [], $num_keys = 0): mixed
            {
                [$key, $presented, $newHash, $now, $grace] = [
                    $args[0], $args[1], $args[2], (int) $args[3], (int) $args[4],
                ];

                $raw = $this->store[$key] ?? null;
                if ($raw === null) {
                    return ['invalid'];
                }

                /** @var array<string, mixed> $d */
                $d   = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $ttl = (int) $d['exp'] - $now;
                if ($ttl <= 0) {
                    unset($this->store[$key]);

                    return ['invalid'];
                }

                if ($d['secretHash'] === $presented) {
                    $d['prevSecretHash'] = $d['secretHash'];
                    $d['secretHash']     = $newHash;
                    $d['graceUntil']     = $now + $grace;
                    $this->store[$key]   = json_encode($d, JSON_THROW_ON_ERROR);

                    return ['rotated', (string) $d['userId']];
                }

                if (is_string($d['prevSecretHash']) && $d['prevSecretHash'] === $presented && $now < (int) $d['graceUntil']) {
                    return ['replay', (string) $d['userId']];
                }

                unset($this->store[$key]);

                return ['reuse'];
            }

            /** @return array<string, mixed>|null */
            public function peek(string $familyId): ?array
            {
                $raw = $this->store['rt:' . $familyId] ?? null;

                return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        };
    }
}
