<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\TelegramLinkCodeStore;
use App\Service\Queue\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * State-machine кодов привязки Telegram (CNV-59) против in-memory Redis-дубля.
 */
final class TelegramLinkCodeStoreTest extends TestCase
{
    private const USER_ID = 42;

    public function testMintBindsUserIdAndStoresPending(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);

        self::assertNotSame('', $minted['code']);
        self::assertNotSame('', $minted['nonce']);

        $stored = $redis->peek($minted['code']);
        self::assertNotNull($stored);
        self::assertSame(TelegramLinkCodeStore::STATUS_PENDING, $stored['status']);
        self::assertSame(self::USER_ID, $stored['userId']);
        self::assertSame(hash('sha256', $minted['nonce']), $stored['nonceHash']);
    }

    public function testPeekUserIdReturnsBoundId(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);

        self::assertSame(self::USER_ID, $store->peekUserId($minted['code']));
        self::assertNull($store->peekUserId('missing'));
    }

    public function testMarkAuthorizedThenRedeemBurnsCode(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);

        self::assertTrue($store->markAuthorized($minted['code']));

        $result = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLinkCodeStore::STATUS_AUTHORIZED, $result['status']);
        self::assertSame(self::USER_ID, $result['userId']);
        self::assertNull($redis->peek($minted['code']));
    }

    public function testMarkCollisionSurfacesOnRedeem(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);

        self::assertTrue($store->markCollision($minted['code']));

        $result = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLinkCodeStore::STATUS_COLLISION, $result['status']);
        self::assertSame(self::USER_ID, $result['userId']);
    }

    public function testRedeemMismatchKeepsCode(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);
        $store->markAuthorized($minted['code']);

        $result = $store->redeem($minted['code'], 'wrong-nonce');
        self::assertSame(TelegramLinkCodeStore::STATUS_MISMATCH, $result['status']);
        self::assertNotNull($redis->peek($minted['code']));
    }

    public function testSecondMarkAuthorizedRejected(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint(self::USER_ID);

        self::assertTrue($store->markAuthorized($minted['code']));
        self::assertFalse($store->markAuthorized($minted['code']));
    }

    private function makeStore(object $redis): TelegramLinkCodeStore
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

        return new TelegramLinkCodeStore($factory, 300);
    }

    private function makeRedis(): \Redis
    {
        return new class () extends \Redis {
            /** @var array<string, string> */
            public array $store = [];

            public function set($key, $value, $options = null): \Redis|string|bool
            {
                if (is_array($options) && ($options['NX'] ?? false) === true && isset($this->store[$key])) {
                    return false;
                }
                $this->store[$key] = (string) $value;

                return true;
            }

            public function get($key): mixed
            {
                return $this->store[$key] ?? false;
            }

            public function del($key, ...$other_keys): \Redis|int|false
            {
                $n = 0;
                foreach ([$key, ...$other_keys] as $k) {
                    if (isset($this->store[$k])) {
                        unset($this->store[$k]);
                        $n++;
                    }
                }

                return $n;
            }

            public function eval($script, $args = [], $num_keys = 0): mixed
            {
                $key = $args[0];
                $raw = $this->store[$key] ?? null;

                if (str_contains((string) $script, "d.status = 'authorized'")) {
                    if ($raw === null) {
                        return 0;
                    }
                    /** @var array<string, mixed> $d */
                    $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (($d['status'] ?? null) !== 'pending') {
                        return 0;
                    }
                    $d['status']       = 'authorized';
                    $this->store[$key] = (string) json_encode($d, JSON_THROW_ON_ERROR);

                    return 1;
                }

                if (str_contains((string) $script, "d.status = 'collision'")) {
                    if ($raw === null) {
                        return 0;
                    }
                    /** @var array<string, mixed> $d */
                    $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (($d['status'] ?? null) !== 'pending') {
                        return 0;
                    }
                    $d['status']       = 'collision';
                    $this->store[$key] = (string) json_encode($d, JSON_THROW_ON_ERROR);

                    return 1;
                }

                if ($raw === null) {
                    return ['expired'];
                }
                /** @var array<string, mixed> $d */
                $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (in_array($d['status'] ?? null, ['authorized', 'collision'], true)) {
                    $nonceHash = $args[1] ?? null;
                    if (! is_string($d['nonceHash'] ?? null) || $d['nonceHash'] !== $nonceHash) {
                        return ['mismatch'];
                    }
                    unset($this->store[$key]);
                    $uid = is_int($d['userId'] ?? null) ? (string) $d['userId'] : '';

                    return [$d['status'], $uid];
                }

                return ['pending'];
            }

            /** @return array<string, mixed>|null */
            public function peek(string $code): ?array
            {
                $raw = $this->store['tg:link:' . $code] ?? null;

                return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        };
    }
}
