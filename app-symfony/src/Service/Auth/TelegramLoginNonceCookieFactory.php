<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Строит cookie `tg_login_nonce` (выставление + гашение) для магик-линк логина.
 *
 * httpOnly, Secure, SameSite=Lax, TTL 5 мин, path `/api/v1/auth` — чтобы cookie
 * ушла при top-level GET-навигации браузера на `/api/v1/auth/telegram/callback`
 * (SameSite=Lax это разрешает). Значение — сырой nonce; на сервере с ним
 * сверяется сохранённый `hash(nonce)`. Same-device — принятый компромисс:
 * логин завершается в том же браузере, где начали (закрывает session-fixation).
 * Гашение обязано повторять path/secure/sameSite, иначе браузер не удалит cookie.
 */
final class TelegramLoginNonceCookieFactory
{
    public const NAME = 'tg_login_nonce';
    public const PATH = '/api/v1/auth';

    public function __construct(
        private readonly bool $secure = true,
        private readonly int $maxAge = 300,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function create(string $nonce): Cookie
    {
        return Cookie::create(
            self::NAME,
            $nonce,
            time() + $this->maxAge,
            self::PATH,
            null,
            $this->secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public function clear(): Cookie
    {
        return Cookie::create(
            self::NAME,
            null,
            1,
            self::PATH,
            null,
            $this->secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
