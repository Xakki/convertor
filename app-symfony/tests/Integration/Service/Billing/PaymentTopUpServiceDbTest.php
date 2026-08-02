<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentGateway;
use App\Enum\PaymentStatus;
use App\Service\Billing\PaymentTopUpService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * handleSuccessfulPayment против реальной БД: credit + idempotency по charge id.
 */
#[Group('integration')]
final class PaymentTopUpServiceDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentTopUpService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em       = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var PaymentTopUpService $service */
        $service       = $container->get(PaymentTopUpService::class);
        $this->service = $service;
    }

    public function testSuccessfulPaymentCreditsBalanceOnce(): void
    {
        $user = $this->persistTelegramUser('tg-topup-' . uniqid('', true));

        $payment = (new Payment())
            ->setUser($user)
            ->setAmount(1.0)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars)
            ->setStatus(PaymentStatus::Pending)
            ->setMetadata(['pack_id' => 'pack_100', 'usd_cents' => 100, 'stars' => 100]);
        $this->em->persist($payment);
        $this->em->flush();

        $payload  = PaymentTopUpService::INVOICE_PAYLOAD_PREFIX . $payment->getId();
        $chargeId = 'test-charge-' . uniqid('', true);

        self::assertTrue($this->service->handleSuccessfulPayment($payload, $chargeId, 100, $user->getTelegramId() ?? ''));
        self::assertFalse($this->service->handleSuccessfulPayment($payload, $chargeId, 100, $user->getTelegramId() ?? ''));

        $this->em->refresh($user);
        $this->em->refresh($payment);

        self::assertSame(100, $user->getBalanceCents());
        self::assertSame(PaymentStatus::Completed, $payment->getStatus());
        self::assertSame($chargeId, $payment->getExternalId());

        $this->removeUser($user);
    }

    /**
     * Реальные telegram_payment_charge_id длиннее VARCHAR(64) — без widen ref_id
     * credit() падал SQLSTATE 1406 и webhook молчал после оплаты.
     */
    public function testSuccessfulPaymentAcceptsLongTelegramChargeId(): void
    {
        $user = $this->persistTelegramUser('tg-topup-long-' . uniqid('', true));

        $payment = (new Payment())
            ->setUser($user)
            ->setAmount(1.0)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars)
            ->setStatus(PaymentStatus::Pending)
            ->setMetadata(['pack_id' => 'pack_100', 'usd_cents' => 100, 'stars' => 100]);
        $this->em->persist($payment);
        $this->em->flush();

        $payload = PaymentTopUpService::INVOICE_PAYLOAD_PREFIX . $payment->getId();
        // >64 chars, типичный Stars charge id (раньше ломал balance_transactions.ref_id).
        $chargeId = 'stxy_' . str_repeat('a', 80);

        self::assertTrue($this->service->handleSuccessfulPayment($payload, $chargeId, 100, $user->getTelegramId() ?? ''));

        $this->em->refresh($user);
        $this->em->refresh($payment);

        self::assertSame(100, $user->getBalanceCents());
        self::assertSame($chargeId, $payment->getExternalId());

        $this->removeUser($user);
    }

    private function persistTelegramUser(string $telegramId): User
    {
        $user = (new User())
            ->setTelegramId($telegramId)
            ->setEmail($telegramId . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function removeUser(User $user): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\BalanceTransaction bt WHERE bt.user = :user')
            ->setParameter('user', $user)
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Payment p WHERE p.user = :user')
            ->setParameter('user', $user)
            ->execute();
        $this->em->remove($user);
        $this->em->flush();
    }
}
