<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Queue\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Гоняет state-machine one-time кодов (pairing + poll, nonce-only) против
 * in-memory \Redis-дубля, который переиспользует REDEEM_LUA/AUTHORIZE_LUA в PHP.
 * Покрывает mint/authorize/redeem и security-инварианты:
 *  - pending не гасит код;
 *  - authorized + nonce совпал → гасит one-time;
 *  - НЕВЕРНЫЙ nonce → mismatch, код НЕ сожжён (fixation отбит);
 *  - authorize сохраняет nonceHash;
 *  - status-guard: второй authorize уже-authorized кода → false (не перепривязка).
 */
final class TelegramLoginCodeStoreTest extends TestCase
{
    private const USER_ID = 77;

    public function testMintReturnsCodeAndNonceAndStoresPending(): void
    {
        $redis = $this->makeRedis();
        $store = $this->makeStore($redis);

        $minted = $store->mint();
        self::assertNotSame('', $minted['code']);
        self::assertNotSame('', $minted['nonce']);
        self::assertNotSame($minted['code'], $minted['nonce'], 'code и nonce — разные секреты');
        // base64url(32 байта) = 43 симв., влезает в 64-байтный callback_data.
        self::assertLessThanOrEqual(64, strlen($minted['code']));

        $stored = $redis->peek($minted['code']);
        self::assertNotNull($stored);
        self::assertSame(TelegramLoginCodeStore::STATUS_PENDING, $stored['status']);
        self::assertNull($stored['userId']);
        // В Redis только хэш nonce, не сырой nonce. linkSecretHash больше нет.
        self::assertSame(hash('sha256', $minted['nonce']), $stored['nonceHash']);
        self::assertNotSame($minted['nonce'], $stored['nonceHash']);
        self::assertArrayNotHasKey('linkSecretHash', $stored);
    }

    public function testRedeemPendingReturnsPendingAndKeepsCode(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();

        $result = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLoginCodeStore::STATUS_PENDING, $result['status']);
        self::assertNull($result['userId']);
        self::assertNotNull($redis->peek($minted['code']), 'pending redeem must NOT delete the code');
    }

    public function testAuthorizeStoresUserPreservingNonce(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();

        self::assertTrue($store->authorize($minted['code'], self::USER_ID));

        $stored = $redis->peek($minted['code']);
        self::assertSame(TelegramLoginCodeStore::STATUS_AUTHORIZED, $stored['status']);
        self::assertSame(self::USER_ID, $stored['userId']);
        // nonceHash сохранён (привязка браузера не потеряна).
        self::assertSame(hash('sha256', $minted['nonce']), $stored['nonceHash']);
        self::assertArrayNotHasKey('linkSecretHash', $stored);
    }

    public function testRedeemWithMatchingNonceBurnsCode(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();

        self::assertTrue($store->authorize($minted['code'], self::USER_ID));

        $result = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLoginCodeStore::STATUS_AUTHORIZED, $result['status']);
        self::assertSame(self::USER_ID, $result['userId']);
        self::assertNull($redis->peek($minted['code']), 'authorized redeem must burn the code (one-time)');
    }

    public function testRedeemWithWrongNonceIsMismatchAndKeepsCode(): void
    {
        // FIXATION-ветка: чужой браузер без nonce инициатора → mismatch, код НЕ сожжён.
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();
        self::assertTrue($store->authorize($minted['code'], self::USER_ID));

        $result = $store->redeem($minted['code'], 'wrong-nonce-from-another-browser');
        self::assertSame(TelegramLoginCodeStore::STATUS_MISMATCH, $result['status']);
        self::assertNotNull($redis->peek($minted['code']), 'wrong nonce must NOT burn the code');

        // Легитимный браузер (верный nonce) всё ещё завершает.
        $ok = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLoginCodeStore::STATUS_AUTHORIZED, $ok['status']);
    }

    public function testSecondAuthorizeAfterAuthorizedIsRejectedByStatusGuard(): void
    {
        // Status-guard: первый тап побеждает. Форварженный/повторный тап (другой
        // userId) НЕ перепривязывает код → authorize возвращает false.
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();

        self::assertTrue($store->authorize($minted['code'], self::USER_ID));

        self::assertFalse(
            $store->authorize($minted['code'], 999),
            'second authorize must be rejected (first tap wins)',
        );

        // Код по-прежнему привязан к первому пользователю.
        self::assertSame(self::USER_ID, $redis->peek($minted['code'])['userId']);
    }

    public function testSecondRedeemAfterAuthorizedIsExpired(): void
    {
        $redis  = $this->makeRedis();
        $store  = $this->makeStore($redis);
        $minted = $store->mint();
        self::assertTrue($store->authorize($minted['code'], self::USER_ID));

        $store->redeem($minted['code'], $minted['nonce']); // first redeem burns it

        $second = $store->redeem($minted['code'], $minted['nonce']);
        self::assertSame(TelegramLoginCodeStore::STATUS_EXPIRED, $second['status']);
        self::assertNull($second['userId']);
    }

    public function testRedeemUnknownCodeIsExpired(): void
    {
        $store  = $this->makeStore($this->makeRedis());
        $result = $store->redeem('does-not-exist', 'any-nonce');
        self::assertSame(TelegramLoginCodeStore::STATUS_EXPIRED, $result['status']);
    }

    public function testAuthorizeUnknownCodeReturnsFalse(): void
    {
        $store = $this->makeStore($this->makeRedis());
        self::assertFalse($store->authorize('never-minted', self::USER_ID));
    }

    private function makeStore(object $redis): TelegramLoginCodeStore
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

        return new TelegramLoginCodeStore($factory, 300);
    }

    /**
     * In-memory \Redis-дубль: SET (NX/KEEPTTL/EX), EVAL(REDEEM_LUA/AUTHORIZE_LUA),
     * peek(). eval различает скрипты по маркеру: AUTHORIZE_LUA переписывает статус,
     * REDEEM_LUA гейтит nonce.
     */
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

            public function exists($key, ...$other_keys): \Redis|int|bool
            {
                return isset($this->store[$key]) ? 1 : 0;
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

                // AUTHORIZE_LUA: только из pending; сохраняет nonceHash + KEEPTTL,
                // добавляет userId.
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
                    $d['userId']       = (int) $args[1];
                    $this->store[$key] = (string) json_encode($d, JSON_THROW_ON_ERROR);

                    return 1;
                }

                // REDEEM_LUA: nonce-гейт + one-time burn.
                if ($raw === null) {
                    return ['expired'];
                }
                /** @var array<string, mixed> $d */
                $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (($d['status'] ?? null) === 'authorized') {
                    $nonceHash = $args[1] ?? null;
                    if (! is_string($d['nonceHash'] ?? null) || $d['nonceHash'] !== $nonceHash) {
                        return ['mismatch'];
                    }
                    unset($this->store[$key]);
                    $uid = is_int($d['userId'] ?? null) ? (string) $d['userId'] : '';

                    return ['authorized', $uid];
                }

                return ['pending'];
            }

            /** @return array<string, mixed>|null */
            public function peek(string $code): ?array
            {
                $raw = $this->store['tg:login:' . $code] ?? null;

                return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        };
    }
}
