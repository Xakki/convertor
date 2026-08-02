<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiIpRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * CNV-34: coarse api_ip floor + exclusion paths.
 */
final class ApiIpRateLimitListenerTest extends TestCase
{
    public function testRejectsWhenIpExceeded(): void
    {
        $listener = $this->listener(limit: 1);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $ok = $this->dispatch($listener, $kernel, '/api/v1/convert', '10.9.0.1');
        self::assertNull($ok->getResponse());

        $denied = $this->dispatch($listener, $kernel, '/api/v1/me', '10.9.0.1');
        $res    = $denied->getResponse();
        self::assertNotNull($res);
        self::assertSame(429, $res->getStatusCode());
        self::assertSame(
            ['error' => 'Too many requests, please try later'],
            json_decode((string) $res->getContent(), true),
        );
        self::assertTrue($res->headers->has('Retry-After'));
    }

    public function testExcludesFormatsAndInternal(): void
    {
        $listener = $this->listener(limit: 1);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        // Exhaust bucket on a limited path first.
        $this->dispatch($listener, $kernel, '/api/v1/convert', '10.9.0.2');

        foreach (['/api/v1/formats', '/api/v1/examples', '/api/v1/internal/worker/result', '/api/v1/worker/register', '/api/v1/telegram/webhook', '/api/v1/auth/telegram/start', '/api/v1/auth/oauth/google/start'] as $path) {
            $event = $this->dispatch($listener, $kernel, $path, '10.9.0.2');
            self::assertNull($event->getResponse(), "path {$path} must be excluded from api_ip");
        }
    }

    public function testIgnoresNonApiPaths(): void
    {
        $listener = $this->listener(limit: 1);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $event = $this->dispatch($listener, $kernel, '/dashboard', '10.9.0.3');
        self::assertNull($event->getResponse());
    }

    private function listener(int $limit): ApiIpRateLimitListener
    {
        $factory = new RateLimiterFactory(
            [
                'id'       => 'api_ip',
                'policy'   => 'sliding_window',
                'limit'    => $limit,
                'interval' => '1 minute',
            ],
            new InMemoryStorage(),
        );

        return new ApiIpRateLimitListener($factory);
    }

    private function dispatch(
        ApiIpRateLimitListener $listener,
        HttpKernelInterface $kernel,
        string $path,
        string $ip,
    ): RequestEvent {
        $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip]);
        $event   = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        return $event;
    }
}
