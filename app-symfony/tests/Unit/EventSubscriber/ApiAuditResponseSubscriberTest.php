<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ApiAuditResponseSubscriber;
use App\Service\Audit\ApiAuditWriterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiAuditResponseSubscriberTest extends TestCase
{
    #[DataProvider('completedResponseStatuses')]
    public function testPersistsSuccessfulAndCompletedErrorResponses(int $status): void
    {
        $writer = $this->createMock(ApiAuditWriterInterface::class);
        $writer->expects(self::once())->method('append');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $request = $this->requestWithAuditAttributes();
        (new ApiAuditResponseSubscriber($writer, $logger))->record(
            new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('body', $status)),
        );
    }

    public function testWriterFailureIsContainedAndResponseIsUnchanged(): void
    {
        $writer = $this->createMock(ApiAuditWriterInterface::class);
        $writer->method('append')->willThrowException(new \RuntimeException('secret payload must not be logged'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'API audit persistence failed',
            self::callback(static function (array $context): bool {
                return $context['method']    === 'GET'
                    && $context['route']     === '/api/v1/quota'
                    && $context['status']    === 500
                    && $context['exception'] === 'RuntimeException'
                    && ! array_key_exists('owner_id', $context)
                    && ! array_key_exists('token_label', $context)
                    && ! array_key_exists('headers', $context)
                    && ! array_key_exists('body', $context);
            }),
        );

        $response = new Response('completed response', 500);
        (new ApiAuditResponseSubscriber($writer, $logger))->record(
            new ResponseEvent($this->kernel(), $this->requestWithAuditAttributes(), HttpKernelInterface::MAIN_REQUEST, $response),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('completed response', $response->getContent());
    }

    public function testLoggerFailureIsContainedAndResponseIsUnchanged(): void
    {
        $writer = $this->createMock(ApiAuditWriterInterface::class);
        $writer->method('append')->willThrowException(new \RuntimeException('persistence failure'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new \RuntimeException('logger failure'));

        $response = new Response('completed response', 200);
        (new ApiAuditResponseSubscriber($writer, $logger))->record(
            new ResponseEvent($this->kernel(), $this->requestWithAuditAttributes(), HttpKernelInterface::MAIN_REQUEST, $response),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('completed response', $response->getContent());
    }

    public function testRequestsWithoutPersonalTokenAttributesAreIgnored(): void
    {
        $writer = $this->createMock(ApiAuditWriterInterface::class);
        $writer->expects(self::never())->method('append');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $request = Request::create('/api/v1/quota', 'GET');
        (new ApiAuditResponseSubscriber($writer, $logger))->record(
            new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('{}', 200)),
        );
    }

    public function testAuditEventTimestampIsExplicitlyUtc(): void
    {
        $writer = $this->createMock(ApiAuditWriterInterface::class);
        $writer->expects(self::once())->method('append')->with(
            7,
            self::callback(static fn (\App\DTO\ApiAuditEvent $event): bool => $event->createdAt->getTimezone()->getName() === 'UTC'),
        );
        $logger = $this->createMock(LoggerInterface::class);

        (new ApiAuditResponseSubscriber($writer, $logger))->record(
            new ResponseEvent($this->kernel(), $this->requestWithAuditAttributes(), HttpKernelInterface::MAIN_REQUEST, new Response('{}', 200)),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function completedResponseStatuses(): iterable
    {
        yield 'success' => [200];
        yield 'client error' => [404];
        yield 'server error' => [500];
    }

    private function requestWithAuditAttributes(): Request
    {
        $request = Request::create('/api/v1/quota', 'GET');
        $request->attributes->set('api_audit_owner_id', 7);
        $request->attributes->set('api_audit_token_label', 'build');
        $request->attributes->set('api_audit_started_at', microtime(true));

        return $request;
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createMock(HttpKernelInterface::class);
    }
}
