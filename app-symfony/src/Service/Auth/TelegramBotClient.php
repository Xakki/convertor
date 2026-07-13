<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тонкий клиент Telegram Bot API (https://api.telegram.org/bot<TOKEN>/<method>).
 *
 * Не final — функциональные тесты подменяют его в контейнере через createMock,
 * а createMock не умеет мокать final. Сетевой слой — HttpClientInterface, юнит-
 * тесты гоняют его на MockHttpClient/MockResponse (без реальной сети).
 */
class TelegramBotClient
{
    private const API_BASE  = 'https://api.telegram.org/bot';
    private const FILE_BASE = 'https://api.telegram.org/file/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private readonly string $telegramBotToken,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     *
     * @return array<string, mixed>
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendMessage', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $payload['text'] = $text;
        }

        return $this->call('answerCallbackQuery', $payload);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     *
     * @return array<string, mixed>
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): array
    {
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageReplyMarkup', $payload);
    }

    /**
     * getUserProfilePhotos — список фото профиля пользователя. Апдейты Telegram
     * НИКОГДА не несут аватар, его берём только этим методом. `limit=1` — нам
     * нужна лишь текущая (первая) фотография. Ответ: result.photos[i] — массив
     * PhotoSize (разные разрешения одного фото).
     *
     * @return array<string, mixed>
     */
    public function getUserProfilePhotos(int|string $telegramId, int $limit = 1): array
    {
        return $this->call('getUserProfilePhotos', ['user_id' => $telegramId, 'limit' => $limit]);
    }

    /**
     * getFile — по file_id возвращает File с `file_path` для скачивания. Сам
     * file_path НЕ содержит токена, но URL скачивания (см. downloadFile) — да.
     *
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * Скачивает содержимое файла по `file_path` из getFile. URL скачивания —
     * `…/file/bot<TOKEN>/<path>` — несёт bot-токен, поэтому ошибки скрабим (как в
     * call()) и НЕ пробрасываем исходное исключение (его chain тоже несёт URL).
     * Возвращает сырые байты; null — если файл недоступен/скачивание не удалось.
     */
    public function downloadFile(string $filePath): ?string
    {
        if (trim($this->telegramBotToken) === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                self::FILE_BASE . $this->telegramBotToken . '/' . ltrim($filePath, '/'),
            );

            return $response->getContent();
        } catch (ExceptionInterface $e) {
            $this->logger->error('Telegram file download failed', [
                'error' => $this->scrubToken($e->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secretToken,
            'allowed_updates'      => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        if (trim($this->telegramBotToken) === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::API_BASE . $this->telegramBotToken . '/' . $method,
                ['json' => $payload],
            );

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            // Токен бота вшит в path URL, а Symfony-исключения включают
            // эффективный URL в message → без скраба он утёк бы в логи/Graylog.
            // Логируем скрабленное сообщение и НЕ пробрасываем исходное
            // исключение (его chain тоже несёт сырой URL глобальному хендлеру).
            $this->logger->error('Telegram Bot API call failed', [
                'method' => $method,
                'error'  => $this->scrubToken($e->getMessage()),
            ]);

            throw new \RuntimeException('Telegram Bot API call failed: ' . $method);
        }

        if (($data['ok'] ?? false) !== true) {
            $this->logger->warning('Telegram Bot API returned not-ok', ['method' => $method, 'response' => $data]);
        }

        return $data;
    }

    /**
     * Заменяет подстроку bot-токена на `<redacted>` в любой логируемой строке
     * (URL с токеном в path → утечка секрета в Graylog). Пустой токен не скрабим,
     * чтобы не заменить всё подряд.
     */
    private function scrubToken(string $message): string
    {
        $token = trim($this->telegramBotToken);
        if ($token === '') {
            return $message;
        }

        return str_replace($token, '<redacted>', $message);
    }
}
