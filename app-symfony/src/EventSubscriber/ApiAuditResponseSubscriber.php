<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\DTO\ApiAuditEvent;
use App\DTO\ApiAuditIdentityType;
use App\DTO\ApiAuditTokenMetadata;
use App\Service\Audit\ApiAuditWriterInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiAuditResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiAuditWriterInterface $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['record', -1000]];
    }

    public function record(ResponseEvent $event): void
    {
        $request   = $event->getRequest();
        $ownerId   = $request->attributes->get('api_audit_owner_id');
        $label     = $request->attributes->get('api_audit_token_label');
        $startedAt = $request->attributes->get('api_audit_started_at');
        if (! is_int($ownerId) || ! is_string($label) || ! is_float($startedAt)) {
            return;
        }

        $route = $this->normalizedRoute($request->getPathInfo(), $conversionId);
        if ($route === null) {
            return;
        }

        try {
            $token = new ApiAuditTokenMetadata($label, 'cnv_********', ApiAuditIdentityType::PERSONAL_TOKEN);
            $this->writer->append($ownerId, new ApiAuditEvent(
                $request->getMethod(),
                $route,
                $event->getResponse()->getStatusCode(),
                max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                $token,
                $conversionId,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ));
        } catch (\Throwable $exception) {
            // Audit is best-effort: persistence must not replace a completed response.
            try {
                $this->logger->warning('API audit persistence failed', [
                    'method'    => $request->getMethod(),
                    'route'     => $route,
                    'status'    => $event->getResponse()->getStatusCode(),
                    'exception' => get_debug_type($exception),
                ]);
            } catch (\Throwable) {
                // Logging is best-effort too: never replace a completed response.
            }
        }
    }

    private function normalizedRoute(string $path, ?int &$conversionId): ?string
    {
        $conversionId = null;
        if ($path === '/api/v1/quota') {
            return $path;
        }
        if ($path === '/api/v1/convert') {
            return $path;
        }
        if ($path === '/api/v1/convert/history') {
            return $path;
        }
        if (preg_match('#^/api/v1/convert/(\d+)/(status|download|preview)$#D', $path, $matches) !== 1) {
            return null;
        }
        $conversionId = (int) $matches[1];

        return '/api/v1/convert/{id}/' . $matches[2];
    }
}
