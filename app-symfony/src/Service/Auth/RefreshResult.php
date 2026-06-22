<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Outcome of RefreshTokenService::rotate().
 *
 * cookieValue is the NEW cookie to set on the response; null means "leave the
 * incoming cookie unchanged" (benign replay within the grace window — we only
 * stored hashes, so the rotated secret cannot be reconstructed for the replay
 * caller; the winning rotation already set the new cookie on its own response).
 */
final class RefreshResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?int $userId = null,
        public readonly ?string $cookieValue = null,
    ) {
    }

    public static function invalid(): self
    {
        return new self(false);
    }

    public static function rotated(int $userId, string $cookieValue): self
    {
        return new self(true, $userId, $cookieValue);
    }

    public static function replayed(int $userId): self
    {
        return new self(true, $userId, null);
    }
}
