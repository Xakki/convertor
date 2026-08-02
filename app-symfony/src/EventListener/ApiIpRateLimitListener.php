<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Грубий per-IP пол для публичного API `/api/v1/*` (CNV-34, limiter api_ip).
 *
 * Исключения: каталоги/примеры, internal/worker, webhook, auth start-эндпоинты.
 * Telegram /poll оставляет свой tighter anon_telegram_poll в контроллере.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class ApiIpRateLimitListener
{
    private const API_PREFIX = '/api/v1';

    /** Префиксы/точные пути вне грубого пола. */
    private const EXCLUDED_PREFIXES = [
        '/api/v1/formats',
        '/api/v1/examples',
        '/api/v1/internal',
        '/api/v1/worker',
        '/api/v1/telegram/webhook',
        '/api/v1/auth/telegram/start',
        '/api/v1/auth/sms/request',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.api_ip')]
        private readonly RateLimiterFactoryInterface $apiIpLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();

        if (! str_starts_with($path, self::API_PREFIX)) {
            return;
        }

        if ($this->isExcluded($path)) {
            return;
        }

        $limit = $this->apiIpLimiter->create((string) $request->getClientIp())->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
        $event->setResponse(new JsonResponse(
            ['error' => 'Too many requests, please try later'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        ));
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // OAuth: GET /api/v1/auth/oauth/{provider}/start — должен оставаться открытым.
        if (preg_match('#^/api/v1/auth/oauth/[^/]+/start$#', $path) === 1) {
            return true;
        }

        return false;
    }
}
