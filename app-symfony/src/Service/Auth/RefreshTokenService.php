<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Service\Queue\RedisConnectionFactory;

/**
 * Opaque refresh-token families stored in KeyDB (db 1, sessions).
 *
 * Key `rt:{familyId}` → JSON {userId, secretHash, prevSecretHash, graceUntil, exp}.
 * Cookie value handed to the client is `{familyId}.{secret}`; only hashes live in
 * the store. Rotation is rotate-on-use with a prev-hash grace window so concurrent
 * or retried refreshes don't trip reuse-detection. `exp` is the family's absolute
 * lifetime — rotation preserves it (TTL = exp-now), never extends it.
 *
 * The load-compare-rotate is a single EVAL so two concurrent rotations cannot race.
 */
final class RefreshTokenService
{
    private const KEY_PREFIX = 'rt:';

    // Atomic compare-and-rotate. KEYS[1]=key, ARGV: 1=presentedHash, 2=newHash,
    // 3=now, 4=grace. Returns {status[, userId]} with status in
    // rotated|replay|invalid|reuse. cjson `null` decodes to a truthy cjson.null,
    // hence the explicit type=='string' guard on prevSecretHash.
    private const ROTATE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return {'invalid'} end
        local ok, d = pcall(cjson.decode, raw)
        local exp = ok and type(d) == 'table' and tonumber(d.exp) or nil
        if not exp then
            -- corrupt/shape-mismatched value: heal by revoking, never wedge on 500
            redis.call('DEL', KEYS[1])
            return {'invalid'}
        end
        local now = tonumber(ARGV[3])
        local ttl = exp - now
        if ttl <= 0 then
            redis.call('DEL', KEYS[1])
            return {'invalid'}
        end
        if d.secretHash == ARGV[1] then
            d.prevSecretHash = d.secretHash
            d.secretHash = ARGV[2]
            d.graceUntil = now + tonumber(ARGV[4])
            redis.call('SET', KEYS[1], cjson.encode(d), 'EX', tostring(math.floor(ttl)))
            return {'rotated', tostring(d.userId)}
        elseif type(d.prevSecretHash) == 'string' and d.prevSecretHash == ARGV[1] and now < tonumber(d.graceUntil) then
            return {'replay', tostring(d.userId)}
        else
            redis.call('DEL', KEYS[1])
            return {'reuse'}
        end
        LUA;

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly string $appSecret,
        private readonly int $ttl = 2592000,
        private readonly int $grace = 60,
    ) {
        // Fail fast: an empty pepper silently neuters the HMAC.
        if (trim($appSecret) === '') {
            throw new \InvalidArgumentException('RefreshTokenService requires a non-empty app secret (HMAC pepper).');
        }
    }

    /** Create a fresh family and return the cookie value `{familyId}.{secret}`. */
    public function issueFamily(User $user): string
    {
        $familyId = $this->uuidV4();
        $secret   = $this->newSecret();
        $now      = time();

        $payload = json_encode([
            'userId'         => $user->getId(),
            'secretHash'     => $this->hash($secret),
            'prevSecretHash' => null,
            'graceUntil'     => 0,
            'exp'            => $now + $this->ttl,
        ], JSON_THROW_ON_ERROR);

        $this->redisFactory->create()->set(self::KEY_PREFIX . $familyId, $payload, ['EX' => $this->ttl]);

        return $familyId . '.' . $secret;
    }

    public function rotate(string $cookieValue): RefreshResult
    {
        [$familyId, $secret] = $this->parse($cookieValue);
        if ($familyId === null || $secret === null) {
            return RefreshResult::invalid();
        }

        $newSecret = $this->newSecret();
        $result    = $this->redisFactory->create()->eval(
            self::ROTATE_LUA,
            [
                self::KEY_PREFIX . $familyId,
                $this->hash($secret),
                $this->hash($newSecret),
                (string) time(),
                (string) $this->grace,
            ],
            1,
        );

        if (! is_array($result) || ! isset($result[0]) || ! is_string($result[0])) {
            return RefreshResult::invalid();
        }

        $userId = isset($result[1]) && (is_string($result[1]) || is_int($result[1])) ? (int) $result[1] : 0;

        return match ($result[0]) {
            'rotated' => RefreshResult::rotated($userId, $familyId . '.' . $newSecret),
            'replay'  => RefreshResult::replayed($userId),
            default   => RefreshResult::invalid(),
        };
    }

    /** Revoke the whole family (logout, or reuse fallout). Idempotent. */
    public function revoke(string $cookieValue): void
    {
        [$familyId] = $this->parse($cookieValue);
        if ($familyId === null) {
            return;
        }

        $this->redisFactory->create()->del(self::KEY_PREFIX . $familyId);
    }

    /**
     * @return array{0: ?string, 1: ?string} [familyId, secret] or [null, null]
     */
    private function parse(string $cookieValue): array
    {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    private function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->appSecret);
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
