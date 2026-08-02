<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Billing\TopUpPack;
use App\Entity\User;
use App\Exception\InvalidTopUpAmountException;
use App\Exception\TopUpNotAllowedException;
use App\Exception\UnknownTopUpPackException;
use App\Repository\UserRepository;
use App\Service\Auth\TelegramBotClient;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramUserProvisioner;
use App\Service\Billing\BalanceService;
use App\Service\Billing\PaymentTopUpService;
use App\Service\Billing\TopUpPackRegistry;
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
 *  - message `/balance` → баланс prepaid + ставки pay-per-use (CNV-58).
 *  - message `/topup` / `/topup <pack_id|N>` → инлайн-пакеты / invoice / произвольные Stars (CNV-58).
 *  - message `/start pay_<pack>` → invoice пополнения prepaid-баланса (CNV-28).
 *  - callback_query «Войти» → findOrCreateUser + пометить code authorized.
 *  - callback_query `topup:<pack_id>` → invoice выбранного пакета.
 *  - pre_checkout_query → подтверждение invoice перед оплатой Stars.
 *  - message с successful_payment → идемпотентное зачисление на баланс.
 *
 * Модель — PAIRING + POLL: апрув в боте помечает code authorized; завершение
 * входа — в исходной вкладке по `code` + nonce-cookie. Двухшаговость намеренна:
 * сам /start НЕ авторизует — авторизует тап по кнопке.
 */
#[Route('/api/v1/telegram/webhook')]
class TelegramWebhookController extends AbstractController
{
    private const CALLBACK_PREFIX_LOGIN = 'login:';
    private const CALLBACK_PREFIX_TOPUP = 'topup:';

