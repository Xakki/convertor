<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\TelegramBotClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Гоняет TelegramBotClient на MockHttpClient — без реальной сети. Проверяет
 * корректный URL (bot<TOKEN>/<method>), JSON-тело запроса и разбор ответа.
 */
final class TelegramBotClientTest extends TestCase
{
    private const TOKEN = '123:ABCDEF';

    public function testSendMessagePostsToCorrectUrlWithPayload(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => 5]]));
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $result = $client->sendMessage(42, 'привет', ['inline_keyboard' => [[['text' => 'Войти', 'callback_data' => 'login:abc']]]]);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.telegram.org/bot123:ABCDEF/sendMessage', $captured['url']);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(42, $body['chat_id']);
        self::assertSame('привет', $body['text']);
        self::assertSame('login:abc', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertTrue($result['ok']);
    }

    public function testAnswerCallbackQueryHitsCorrectMethod(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse((string) json_encode(['ok' => true]));
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $client->answerCallbackQuery('cbid-1', 'Готово');

        self::assertSame('https://api.telegram.org/bot123:ABCDEF/answerCallbackQuery', $captured);
    }

    public function testSetWebhookSendsUrlAndSecret(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode(['ok' => true]));
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $client->setWebhook('https://example.test/api/v1/telegram/webhook', 's3cr3t');

        self::assertSame('https://api.telegram.org/bot123:ABCDEF/setWebhook', $captured['url']);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://example.test/api/v1/telegram/webhook', $body['url']);
        self::assertSame('s3cr3t', $body['secret_token']);
    }

    public function testGetUserProfilePhotosHitsCorrectMethod(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['total_count' => 1, 'photos' => []]]));
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $client->getUserProfilePhotos(12345);

        self::assertSame('https://api.telegram.org/bot123:ABCDEF/getUserProfilePhotos', $captured['url']);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(12345, $body['user_id']);
        self::assertSame(1, $body['limit']);
    }

    public function testGetFileHitsCorrectMethod(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]));
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $result = $client->getFile('FILE-ID');

        self::assertSame('https://api.telegram.org/bot123:ABCDEF/getFile', $captured['url']);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FILE-ID', $body['file_id']);
        self::assertSame('photos/file_1.jpg', $result['result']['file_path']);
    }

    public function testDownloadFileUsesFileEndpointAndReturnsBytes(): void
    {
        $captured = null;
        $http     = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse('RAW-BYTES');
        });

        $client = new TelegramBotClient($http, self::TOKEN, new NullLogger());
        $bytes  = $client->downloadFile('photos/file_1.jpg');

        self::assertSame('GET', $captured['method']);
        self::assertSame('https://api.telegram.org/file/bot123:ABCDEF/photos/file_1.jpg', $captured['url']);
        self::assertSame('RAW-BYTES', $bytes);
    }

    public function testDownloadFileReturnsNullAndScrubsTokenOnError(): void
    {
        $records = [];
        $logger  = new class ($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(public array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            throw new TransportException('boom for ' . $url);
        });

        $client = new TelegramBotClient($http, self::TOKEN, $logger);
        $bytes  = $client->downloadFile('photos/file_1.jpg');

        self::assertNull($bytes);
        self::assertNotEmpty($records);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($records));
    }

    public function testEmptyTokenThrows(): void
    {
        $client = new TelegramBotClient(new MockHttpClient(), '', new NullLogger());
        $this->expectException(\RuntimeException::class);
        $client->sendMessage(1, 'x');
    }

    /**
     * На transport-ошибке message исключения несёт эффективный URL с токеном в
     * path. Проверяем, что залогированное сообщение СКРАБЛЕНО (нет токена), а
     * проброшенное исключение НЕ цепляет исходное (его chain тоже несёт URL).
     */
    public function testTransportErrorScrubsTokenFromLogAndDoesNotChainRawException(): void
    {
        $records = [];
        $logger  = new class ($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(public array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // MockHttpClient бросает transport-ошибку, чей текст содержит URL с токеном.
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            throw new TransportException('Could not resolve host for ' . $url);
        });

        $client = new TelegramBotClient($http, self::TOKEN, $logger);

        try {
            $client->sendMessage(1, 'x');
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            // Проброшенное исключение не цепляет исходное (нет утечки URL через chain).
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertNotEmpty($records, 'ошибка должна логироваться');
        $logged = (string) json_encode($records);
        self::assertStringNotContainsString(self::TOKEN, $logged, 'токен НЕ должен утечь в лог');
        self::assertStringContainsString('<redacted>', $records[0]['context']['error']);
    }
}
