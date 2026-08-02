<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\TopUpPack;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\PaymentGateway;
use App\Enum\PaymentStatus;
use App\Exception\InvalidTopUpAmountException;
use App\Exception\TopUpNotAllowedException;
use App\Repository\PaymentRepository;
use App\Service\Auth\TelegramBotClient;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Telegram Stars top-up: pending Payment + invoice + idempotent credit on successful_payment.
 */
class PaymentTopUpService
{
    public const INVOICE_PAYLOAD_PREFIX = 'topup:';

    /** Минимум Stars для произвольного `/topup <N>` (1⭐ = 1¢, без скидки). */
    public const MIN_TOPUP_STARS = 5;

    /** pack_id в metadata для произвольной суммы. */
    public const CUSTOM_PACK_ID = 'custom';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentRepository $paymentRepository,
        private readonly TopUpPackRegistry $packRegistry,
        private readonly BalanceService $balanceService,
        private readonly TelegramBotClient $botClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<TopUpPack>
     */
    public function listPacks(): array
    {
        return $this->packRegistry->listPacks();
    }

    public function assertUserCanTopUp(User $user): void
    {
        if ($user->isGuest()) {
            throw TopUpNotAllowedException::guestUser();
        }

        if ($user->getTelegramId() === null) {
            throw TopUpNotAllowedException::noTelegramLink();
        }
    }

    /**
     * Создаёт pending Payment по пакету и отправляет invoice в чат Telegram.
     */
    public function sendInvoiceToChat(User $user, string $packId, int|string $chatId): Payment
    {
        $this->assertUserCanTopUp($user);
        $pack    = $this->packRegistry->getPack($packId);
        $payment = $this->createPendingPayment($user, $pack->id, $pack->usdCents, $pack->stars);

        $this->botClient->sendInvoice(
            $chatId,
            $this->invoiceTitle($pack),
            $this->invoiceDescription($pack),
            $this->buildInvoicePayload($payment),
            $pack->stars,
        );

        return $payment;
    }

    /**
     * Произвольное пополнение: N Stars (XTR) → N USD cents (1:1, без скидки пакетов).
     *
     * @throws InvalidTopUpAmountException если $stars < MIN_TOPUP_STARS
     */
    public function sendInvoiceForStars(User $user, int $stars, int|string $chatId): Payment
    {
        $this->assertUserCanTopUp($user);
        $this->assertStarsAmount($stars);

        // 1⭐ = 1¢ — базовая ставка pack_100, без пакетной скидки.
        $usdCents = $stars;
        $pack     = new TopUpPack(self::CUSTOM_PACK_ID, $usdCents, $stars);
        $payment  = $this->createPendingPayment($user, $pack->id, $pack->usdCents, $pack->stars);

        $this->botClient->sendInvoice(
            $chatId,
            $this->invoiceTitle($pack),
            $this->invoiceDescription($pack),
            $this->buildInvoicePayload($payment),
            $pack->stars,
        );

        return $payment;
    }

    /**
     * Создаёт pending Payment и возвращает createInvoiceLink (для REST slice 6).
     *
     * @return array{payment: Payment, invoice_link: string}
     */
    public function createInvoiceLink(User $user, string $packId): array
    {
        $this->assertUserCanTopUp($user);
        $pack    = $this->packRegistry->getPack($packId);
        $payment = $this->createPendingPayment($user, $pack->id, $pack->usdCents, $pack->stars);

        $response = $this->botClient->createInvoiceLink(
            $this->invoiceTitle($pack),
            $this->invoiceDescription($pack),
            $this->buildInvoicePayload($payment),
            $pack->stars,
        );

        $invoiceLink = is_string($response['result'] ?? null) ? $response['result'] : '';
        if ($invoiceLink === '') {
            throw new \RuntimeException('Telegram createInvoiceLink returned empty result.');
        }

        return ['payment' => $payment, 'invoice_link' => $invoiceLink];
    }

    /**
     * @throws InvalidTopUpAmountException
     */
    public function assertStarsAmount(int $stars): void
    {
        if ($stars < self::MIN_TOPUP_STARS) {
            throw InvalidTopUpAmountException::belowMinimum(self::MIN_TOPUP_STARS);
        }
    }

    /**
     * pre_checkout_query: подтверждаем только если pending Payment совпадает по сумме и пользователю.
     */
    public function handlePreCheckoutQuery(
        string $queryId,
        string $invoicePayload,
        int $totalAmountStars,
        string $telegramUserId,
    ): void {
        $ok      = false;
        $message = null;

        try {
            $payment = $this->resolvePendingPayment($invoicePayload, $telegramUserId, $totalAmountStars);
            $ok      = $payment !== null;
            if (! $ok) {
                $message = 'Платёж недоступен или устарел.';
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Top-up pre_checkout rejected', [
                'payload' => $invoicePayload,
                'error'   => $e->getMessage(),
            ]);
            $message = 'Не удалось подтвердить платёж.';
        }

