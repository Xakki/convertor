<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\ContextEnricher;
use Xakki\LogSymfony\FileTrace;
use Xakki\LogSymfony\Formatters\CustomFormatter;
use Xakki\LogSymfony\LoggerConfig;
use Xakki\LogSymfony\Processor\ContextEnrichProcessor;
use Xakki\LogSymfony\Processor\ExtraProcessor;
use Xakki\LogSymfony\Processor\RemoteIpProcessor;
use Xakki\LogSymfony\Redactor;
use Xakki\LogSymfony\RequestId;

final class StructuredLoggingHandlerTest extends TestCase
{
    public function testConfiguredJsonHandlerKeepsTypedSafeAnonymousContextOnly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'convertor-log-');
        self::assertIsString($path);

        $previousServer             = $_SERVER;
        $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
        $_SERVER['HTTP_REQUEST_ID'] = 'request-123';

        try {
            $sensitiveFieldNeedles = [
                'guest_id', 'user_id', 'user_email', 'request_body', 'user_agent', 'headers',
            ];
            $config = new LoggerConfig(
                extra: [
                    'app_name' => 'convertor',
                    'app_env'  => 'test',
                    'tier'     => 'web',
                ],
                contextTypeRules: ['exact' => ['identity_opaque' => ContextEnricher::CONTEXT_TYPE_BOOL]],
            );
            $enricher = new ContextEnricher(
                $config,
                new Redactor($sensitiveFieldNeedles),
                new FileTrace($config, new Redactor($sensitiveFieldNeedles), dirname(__DIR__, 3)),
                new RequestId(),
            );
            $handler = new StreamHandler($path, Level::Debug);
            $handler->setFormatter(new CustomFormatter('Y-m-d\\TH:i:s.uP', 1400));
            $logger = new Logger('app', [$handler]);
            $logger->pushProcessor(new ExtraProcessor($config));
            $logger->pushProcessor(new ContextEnrichProcessor($enricher));
            $logger->pushProcessor(new RemoteIpProcessor());

            $logger->debug('Anonymous API identity resolved', [
                'auth_identity_type' => 'anonymous_ip',
                'identity_opaque'    => true,
                'guest_id'           => 'guest-secret',
                'user_id'            => 42,
                'token'              => 'token-secret',
                'cookie'             => 'cookie-secret',
                'secret'             => 'app-secret',
                'request_body'       => ['password' => 'body-secret'],
                'user_agent'         => 'private-agent',
                'headers'            => ['X-Private' => 'header-secret'],
            ]);

            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('anonymous_ip', $record['context']['auth_identity_type']);
            self::assertTrue($record['context']['identity_opaque']);
            self::assertSame('203.0.113.7', $record['context']['remote_ip']);
            self::assertSame('request-123', $record['context']['request_id']);
            self::assertIsInt($record['context']['message_len']);
            foreach ([
                'guest_id', 'user_id', 'user_email', 'token', 'authorization', 'cookie',
                'secret', 'request_body', 'user_agent', 'headers',
            ] as $forbiddenField) {
                self::assertTrue(
                    ! array_key_exists($forbiddenField, $record['context'])
                    || $record['context'][$forbiddenField] === '***',
                    sprintf('Sensitive field %s must be omitted or masked.', $forbiddenField),
                );
            }
            self::assertSame('convertor', $record['extra']['app_name']);
        } finally {
            $_SERVER = $previousServer;
            unlink($path);
        }
    }
}
