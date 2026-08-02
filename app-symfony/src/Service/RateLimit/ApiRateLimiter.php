<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-IP + per-identity rate limits for convert/quota (CNV-34).
 *
 * Guests: anon_* by IP; if guestId present — also anon_* by guest:{id}.
 * ROLE_USER: user_* by IP and by user:{id}; reject if either exceeded.
 * Coarse /api/v1/* floor — {@see \App\EventListener\ApiIpRateLimitListener}.
 */
final class ApiRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.anon_convert')]
        private readonly RateLimiterFactoryInterface $anonConvertLimiter,
        #[Autowire(service: 'limiter.anon_quota')]
        private readonly RateLimiterFactoryInterface $anonQuotaLimiter,
        #[Autowire(service: 'limiter.user_convert')]
        private readonly RateLimiterFactoryInterface $userConvertLimiter,
        #[Autowire(service: 'limiter.user_quota')]
        private readonly RateLimiterFactoryInterface $userQuotaLimiter,
    ) {
    }

    /**
     * @return JsonResponse|null 429 response when limited, null when accepted
     */
    public function enforceConvert(Request $request, User $user, bool $privileged): ?JsonResponse
    {
        $ip = (string) $request->getClientIp();

        if ($privileged) {
            return $this->consumeBoth(
                $this->userConvertLimiter,
                $ip,
                'user:' . (string) $user->getId(),
                'Too many requests, please try later',
            );
        }

        $denied = $this->consumeOne(
            $this->anonConvertLimiter,
            $ip,
            'Too many anonymous conversions, please try later or log in',
        );
        if ($denied !== null) {
            return $denied;
        }

        $guestId = $user->getGuestId();
        if ($guestId !== null && $guestId !== '') {
            return $this->consumeOne(
                $this->anonConvertLimiter,
                'guest:' . $guestId,
                'Too many anonymous conversions, please try later or log in',
            );
        }

        return null;
    }

    /**
     * @return JsonResponse|null 429 response when limited, null when accepted
     */
    public function enforceQuota(Request $request, User $user, bool $privileged): ?JsonResponse
    {
        $ip = (string) $request->getClientIp();

        if ($privileged) {
            return $this->consumeBoth(
                $this->userQuotaLimiter,
                $ip,
                'user:' . (string) $user->getId(),
                'Too many requests, please try later',
            );
        }

        $denied = $this->consumeOne(
            $this->anonQuotaLimiter,
            $ip,
            'Too many requests, please try later',
        );
        if ($denied !== null) {
            return $denied;
        }

        $guestId = $user->getGuestId();
        if ($guestId !== null && $guestId !== '') {
            return $this->consumeOne(
                $this->anonQuotaLimiter,
                'guest:' . $guestId,
                'Too many requests, please try later',
            );
        }

        return null;
    }

    /**
     * Consume IP and identity keys; reject if either is exceeded.
     */
    private function consumeBoth(
        RateLimiterFactoryInterface $factory,
        string $ipKey,
        string $identityKey,
        string $error,
    ): ?JsonResponse {
        $ipLimit = $factory->create($ipKey)->consume(1);
        if (! $ipLimit->isAccepted()) {
            return $this->tooMany($error, $ipLimit);
        }

        $userLimit = $factory->create($identityKey)->consume(1);
        if (! $userLimit->isAccepted()) {
            return $this->tooMany($error, $userLimit);
        }

        return null;
    }

    private function consumeOne(
        RateLimiterFactoryInterface $factory,
        string $key,
        string $error,
    ): ?JsonResponse {
        $limit = $factory->create($key)->consume(1);
        if (! $limit->isAccepted()) {
            return $this->tooMany($error, $limit);
        }

        return null;
    }

    private function tooMany(string $error, RateLimit $limit): JsonResponse
    {
        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

        return new JsonResponse(
            ['error' => $error],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