        $this->botClient->answerPreCheckoutQuery($queryId, $ok, $message);
    }

    /**
     * successful_payment: идемпотентное зачисление по telegram_payment_charge_id.
     *
     * @return bool true если баланс зачислен; false если уже обработано
     */
    public function handleSuccessfulPayment(
        string $invoicePayload,
        string $telegramPaymentChargeId,
        int $totalAmountStars,
        string $telegramUserId,
    ): bool {
        if ($telegramPaymentChargeId === '') {
            $this->logger->warning('Top-up successful_payment without charge id', ['payload' => $invoicePayload]);

            return false;
        }

        $existing = $this->paymentRepository->findByExternalId($telegramPaymentChargeId);
        if ($existing !== null && $existing->getStatus() === PaymentStatus::Completed) {
            return false;
        }

        $payment = $this->resolvePendingPayment($invoicePayload, $telegramUserId, $totalAmountStars);
        if ($payment === null) {
            $this->logger->warning('Top-up successful_payment: payment not found or invalid', [
                'payload'  => $invoicePayload,
                'chargeId' => $telegramPaymentChargeId,
            ]);

            return false;
        }

        return $this->em->wrapInTransaction(function () use ($payment, $telegramPaymentChargeId): bool {
            $this->em->refresh($payment);

            if ($payment->getStatus() === PaymentStatus::Completed) {
                return false;
            }

            $dup = $this->paymentRepository->findByExternalId($telegramPaymentChargeId);
            if ($dup !== null && $dup->getId() !== $payment->getId()) {
                return false;
            }

            /** @var int $usdCents */
            $usdCents = $payment->getMetadata()['usd_cents'] ?? 0;
            if ($usdCents <= 0) {
                throw new \LogicException(sprintf('Payment %d has invalid usd_cents metadata.', $payment->getId()));
            }

            $payment
                ->setExternalId($telegramPaymentChargeId)
                ->setStatus(PaymentStatus::Completed);

            $this->balanceService->credit(
                $payment->getUser(),
                $usdCents,
                BalanceTransactionSource::Payment,
                $telegramPaymentChargeId,
                ['payment_id' => $payment->getId()],
            );

            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Гонка: параллельный webhook успел записать тот же external_id.
                return false;
            }

            $this->logger->info('Top-up credited', [
                'paymentId' => $payment->getId(),
                'userId'    => $payment->getUser()->getId(),
                'usdCents'  => $usdCents,
                'chargeId'  => $telegramPaymentChargeId,
            ]);

            return true;
        });
    }

    public function buildInvoicePayload(Payment $payment): string
    {
        return self::INVOICE_PAYLOAD_PREFIX . $payment->getId();
    }

    private function createPendingPayment(User $user, string $packId, int $usdCents, int $stars): Payment
    {
        $payment = (new Payment())
            ->setUser($user)
            ->setAmount($usdCents / 100.0)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars)
            ->setStatus(PaymentStatus::Pending)
            ->setMetadata([
                'pack_id'   => $packId,
                'usd_cents' => $usdCents,
                'stars'     => $stars,
            ]);

        $this->paymentRepository->save($payment, true);

        return $payment;
    }

    private function resolvePendingPayment(
        string $invoicePayload,
        string $telegramUserId,
        int $totalAmountStars,
    ): ?Payment {
        $paymentId = $this->parsePaymentIdFromPayload($invoicePayload);
        if ($paymentId === null) {
            return null;
        }

        $payment = $this->paymentRepository->find($paymentId);
        if ($payment === null || $payment->getStatus() !== PaymentStatus::Pending) {
            return null;
        }

        $user = $payment->getUser();
        if ($user->getTelegramId() !== $telegramUserId) {
            return null;
        }

        $expectedStars = $payment->getMetadata()['stars'] ?? null;
        if (! is_int($expectedStars) && ! (is_string($expectedStars) && ctype_digit((string) $expectedStars))) {
            return null;
        }

        if ((int) $expectedStars !== $totalAmountStars) {
            return null;
        }

        return $payment;
    }

    private function parsePaymentIdFromPayload(string $invoicePayload): ?int
    {
        if (! str_starts_with($invoicePayload, self::INVOICE_PAYLOAD_PREFIX)) {
            return null;
        }

        $idPart = substr($invoicePayload, strlen(self::INVOICE_PAYLOAD_PREFIX));
        if ($idPart === '' || ! ctype_digit($idPart)) {
            return null;
        }

        return (int) $idPart;
    }

    private function invoiceTitle(TopUpPack $pack): string
    {
        return sprintf('Пополнение баланса — $%.2f', $pack->usdAmount());
    }

    private function invoiceDescription(TopUpPack $pack): string
    {
        return sprintf(
            'Prepaid-кредиты Convertor на $%.2f (%d USD cents). Пакет %s.',
            $pack->usdAmount(),
            $pack->usdCents,
            $pack->id,
        );
    }
}
