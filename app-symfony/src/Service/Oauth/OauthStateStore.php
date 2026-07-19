<?php

declare(strict_types=1);

namespace App\Service\Oauth;

use App\Service\Queue\RedisConnectionFactory;

/**
 * Одноразовый CSRF-`state` OAuth-флоу в KeyDB db 1 (sessions — тот же store, что
 * refresh-семейства и Telegram-коды). НЕ в `$_SESSION` (см. предупреждение в
 * oauth-04 про сессионный state чужого референс-пакета).
 *
 * Ключ `oauth:state:{state}` → JSON {provider, codeVerifier?}. Жизненный цикл:
 *  - mint:    сгенерить `state` (256-битный секрет), SET ... EX 600 NX. Для
 *             PKCE-провайдеров рядом кладём `codeVerifier`. Payload — открытый
 *             словарь: при появлении VID device_id и т.п. расширяется без миграции.
 *  - consume: атомарный GET+DEL (Lua) — один и тот же `state` нельзя погасить
 *             дважды (replay callback'а отбит). Провайдер из payload сверяется с
 *             провайдером из URL callback'а: не совпал → null (state не от этого
 *             провайдера). Отсутствует/протух → null.
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock.
 */
class OauthStateStore
{
    private const KEY_PREFIX = 'oauth:state:';

    // Атомарный one-time GET+DEL. KEYS[1]=ключ. Возвращает сырой payload-JSON или
    // false (нет ключа). DEL до возврата → повторный consume того же state пуст.
    private const CONSUME_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return false end
        redis.call('DEL', KEYS[1])
        return raw
        LUA;

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly int $ttl = 600,
    ) {
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Создать новый одноразовый `state` для провайдера. Для PKCE-провайдеров
     * `$codeVerifier` не null — сохраняется рядом и вернётся из consume().
     *
     * @return string сырой state, который уйдёт в authorize URL (query `state`)
     */
    public function mint(string $provider, ?string $codeVerifier = null): string
    {
        $state   = $this->newSecret();
        $payload = ['provider' => $provider];
        if ($codeVerifier !== null) {
            $payload['codeVerifier'] = $codeVerifier;
        }

        // NX: не перезаписываем чужой state (коллизия 256-битного секрета невероятна).
        $this->redisFactory->create()->set(
            self::KEY_PREFIX . $state,
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['EX' => $this->ttl, 'NX' => true],
        );

        return $state;
    }

    /**
     * Атомарно погасить `state` (one-time). Возвращает `codeVerifier` (для PKCE)
     * или null при успехе; кидает {@see InvalidOauthStateException}, если state
     * неизвестен/протух/погашен или принадлежит другому провайдеру (CSRF).
     *
     * @throws InvalidOauthStateException
     */
    public function consume(string $state, string $provider): OauthStateData
    {
        if ($state === '') {
            throw new InvalidOauthStateException();
        }

        $raw = $this->redisFactory->create()->eval(self::CONSUME_LUA, [self::KEY_PREFIX . $state], 1);
        if (! is_string($raw) || $raw === '') {
            throw new InvalidOauthStateException();
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['provider'] ?? null) !== $provider) {
            // state принадлежит другому провайдеру или битый JSON → отбой CSRF.
            throw new InvalidOauthStateException();
        }

        $codeVerifier = isset($payload['codeVerifier']) && is_string($payload['codeVerifier'])
            ? $payload['codeVerifier']
            : null;

        return new OauthStateData($codeVerifier);
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
