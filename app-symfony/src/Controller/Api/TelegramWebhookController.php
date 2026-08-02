<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Auth\TelegramBotClient;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramUserProvisioner;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Приём апдейтов Telegram (webhook). Отдельный firewall `^/api/v1/telegram/webhook`
 * (security:false) — аутентификация тут ТОЛЬКО по секрет-заголовку
 * X-Telegram-Bot-Api-Secret-Token, который Telegram шлёт с каждым апдейтом.
 *
 * Обработка:
 *  - message `/start <code>` → инлайн-кнопка «Войти» (callback_data несёт code).
 *  - message `/help` → короткая справка о боте.
 *  - message `/convert` → ссылка на веб-приложение для конвертации файла.
 *  - callback_query «Войти» → findOrCreateUser + пометить code authorized +
 *    текст «вернитесь в браузер» (исходная вкладка заберёт сессию через poll).
 *
 * Модель — PAIRING + POLL: апрув в боте помечает code authorized; завершение
 * входа — в исходной вкладке по `code` + nonce-cookie. Двухшаговость намеренна:
 * сам /start НЕ авторизует — авторизует тап по кнопке.
 */
#[Route('/api/v1/telegram/webhook')]
class TelegramWebhookController extends AbstractController
{
    private const CALLBACK_PREFIX = 'login:';

    public function __construct(
        private readonly TelegramBotClient $botClient,
        private readonly TelegramLoginCodeStore $codeStore,
        private readonly TelegramUserProvisioner $provisioner,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
        #[Autowire('%env(APP_URL)%')]
        private readonly string $appUrl,
    ) {
    }

    #[Route('', name: 'telegram_webhook', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Telegram webhook (внутренний, защищён секрет-заголовком)', security: [])]
    #[OA\Response(response: 200, description: 'Апдейт принят')]
    #[OA\Response(response: 403, description: 'Неверный секрет-заголовок')]
    public function handle(Request $request): JsonResponse
    {
        // Fail-closed: пустой секрет в конфиге отвергает всё (как WorkerAuthenticator).
        if (trim($this->webhookSecret) === ''
            || ! hash_equals($this->webhookSecret, (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token', ''))) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $update */
        $update = json_decode($request->getContent(), true) ?? [];

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        } elseif (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }

        // Telegram нужен просто 200 — тело он игнорирует.
        return $this->json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $text   = is_string($message['text'] ?? null) ? $message['text'] : '';
        $chatId = $this->extractChatId($message);
        if ($chatId === null) {
            return;
        }

        // "/help" (допускаем суффикс "@bot_username", как шлёт Telegram-клиент
        // в группах) — короткая справка о боте.
        if (preg_match('/^\/help(?:@\S+)?$/', $text) === 1) {
            $this->botClient->sendMessage(
                $chatId,
                'Этот бот нужен для входа в Convertor и конвертации файлов. '
                . 'Отправьте /start с сайта, чтобы войти, или /convert, чтобы перейти к конвертации.',
            );

            return;
        }

        // "/convert" (тот же допуск на "@bot_username") — ссылка на веб-приложение.
        if (preg_match('/^\/convert(?:@\S+)?$/', $text) === 1) {
            $this->botClient->sendMessage(
                $chatId,
                'Чтобы сконвертировать файл, откройте веб-приложение: ' . rtrim($this->appUrl, '/'),
            );

            return;
        }

        // Ждём "/start <code>".
        if (! preg_match('/^\/start\s+(\S+)$/', $text, $m)) {
            $this->botClient->sendMessage($chatId, 'Откройте бота по ссылке с сайта, чтобы войти.');

            return;
        }

        $code = $m[1];

        $this->botClient->sendMessage($chatId, 'Нажмите «Войти», чтобы завершить вход на сайте:', [
            'inline_keyboard' => [[
                ['text' => 'Войти', 'callback_data' => self::CALLBACK_PREFIX . $code],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = is_string($callbackQuery['id'] ?? null) ? $callbackQuery['id'] : '';
        $data       = is_string($callbackQuery['data'] ?? null) ? $callbackQuery['data'] : '';

        if ($callbackId === '' || ! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return;
        }

        $code = substr($data, strlen(self::CALLBACK_PREFIX));
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];

        $telegramId = isset($from['id']) ? (string) $from['id'] : '';
        if ($telegramId === '') {
            $this->botClient->answerCallbackQuery($callbackId, 'Не удалось определить аккаунт.');

            return;
        }

        $user = $this->provisioner->findOrCreateUser(
            $telegramId,
            is_string($from['username'] ?? null) ? $from['username'] : null,
            is_string($from['first_name'] ?? null) ? $from['first_name'] : null,
        );

        // authorize = true только из pending (status-guard: первый тап побеждает,
        // форвард не перепривязывает). findOrCreateUser персистит юзера → id всегда присвоен.
        $userId = $user->getId();
        \assert($userId !== null);
        if (! $this->codeStore->authorize($code, $userId)) {
            $this->botClient->answerCallbackQuery($callbackId, 'Код истёк или уже использован, начните вход заново.');

            return;
        }

        $this->logger->info('Telegram bot-login authorized', ['userId' => $user->getId()]);
        $this->botClient->answerCallbackQuery($callbackId, 'Готово! Вернитесь в браузер.');

        // Без magic-ссылки: исходная вкладка сама заберёт сессию через poll
        // (code + nonce-cookie). Сообщаем пользователю вернуться в браузер.
        $chatId = $this->extractChatId(is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : []);
        if ($chatId !== null) {
            $this->botClient->sendMessage($chatId, 'Авторизация успешна. Вернитесь в браузер.');
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractChatId(array $message): int|string|null
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $id   = $chat['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
