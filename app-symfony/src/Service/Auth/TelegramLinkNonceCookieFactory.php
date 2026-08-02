<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie `tg_link_nonce` для pairing+poll привязки Telegram (CNV-59).
 * Отдельное имя от `tg_login_nonce`, чтобы login/link не мешали друг другу.
 * path `/api/v1/auth` — cookie уходит на XHR poll под тем же префиксом.
 */
final class TelegramLinkNonceCookieFactory
{
    public const NAME = 'tg_link_nonce';
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
