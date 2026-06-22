<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds the refresh cookie (set + clear) from env-configured attributes.
 * Host-only (no Domain), HttpOnly, Path scoped to the auth firewall so the
 * cookie is never sent to other API routes. Clearing MUST reuse the same
 * path/secure/sameSite or the browser keeps the cookie.
 */
final class RefreshCookieFactory
{
    public const PATH = '/api/v1/auth';

    public function __construct(
        private readonly string $name = 'refresh_token',
        private readonly bool $secure = true,
        private readonly string $sameSite = Cookie::SAMESITE_LAX,
        private readonly int $maxAge = 2592000,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function create(string $value): Cookie
    {
        return Cookie::create(
            $this->name,
            $value,
            time() + $this->maxAge,
            self::PATH,
            null,
            $this->secure,
            true,
            false,
            $this->sameSite(),
        );
    }

    public function clear(): Cookie
    {
        return Cookie::create(
            $this->name,
            null,
            1,
            self::PATH,
            null,
            $this->secure,
            true,
            false,
            $this->sameSite(),
        );
    }

    /** @return ''|'lax'|'none'|'strict' */
    private function sameSite(): string
    {
        return match (strtolower($this->sameSite)) {
            'none'   => Cookie::SAMESITE_NONE,
            'strict' => Cookie::SAMESITE_STRICT,
            ''       => '',
            default  => Cookie::SAMESITE_LAX,
        };
    }
}
