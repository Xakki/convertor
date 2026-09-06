<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OpenApiDocumentationCacheSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['setCacheHeaders', -100]];
    }

    public function setCacheHeaders(ResponseEvent $event): void
    {
        $request  = $event->getRequest();
        $path     = $request->getPathInfo();
        $response = $event->getResponse();

        if (str_starts_with($path, '/api/user/doc') || str_starts_with($path, '/api/admin/doc')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Vary', 'Authorization', false);

            return;
        }

        if ($path === '/api/doc' || $path === '/api/doc.json') {
            $response->headers->set('Cache-Control', 'public, max-age=300, must-revalidate');
        }
    }
}
