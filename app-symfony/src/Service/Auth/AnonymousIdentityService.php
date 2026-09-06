<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Derives an opaque owner key for requests without a stronger identity.
 * The source address is read through Symfony's trusted-proxy handling; it is
 * never returned or persisted.
 */
final class AnonymousIdentityService
{
    public function __construct(
        private readonly string $secret,
    ) {
    }

    public function fromRequest(Request $request): ?string
    {
        $clientIp = $request->getClientIp();
        if (! is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packedIp = inet_pton($clientIp);
        if ($packedIp === false) {
            return null;
        }

        $normalizedIp = inet_ntop($packedIp);
        if ($normalizedIp === false) {
            return null;
        }

        return hash_hmac('sha256', $normalizedIp, $this->secret);
    }
}
