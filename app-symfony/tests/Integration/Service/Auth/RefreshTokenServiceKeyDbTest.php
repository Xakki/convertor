<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Auth;

use App\Entity\User;
use App\Service\Auth\RefreshTokenService;
use App\Service\Queue\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ACTUAL server-side ROTATE_LUA against a real KeyDB (db 1),
 * mirroring the unit state-machine scenarios so a divergence between the
 * in-memory fake and the production Lua is caught. Skips cleanly when no KeyDB
 * is reachable (e.g. CI without the sessions store).
 *
 * @group integration
 */
final class RefreshTokenServiceKeyDbTest extends TestCase
{
    private const SECRET = 'integration-test-secret';

    private RedisConnectionFactory $factory;
    private \Redis $redis;

    protected function setUp(): void
    {
        $dsn     = getenv('REDIS_SESSIONS_DSN') ?: ($_SERVER['REDIS_SESSIONS_DSN'] ?? 'redis://keydb:6379?dbindex=1');
        $factory = new RedisConnectionFactory((string) $dsn);

        try {
            $redis = $factory->create();
            $redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('KeyDB (sessions db) not reachable: ' . $e->getMessage());
        }

        $this->factory = $factory;
        $this->redis   = $redis;
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of any rt:* keys this test family left behind.
        foreach ($this->createdFamilies as $familyId) {
            $this->redis->del('rt:' . $familyId);
        }
    }

    /** @var list<string> */
    private array $createdFamilies = [];

    public function testRotateReplayAndReuseAgainstRealLua(): void
    {
        $service    = $this->makeService(grace: 60);
        $cookie1    = $this->issue($service);
        [$familyId] = explode('.', $cookie1, 2);

        // Rotate with current secret.
        $r1 = $service->rotate($cookie1);
        self::assertTrue($r1->valid);
        self::assertSame(7, $r1->userId);
        self::assertNotSame($cookie1, $r1->cookieValue);

        // exp must be preserved across rotation.
        $exp = $this->expOf($familyId);

        // Benign replay of the previous secret within grace → valid, no new cookie.
        $replay = $service->rotate($cookie1);
        self::assertTrue($replay->valid);
        self::assertNull($replay->cookieValue);
        self::assertNotFalse($this->redis->get('rt:' . $familyId), 'family must survive benign replay');

        // Rotate again with the current secret.
        $r2 = $service->rotate((string) $r1->cookieValue);
        self::assertTrue($r2->valid);
        self::assertSame($exp, $this->expOf($familyId), 'exp must never be extended');

        // Reuse: cookie1 is now two rotations old → family revoked.
        $reuse = $service->rotate($cookie1);
        self::assertFalse($reuse->valid);
        self::assertFalse($this->redis->get('rt:' . $familyId), 'reuse must delete the family');

        // Family gone → then-current secret also fails.
        self::assertFalse($service->rotate((string) $r2->cookieValue)->valid);
    }

    public function testPreviousSecretAfterGraceIsReuse(): void
    {
        $service    = $this->makeService(grace: 60);
        $cookie1    = $this->issue($service);
        [$familyId] = explode('.', $cookie1, 2);

        $service->rotate($cookie1); // cookie1 becomes prev, within grace

        // Age graceUntil into the past directly in the store to simulate elapsed time.
        $raw             = (string) $this->redis->get('rt:' . $familyId);
        $d               = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $d['graceUntil'] = 1;
        $this->redis->set('rt:' . $familyId, json_encode($d, JSON_THROW_ON_ERROR));

        $reuse = $service->rotate($cookie1);
        self::assertFalse($reuse->valid);
        self::assertFalse($this->redis->get('rt:' . $familyId), 'prev-after-grace must revoke the family');
    }

    public function testRevokeAndUnknownFamily(): void
    {
        $service    = $this->makeService();
        $cookie     = $this->issue($service);
        [$familyId] = explode('.', $cookie, 2);

        $service->revoke($cookie);
        self::assertFalse($this->redis->get('rt:' . $familyId));
        self::assertFalse($service->rotate($cookie)->valid);
        $service->revoke($cookie); // idempotent — no error

        self::assertFalse($service->rotate('11111111-1111-4111-8111-111111111111.nope')->valid);
    }

    private function makeService(int $grace = 60): RefreshTokenService
    {
        return new RefreshTokenService($this->factory, self::SECRET, 2592000, $grace);
    }

    private function issue(RefreshTokenService $service): string
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $cookie                  = $service->issueFamily($user);
        $this->createdFamilies[] = explode('.', $cookie, 2)[0];

        return $cookie;
    }

    private function expOf(string $familyId): int
    {
        $d = json_decode((string) $this->redis->get('rt:' . $familyId), true, 512, JSON_THROW_ON_ERROR);

        return (int) $d['exp'];
    }
}