    public function __construct(
        private readonly TelegramBotClient $botClient,
        private readonly TelegramLoginCodeStore $codeStore,
        private readonly TelegramUserProvisioner $provisioner,
        private readonly PaymentTopUpService $topUpService,
        private readonly TopUpPackRegistry $packRegistry,
        private readonly BalanceService $balanceService,
        private readonly UserRepository $userRepository,
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
        } elseif (isset($update['pre_checkout_query']) && is_array($update['pre_checkout_query'])) {
            $this->handlePreCheckoutQuery($update['pre_checkout_query']);
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
        if (isset($message['successful_payment']) && is_array($message['successful_payment'])) {
            $this->handleSuccessfulPayment($message);

            return;
        }

        // trim: меню BotCommand иногда шлёт хвост пробела — иначе regex не матчит.
        $text   = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $chatId = $this->extractChatId($message);
        if ($chatId === null) {
            return;
        }

        // Deep-link пополнения: /start pay_pack_100 → t.me/bot?start=pay_pack_100
        if (preg_match('/^\/start\s+pay_(\S+)$/', $text, $m) === 1) {
            $this->handlePayStart($message, $chatId, $m[1]);

            return;
        }

        // "/help" (допускаем суффикс "@bot_username", как шлёт Telegram-клиент
        // в группах) — короткая справка о боте.
        if (preg_match('/^\/help(?:@\S+)?$/', $text) === 1) {
            $this->botClient->sendMessage($chatId, $this->helpMessage());

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

        // "/balance" — prepaid-баланс и ставки pay-per-use.
        if (preg_match('/^\/balance(?:@\S+)?$/', $text) === 1) {
            $this->handleBalanceCommand($message, $chatId);

            return;
        }

        // "/topup" или "/topup <pack_id|N>" — меню пакетов / invoice пакета / произвольные Stars.
        if (preg_match('/^\/topup(?:@\S+)?(?:\s+(\S+))?$/', $text, $m) === 1) {
            $arg = isset($m[1]) && $m[1] !== '' ? $m[1] : null;
            $this->handleTopupCommand($message, $chatId, $arg);

            return;
        }

        // Ждём "/start <code>".
        if (! preg_match('/^\/start\s+(\S+)$/', $text, $m)) {
            $this->botClient->sendMessage($chatId, $this->bareStartMessage());

            return;
        }

        $code = $m[1];

        $this->botClient->sendMessage($chatId, 'Нажмите «Войти», чтобы завершить вход на сайте:', [
            'inline_keyboard' => [[
                ['text' => 'Войти', 'callback_data' => self::CALLBACK_PREFIX_LOGIN . $code],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleBalanceCommand(array $message, int|string $chatId): void
    {
        $telegramUserId = $this->extractTelegramUserId($message);
        if ($telegramUserId === null) {
            // Без from.id нельзя найти User — не молчим (silent return = «бот ничего не ответил»).
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        $user = $this->userRepository->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        $balanceCents = $this->balanceService->getBalanceCents($user);
        $regularCents = $this->balanceService->getPayPerUseCostCents(false);
        $aiCents      = $this->balanceService->getPayPerUseCostCents(true);

        $this->botClient->sendMessage(
            $chatId,
            sprintf(
                "Ваш баланс: %s.\nОбычная конвертация: %s, AI: %s.\nПополнить: /topup",
                $this->formatCents($balanceCents),
                $this->formatCents($regularCents),
                $this->formatCents($aiCents),
            ),
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleTopupCommand(array $message, int|string $chatId, ?string $arg): void
    {
        $telegramUserId = $this->extractTelegramUserId($message);
        if ($telegramUserId === null) {
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        $user = $this->userRepository->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        if ($arg !== null) {
            // Сначала packs (hasPack), затем цифры — иначе numeric pack_id ушёл бы в custom.
            if ($this->packRegistry->hasPack($arg)) {
                $this->sendTopUpInvoice($chatId, $user->getTelegramId() ?? $telegramUserId, $arg);

                return;
            }

            if (ctype_digit($arg)) {
                $stars = (int) $arg;
                if ($stars < PaymentTopUpService::MIN_TOPUP_STARS) {
                    $this->botClient->sendMessage(
                        $chatId,
                        sprintf('Минимум %d ⭐.', PaymentTopUpService::MIN_TOPUP_STARS),
                    );

                    return;
                }

                $this->sendTopUpInvoiceForStars($chatId, $user, $stars);

                return;
            }

            $this->botClient->sendMessage($chatId, $this->unknownTopupArgMessage());

            return;
        }

        $rows = [];
        foreach ($this->packRegistry->listPacks() as $pack) {
            $rows[] = [[
                'text'          => $this->packButtonLabel($pack),
                'callback_data' => self::CALLBACK_PREFIX_TOPUP . $pack->id,
            ]];
        }

        $this->botClient->sendMessage(
            $chatId,
            'Выберите пакет или отправьте /topup <N> (минимум '
            . PaymentTopUpService::MIN_TOPUP_STARS
            . ' ⭐) / /topup <pack_id>:',
            ['inline_keyboard' => $rows],
        );
    }

    private function sendTopUpInvoiceForStars(int|string $chatId, User $user, int $stars): void
    {
        try {
            $this->topUpService->sendInvoiceForStars($user, $stars, $chatId);
        } catch (InvalidTopUpAmountException) {
            $this->botClient->sendMessage(
                $chatId,
                sprintf('Минимум %d ⭐.', PaymentTopUpService::MIN_TOPUP_STARS),
            );
        } catch (\Throwable $e) {
            // TopUpNotAllowedException здесь маловероятен (user уже найден по telegram_id),
            // но гость / сбой Bot API → общее сообщение.
            if ($e instanceof TopUpNotAllowedException) {
                $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

                return;
            }

            $this->logger->error('Top-up custom invoice failed', [
                'stars'  => $stars,
                'userId' => $user->getId(),
                'error'  => $e->getMessage(),
            ]);
            $this->botClient->sendMessage($chatId, 'Не удалось выставить счёт. Попробуйте позже.');
        }
    }

    /**
     * @param array<string, mixed> $preCheckoutQuery
     */
    private function handlePreCheckoutQuery(array $preCheckoutQuery): void
    {
        $queryId = is_string($preCheckoutQuery['id'] ?? null) ? $preCheckoutQuery['id'] : '';
        if ($queryId === '') {
            return;
        }

        $from           = is_array($preCheckoutQuery['from'] ?? null) ? $preCheckoutQuery['from'] : [];
        $telegramUserId = isset($from['id']) ? (string) $from['id'] : '';
        if ($telegramUserId === '') {
            return;
        }

        $invoicePayload = is_string($preCheckoutQuery['invoice_payload'] ?? null)
            ? $preCheckoutQuery['invoice_payload']
            : '';
        $totalAmount      = $preCheckoutQuery['total_amount'] ?? 0;
        $totalAmountStars = is_int($totalAmount) ? $totalAmount : (is_string($totalAmount) && ctype_digit($totalAmount) ? (int) $totalAmount : 0);

        $this->topUpService->handlePreCheckoutQuery(
            $queryId,
            $invoicePayload,
            $totalAmountStars,
            $telegramUserId,
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleSuccessfulPayment(array $message): void
    {
        $successfulPayment = is_array($message['successful_payment'] ?? null)
            ? $message['successful_payment']
            : [];
        $from           = is_array($message['from'] ?? null) ? $message['from'] : [];
        $telegramUserId = isset($from['id']) ? (string) $from['id'] : '';
        if ($telegramUserId === '') {
            return;
        }

        $invoicePayload = is_string($successfulPayment['invoice_payload'] ?? null)
            ? $successfulPayment['invoice_payload']
            : '';
        $chargeId = is_string($successfulPayment['telegram_payment_charge_id'] ?? null)
            ? $successfulPayment['telegram_payment_charge_id']
            : '';
        $totalAmount      = $successfulPayment['total_amount'] ?? 0;
        $totalAmountStars = is_int($totalAmount) ? $totalAmount : (is_string($totalAmount) && ctype_digit($totalAmount) ? (int) $totalAmount : 0);

        // credit() внутри сервиса делает refresh User — баланс после вызова актуальный.
        // false = идемпотентный no-op / платёж не найден — без сообщения об успехе.
        $credited = $this->topUpService->handleSuccessfulPayment(
            $invoicePayload,
            $chargeId,
            $totalAmountStars,
            $telegramUserId,
        );
        if (!$credited) {
            return;
        }

        $chatId = $this->extractChatId($message);
        if ($chatId === null) {
            return;
        }

        $user = $this->userRepository->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->botClient->sendMessage($chatId, 'Баланс пополнен. Можете вернуться на сайт и продолжить конвертацию.');

            return;
        }

        $balanceCents = $this->balanceService->getBalanceCents($user);
        $this->botClient->sendMessage(
            $chatId,
            sprintf(
                'Баланс пополнен. Текущий баланс: %s. Можете вернуться на сайт и продолжить конвертацию.',
                $this->formatCents($balanceCents),
            ),
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handlePayStart(array $message, int|string $chatId, string $packId): void
    {
        $telegramUserId = $this->extractTelegramUserId($message);
        if ($telegramUserId === null) {
            return;
        }

        $this->sendTopUpInvoice($chatId, $telegramUserId, $packId);
    }

    private function sendTopUpInvoice(int|string $chatId, string $telegramUserId, string $packId): void
    {
        if (! $this->packRegistry->hasPack($packId)) {
            $this->botClient->sendMessage($chatId, $this->unknownPackMessage());

            return;
        }

        $user = $this->userRepository->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        try {
            $this->topUpService->sendInvoiceToChat($user, $packId, $chatId);
        } catch (TopUpNotAllowedException) {
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());
        } catch (UnknownTopUpPackException) {
            $this->botClient->sendMessage($chatId, $this->unknownPackMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Top-up invoice failed', [
                'packId' => $packId,
                'userId' => $user->getId(),
                'error'  => $e->getMessage(),
            ]);
            $this->botClient->sendMessage($chatId, 'Не удалось выставить счёт. Попробуйте позже.');
        }
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = is_string($callbackQuery['id'] ?? null) ? $callbackQuery['id'] : '';
        $data       = is_string($callbackQuery['data'] ?? null) ? $callbackQuery['data'] : '';

        if ($callbackId === '') {
            return;
        }

        if (str_starts_with($data, self::CALLBACK_PREFIX_TOPUP)) {
            $this->handleTopupCallback($callbackQuery, $callbackId, $data);

            return;
        }

        if (! str_starts_with($data, self::CALLBACK_PREFIX_LOGIN)) {
            return;
        }

        $code = substr($data, strlen(self::CALLBACK_PREFIX_LOGIN));
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
     * @param array<string, mixed> $callbackQuery
     */
    private function handleTopupCallback(array $callbackQuery, string $callbackId, string $data): void
    {
        $packId = substr($data, strlen(self::CALLBACK_PREFIX_TOPUP));
        $from   = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];

        $telegramUserId = isset($from['id']) ? (string) $from['id'] : '';
        if ($telegramUserId === '') {
            $this->botClient->answerCallbackQuery($callbackId, 'Не удалось определить аккаунт.');

            return;
        }

        $chatId = $this->extractChatId(is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : []);
        if ($chatId === null) {
            $this->botClient->answerCallbackQuery($callbackId, 'Не удалось определить чат.');

            return;
        }

        if ($packId === '' || ! $this->packRegistry->hasPack($packId)) {
            $this->botClient->answerCallbackQuery($callbackId, 'Неизвестный пакет.');
            $this->botClient->sendMessage($chatId, $this->unknownPackMessage());

            return;
        }

        $user = $this->userRepository->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->botClient->answerCallbackQuery($callbackId, 'Сначала войдите на сайте.');
            $this->botClient->sendMessage($chatId, $this->unlinkedUserMessage());

            return;
        }

        $this->botClient->answerCallbackQuery($callbackId);
        $this->sendTopUpInvoice($chatId, $telegramUserId, $packId);
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

    /**
     * @param array<string, mixed> $message
     */
    private function extractTelegramUserId(array $message): ?string
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $id   = isset($from['id']) ? (string) $from['id'] : '';

        return $id !== '' ? $id : null;
    }

    private function loginUrl(): string
    {
        return rtrim($this->appUrl, '/') . '/login';
    }

    private function bareStartMessage(): string
    {
        return 'Чтобы войти в Convertor, откройте страницу входа: ' . $this->loginUrl()
            . "\nНажмите «Войти через Telegram», подтвердите вход кнопкой в этом боте."
            . "\nСправка: /help";
    }

    private function helpMessage(): string
    {
        return "Convertor — конвертация файлов и вход через Telegram.\n"
            . 'Войти: откройте ' . $this->loginUrl()
            . " , нажмите «Войти через Telegram» и подтвердите вход в боте.\n"
            . "Команды:\n"
            . "/balance — баланс и ставки pay-per-use\n"
            . "/topup — пополнить баланс: пакеты или /topup <N> Stars\n"
            . "/convert — открыть веб-приложение для конвертации";
    }

    private function unlinkedUserMessage(): string
    {
        return 'Аккаунт Telegram ещё не связан с Convertor. '
            . 'Откройте страницу входа: ' . $this->loginUrl()
            . "\nНажмите «Войти через Telegram» и подтвердите вход в этом боте, затем повторите команду.";
    }

    private function unknownPackMessage(): string
    {
        return $this->unknownTopupArgMessage();
    }

    private function unknownTopupArgMessage(): string
    {
        $ids = array_map(
            static fn (TopUpPack $pack): string => $pack->id,
            $this->packRegistry->listPacks(),
        );
        $packHint = $ids === []
            ? ''
            : ' Пакеты: ' . implode(', ', $ids) . '. Пример: /topup ' . $ids[0];

        return 'Неизвестный пакет или сумма.'
            . $packHint
            . ' Или произвольная сумма: /topup '
            . PaymentTopUpService::MIN_TOPUP_STARS
            . ' (минимум '
            . PaymentTopUpService::MIN_TOPUP_STARS
            . ' ⭐).';
    }

    private function packButtonLabel(TopUpPack $pack): string
    {
        return sprintf('%s — $%.2f (%d ⭐)', $pack->id, $pack->usdAmount(), $pack->stars);
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d ¢ ($%.2f)', $cents, $cents / 100.0);
    }
}
