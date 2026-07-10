<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Строит cookie `guest_id` (выставление + гашение).
 *
 * httpOnly, Secure, SameSite=Lax, TTL 30 дней, path `/` (в отличие от
 * refresh-cookie на `/api/v1/auth`), чтобы cookie уходила и на `/api/v1/convert`,
 * и на download/status. Значение — подписанное HMAC (см. GuestTokenService).
 * Гашение (`clear`) обязано повторять path/secure/sameSite, иначе браузер
 * не удалит cookie.
 */
final class GuestCookieFactory
{
    public const NAME = 'guest_id';
    public const PATH = '/';

    public function __construct(
        private readonly bool $secure = true,
        private readonly int $maxAge = 2592000,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function create(string $signedValue): Cookie
    {
        return Cookie::create(
            self::NAME,
            $signedValue,
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
