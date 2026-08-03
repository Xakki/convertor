<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\Queue\RedisConnectionFactory;

/**
 * One-time коды привязки Telegram к уже залогиненному User (CNV-59).
 *
 * Отдельный префикс ключей `tg:link:` — не пересекается с login (`tg:login:`).
 * UserId биндится при mint (текущий JWT-User); webhook НЕ вызывает findOrCreateUser
 * и НЕ переключает сессию — только ставит telegram_id на сохранённый UserId.
 *
 * Ключ `tg:link:{code}` → JSON {status, userId, nonceHash}.
 *  - mint:           pending + userId (bound) + nonceHash, TTL 5 мин.
 *  - markAuthorized: pending → authorized (KEEPTTL), userId не меняется.
 *  - markCollision:  pending → collision (KEEPTTL) — telegram_id занят другим User.
 *  - redeem:         как login: authorized+nonce → burn; collision+nonce → burn+collision;
 *                    mismatch не гасит код.
 *
 * Не final — функциональные тесты подменяют через createMock.
 */
class TelegramLinkCodeStore
{
    private const KEY_PREFIX = 'tg:link:';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_COLLISION  = 'collision';
    public const STATUS_EXPIRED    = 'expired';
    public const STATUS_MISMATCH   = 'mismatch';

    // KEYS[1]=ключ, ARGV[1]=presentedNonceHash.
    // Возвращает {status[, userId]}: expired|pending|mismatch|authorized|collision.
    private const REDEEM_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return {'expired'} end
        local ok, d = pcall(cjson.decode, raw)
        if not ok or type(d) ~= 'table' then
            redis.call('DEL', KEYS[1])
            return {'expired'}
        end
        if d.status == 'authorized' or d.status == 'collision' then
            if type(d.nonceHash) ~= 'string' or d.nonceHash ~= ARGV[1] then
                return {'mismatch'}
            end
            redis.call('DEL', KEYS[1])
            local uid = (type(d.userId) == 'number') and tostring(math.floor(d.userId)) or ''
            return {d.status, uid}
        end
        return {'pending'}
        LUA;

    // pending → authorized, KEEPTTL, userId не трогаем.
    private const MARK_AUTHORIZED_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end
        local ok, d = pcall(cjson.decode, raw)
        if not ok or type(d) ~= 'table' then
            redis.call('DEL', KEYS[1])
            return 0
        end
        if d.status ~= 'pending' then return 0 end
        d.status = 'authorized'
        redis.call('SET', KEYS[1], cjson.encode(d), 'KEEPTTL')
        return 1
        LUA;

    // pending → collision, KEEPTTL.
    private const MARK_COLLISION_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end
        local ok, d = pcall(cjson.decode, raw)
        if not ok or type(d) ~= 'table' then
            redis.call('DEL', KEYS[1])
            return 0
        end
        if d.status ~= 'pending' then return 0 end
        d.status = 'collision'
        redis.call('SET', KEYS[1], cjson.encode(d), 'KEEPTTL')
        return 1
        LUA;

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly int $ttl = 300,
    ) {
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * @return array{code: string, nonce: string}
     */
    public function mint(int $userId): array
    {
        $code    = $this->newSecret();
        $nonce   = $this->newSecret();
        $payload = json_encode([
            'status'    => self::STATUS_PENDING,
            'userId'    => $userId,
            'nonceHash' => $this->hashSecret($nonce),
        ], JSON_THROW_ON_ERROR);

        $this->redisFactory->create()->set(self::KEY_PREFIX . $code, $payload, ['EX' => $this->ttl, 'NX' => true]);

        return ['code' => $code, 'nonce' => $nonce];
    }

    /**
     * UserId, привязанный к коду (pending/authorized/collision). null — нет ключа / мусор.
     */
    public function peekUserId(string $code): ?int
    {
        $raw = $this->redisFactory->create()->get(self::KEY_PREFIX . $code);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $d */
            $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $uid = $d['userId'] ?? null;

        return is_int($uid) ? $uid : (is_numeric($uid) ? (int) $uid : null);
    }

    /** pending → authorized. false если код истёк / уже не pending. */
    public function markAuthorized(string $code): bool
    {
        $result = $this->redisFactory->create()->eval(
            self::MARK_AUTHORIZED_LUA,
            [self::KEY_PREFIX . $code],
            1,
        );

        return (int) $result === 1;
    }

    /** pending → collision (telegram_id занят). */
    public function markCollision(string $code): bool
    {
        $result = $this->redisFactory->create()->eval(
            self::MARK_COLLISION_LUA,
            [self::KEY_PREFIX . $code],
            1,
        );

        return (int) $result === 1;
    }

    /**
     * @return array{status: string, userId: ?int}
     */
    public function redeem(string $code, string $nonce): array
    {
        $result = $this->redisFactory->create()->eval(
            self::REDEEM_LUA,
            [self::KEY_PREFIX . $code, $this->hashSecret($nonce)],
            1,
        );

        if (! is_array($result) || ! isset($result[0]) || ! is_string($result[0])) {
            return ['status' => self::STATUS_EXPIRED, 'userId' => null];
        }

        if (in_array($result[0], [self::STATUS_AUTHORIZED, self::STATUS_COLLISION], true)) {
            $userId = isset($result[1]) && (is_string($result[1]) || is_int($result[1])) && (string) $result[1] !== ''
                ? (int) $result[1]
                : null;

            return ['status' => $result[0], 'userId' => $userId];
        }

        if (in_array($result[0], [self::STATUS_PENDING, self::STATUS_MISMATCH], true)) {
            return ['status' => $result[0], 'userId' => null];
        }

        return ['status' => self::STATUS_EXPIRED, 'userId' => null];
    }

    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function newSecret(): string
    {
        // Не пересекаться с deep-link префиксами webhook (`link_`, `pay_`).
        do {
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } while (str_starts_with($secret, 'link_') || str_starts_with($secret, 'pay_'));

        return $secret;
    }
}
