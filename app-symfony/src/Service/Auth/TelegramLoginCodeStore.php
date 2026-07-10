<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\Queue\RedisConnectionFactory;

/**
 * One-time коды Telegram-логина по МОДЕЛИ MAGIC-LINK, в KeyDB db 1 (sessions —
 * тот же store, что и refresh-семейства).
 *
 * ДВА независимых секрета обязательны на завершении (закрывают разные атаки):
 *  - `nonce` — браузера-инициатора; в Redis хранится `hash(nonce)`, сырой nonce
 *    в httpOnly-cookie `tg_login_nonce`. Закрывает session-fixation: завершить
 *    вход можно только из того же браузера, что начал.
 *  - `linkSecret` — генерится ПРИ авторизации и уходит ТОЛЬКО в TG-чат
 *    авторизовавшего (в magic-ссылке, query `s`); в Redis хранится
 *    `hash(linkSecret)`. Закрывает account-takeover: атакующий, заминтивший
 *    code+nonce и сфишивший жертву на «Войти», НЕ владеет linkSecret (тот ушёл
 *    в чат жертвы), поэтому его /callback провалится — он не получит JWT жертвы.
 *
 * Ключ `tg:login:{code}` → JSON {status, userId, nonceHash, linkSecretHash}.
 * Жизненный цикл:
 *  - mint:      SET ... EX 300 NX  (status=pending, nonceHash), TTL 5 мин.
 *  - authorize: read-modify-write (Lua, KEEPTTL) ТОЛЬКО из статуса `pending`
 *               (guard: первый тап побеждает — форварженный/повторный тап НЕ
 *               перепривязывает код к другому пользователю) — ставит
 *               status=authorized + userId + linkSecretHash, СОХРАНЯЯ nonceHash.
 *  - redeem:    единый EVAL с презентованными nonceHash+linkSecretHash — GET;
 *               нет ключа → expired; pending → pending (ключ НЕ трогаем);
 *               authorized и ОБА хэша совпали → снять userId, DEL (one-time),
 *               authorized; authorized, но любой хэш не совпал → mismatch БЕЗ
 *               DEL (чужой не DoS-нет легитимный логин — код доживёт до TTL).
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class TelegramLoginCodeStore
{
    private const KEY_PREFIX = 'tg:login:';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_EXPIRED    = 'expired';
    public const STATUS_MISMATCH   = 'mismatch';

    // Атомарный one-time redeem с двойным гейтом (nonce + linkSecret). KEYS[1]=ключ,
    // ARGV[1]=presentedNonceHash, ARGV[2]=presentedLinkSecretHash. Возвращает
    // {status[, userId]}: expired | pending | mismatch | authorized.
    // Сравнение хэшей — plain `~=`: nonce/linkSecret — 256-битные секреты, не
    // подбираются итеративно, timing-side-channel не даёт форжа (accepted
    // defense-in-depth). cjson.null от отсутствующего userId → проверка type=='number'.
    private const REDEEM_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return {'expired'} end
        local ok, d = pcall(cjson.decode, raw)
        if not ok or type(d) ~= 'table' then
            redis.call('DEL', KEYS[1])
            return {'expired'}
        end
        if d.status == 'authorized' then
            -- Двойной гейт ДО гашения: любой mismatch НЕ гасит код (иначе
            -- знающий публичный code атакующий сожжёт чужой логин одним запросом).
            if type(d.nonceHash) ~= 'string' or d.nonceHash ~= ARGV[1] then
                return {'mismatch'}
            end
            if type(d.linkSecretHash) ~= 'string' or d.linkSecretHash ~= ARGV[2] then
                return {'mismatch'}
            end
            redis.call('DEL', KEYS[1])
            local uid = (type(d.userId) == 'number') and tostring(math.floor(d.userId)) or ''
            return {'authorized', uid}
        end
        return {'pending'}
        LUA;

    // Read-modify-write авторизации ТОЛЬКО из pending (status-guard: первый тап
    // побеждает; форварженный/повторный тап уже-authorized кода не перепривязывает).
    // Сохраняет nonceHash + KEEPTTL, добавляет userId + linkSecretHash. KEYS[1]=ключ,
    // ARGV[1]=userId, ARGV[2]=linkSecretHash. Возвращает 1 при успехе, 0 иначе
    // (нет ключа / уже не pending).
    private const AUTHORIZE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end
        local ok, d = pcall(cjson.decode, raw)
        if not ok or type(d) ~= 'table' then
            redis.call('DEL', KEYS[1])
            return 0
        end
        if d.status ~= 'pending' then return 0 end
        d.status = 'authorized'
        d.userId = tonumber(ARGV[1])
        d.linkSecretHash = ARGV[2]
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
     * Сгенерировать новый pending-код + серверный nonce. В Redis кладём только
     * `hash(nonce)`; сырой nonce возвращаем — контроллер положит его в cookie
     * `tg_login_nonce`. base64url(32 байта) = 43 симв. — code влезает в 64-байтный
     * лимит Telegram callback_data.
     *
     * @return array{code: string, nonce: string}
     */
    public function mint(): array
    {
        $code    = $this->newSecret();
        $nonce   = $this->newSecret();
        $payload = json_encode([
            'status'    => self::STATUS_PENDING,
            'userId'    => null,
            'nonceHash' => $this->hashSecret($nonce),
        ], JSON_THROW_ON_ERROR);

        // NX: не перезаписываем существующий (коллизия 256-битного кода невероятна,
        // но mint не должен молча сбросить чужой уже привязанный код).
        $this->redisFactory->create()->set(self::KEY_PREFIX . $code, $payload, ['EX' => $this->ttl, 'NX' => true]);

        return ['code' => $code, 'nonce' => $nonce];
    }

    /**
     * Пометить код authorized (ТОЛЬКО из pending), привязать user.id и сгенерить
     * `linkSecret`: в Redis кладём `hash(linkSecret)`, сырой linkSecret возвращаем —
     * webhook положит его в magic-ссылку (query `s`) и отправит в TG-чат. nonceHash
     * сохраняется, KEEPTTL — исходное 5-минутное окно не продлеваем.
     *
     * Возвращает сырой linkSecret при успехе или null, если код истёк/неизвестен
     * или уже не в статусе pending (первый тап уже победил).
     */
    public function authorize(string $code, int $userId): ?string
    {
        $linkSecret = $this->newSecret();

        $result = $this->redisFactory->create()->eval(
            self::AUTHORIZE_LUA,
            [self::KEY_PREFIX . $code, (string) $userId, $this->hashSecret($linkSecret)],
            1,
        );

        return (int) $result === 1 ? $linkSecret : null;
    }

    /**
     * Атомарно опросить и (при authorized + совпавших nonce И linkSecret) погасить
     * код. $nonce — сырое значение из cookie `tg_login_nonce`; $linkSecret — сырое
     * значение из query `s` magic-ссылки. Оба хэшируем и сверяем внутри eval.
     *
     * @return array{status: string, userId: ?int}
     */
    public function redeem(string $code, string $nonce, string $linkSecret): array
    {
        $result = $this->redisFactory->create()->eval(
            self::REDEEM_LUA,
            [self::KEY_PREFIX . $code, $this->hashSecret($nonce), $this->hashSecret($linkSecret)],
            1,
        );

        if (! is_array($result) || ! isset($result[0]) || ! is_string($result[0])) {
            return ['status' => self::STATUS_EXPIRED, 'userId' => null];
        }

        if ($result[0] === self::STATUS_AUTHORIZED) {
            $userId = isset($result[1]) && (is_string($result[1]) || is_int($result[1])) && (string) $result[1] !== ''
                ? (int) $result[1]
                : null;

            return ['status' => self::STATUS_AUTHORIZED, 'userId' => $userId];
        }

        if (in_array($result[0], [self::STATUS_PENDING, self::STATUS_MISMATCH], true)) {
            return ['status' => $result[0], 'userId' => null];
        }

        return ['status' => self::STATUS_EXPIRED, 'userId' => null];
    }

    /**
     * sha256 сырого секрета (hex). Redis Lua не умеет sha256 — считаем в PHP и
     * передаём хэш в eval (паттерн RefreshTokenService::hash).
     */
    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
