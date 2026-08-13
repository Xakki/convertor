<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\PaymentGateway;
use App\Enum\PaymentStatus;
use App\Exception\InvalidTopUpAmountException;
use App\Exception\TopUpNotAllowedException;
use App\Repository\PaymentRepository;
use App\Service\Auth\TelegramBotClient;
use App\Service\Billing\BalanceService;
use App\Service\Billing\PaymentTopUpService;
use App\Service\Billing\TopUpPackRegistry;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Юнит-покрытие PaymentTopUpService: invoice payload, pre_checkout, idempotent credit.
 */
final class PaymentTopUpServiceTest extends TestCase
{
    private const PACK_JSON = '{"pack_100":{"usd_cents":100,"stars":100}}';

    private TopUpPackRegistry $packRegistry;

    protected function setUp(): void
    {
        $this->packRegistry = new TopUpPackRegistry(self::PACK_JSON);
    }

    public function testAssertUserCanTopUpRejectsGuest(): void
    {
        $user = (new User())->setIsGuest(true)->setTelegramId('123');

        $this->expectException(TopUpNotAllowedException::class);
        $this->makeService()->assertUserCanTopUp($user);
    }

    public function testHandleSuccessfulPaymentCreditsOnce(): void
    {
        $user    = $this->userWithId(7, '555');
        $payment = $this->pendingPayment(11, $user, 100, 100);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::any())->method('findByExternalId')->willReturn(null);
        $paymentRepo->expects(self::any())->method('find')->with(11)->willReturn($payment);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::once())
            ->method('credit')
            ->with(
                self::identicalTo($user),
                100,
                BalanceTransactionSource::Payment,
                'charge-abc',
                ['payment_id' => 11],
            );

        $em = $this->transactionalEm($payment);

        $service  = $this->makeService($em, $paymentRepo, balance: $balance);
        $credited = $service->handleSuccessfulPayment('topup:11', 'charge-abc', 100, '555');

        self::assertTrue($credited);
        self::assertSame(PaymentStatus::Completed, $payment->getStatus());
        self::assertSame('charge-abc', $payment->getExternalId());
    }

    public function testHandleSuccessfulPaymentSkipsWhenPaymentAlreadyCompleted(): void
    {
        $user    = $this->userWithId(7, '555');
        $payment = $this->pendingPayment(11, $user, 100, 100);
        $payment->setStatus(PaymentStatus::Completed)->setExternalId('charge-old');

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::any())->method('findByExternalId')->willReturn(null);
        $paymentRepo->expects(self::any())->method('find')->with(11)->willReturn($payment);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::never())->method('credit');

        $service = $this->makeService(paymentRepo: $paymentRepo, balance: $balance);
        self::assertFalse($service->handleSuccessfulPayment('topup:11', 'charge-new', 100, '555'));
    }

    public function testHandleSuccessfulPaymentSkipsWhenChargeIdAlreadyCompleted(): void
    {
        $existing = (new Payment())
            ->setUser($this->userWithId(1, '1'))
            ->setAmount(1.0)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars)
            ->setStatus(PaymentStatus::Completed)
            ->setExternalId('charge-dup');

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::once())
            ->method('findByExternalId')
            ->with('charge-dup')
            ->willReturn($existing);
        $paymentRepo->expects(self::never())->method('find');

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::never())->method('credit');

        $service = $this->makeService(paymentRepo: $paymentRepo, balance: $balance);
        self::assertFalse($service->handleSuccessfulPayment('topup:99', 'charge-dup', 100, '555'));
    }

    public function testHandleSuccessfulPaymentTreatsUniqueConstraintAsAlreadyProcessed(): void
    {
        $user    = $this->userWithId(7, '555');
        $payment = $this->pendingPayment(11, $user, 100, 100);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::any())->method('findByExternalId')->willReturn(null);
        $paymentRepo->expects(self::any())->method('find')->with(11)->willReturn($payment);

        $balance = $this->createMock(BalanceService::class);
        $balance->expects(self::once())->method('credit');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $func) {
                return $func();
            });
        $em->expects(self::once())->method('refresh')->with(self::identicalTo($payment));
        $em->expects(self::once())->method('flush')
            ->willThrowException(new UniqueConstraintViolationException(
                new class ('duplicate') extends \Exception implements DriverException {
                    public function getSQLState(): ?string
                    {
                        return '23000';
                    }
                },
                null,
            ));

        $service = $this->makeService($em, $paymentRepo, balance: $balance);
        self::assertFalse($service->handleSuccessfulPayment('topup:11', 'charge-race', 100, '555'));
    }

    public function testHandlePreCheckoutQueryApprovesMatchingPendingPayment(): void
    {
        $user    = $this->userWithId(3, '777');
        $payment = $this->pendingPayment(5, $user, 100, 100);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::any())->method('find')->with(5)->willReturn($payment);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('answerPreCheckoutQuery')
            ->with('q-1', true, null);

        $service = $this->makeService(paymentRepo: $paymentRepo, bot: $bot);
        $service->handlePreCheckoutQuery('q-1', 'topup:5', 100, '777');
    }

    public function testHandlePreCheckoutQueryRejectsAmountMismatch(): void
    {
        $user    = $this->userWithId(3, '777');
        $payment = $this->pendingPayment(5, $user, 100, 100);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::any())->method('find')->with(5)->willReturn($payment);

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('answerPreCheckoutQuery')
            ->with('q-2', false, self::anything());

        $service = $this->makeService(paymentRepo: $paymentRepo, bot: $bot);
        $service->handlePreCheckoutQuery('q-2', 'topup:5', 999, '777');
    }

    public function testSendInvoiceToChatCreatesPendingPaymentAndCallsBot(): void
    {
        $user = $this->userWithId(2, '888');

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::once())->method('save')->with(
            self::callback(static function (Payment $payment) use ($user): bool {
                $ref = new \ReflectionProperty(Payment::class, 'id');
                $ref->setValue($payment, 42);

                return $payment->getUser()                  === $user
                    && $payment->getStatus()                === PaymentStatus::Pending
                    && $payment->getGateway()               === PaymentGateway::TelegramStars
                    && $payment->getMetadata()['usd_cents'] === 100
                    && $payment->getMetadata()['stars']     === 100;
            }),
            true,
        );

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendInvoice')
            ->with(
                999,
                self::stringContains('$1.00'),
                self::stringContains('pack_100'),
                self::stringStartsWith('topup:42'),
                100,
            );

        $em = $this->createStub(EntityManagerInterface::class);

        $service = $this->makeService($em, $paymentRepo, bot: $bot);
        $payment = $service->sendInvoiceToChat($user, 'pack_100', 999);

        self::assertSame(PaymentStatus::Pending, $payment->getStatus());
    }

    public function testSendInvoiceForStarsCreatesCustomPendingAndCallsBot(): void
    {
        $user = $this->userWithId(2, '888');

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->expects(self::once())->method('save')->with(
            self::callback(static function (Payment $payment) use ($user): bool {
                $ref = new \ReflectionProperty(Payment::class, 'id');
                $ref->setValue($payment, 77);

                return $payment->getUser()                  === $user
                    && $payment->getStatus()                === PaymentStatus::Pending
                    && $payment->getGateway()               === PaymentGateway::TelegramStars
                    && $payment->getAmount()                === 0.05
                    && $payment->getMetadata()['pack_id']   === PaymentTopUpService::CUSTOM_PACK_ID
                    && $payment->getMetadata()['usd_cents'] === 5
                    && $payment->getMetadata()['stars']     === 5;
            }),
            true,
        );

        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('sendInvoice')
            ->with(
                999,
                self::stringContains('$0.05'),
                self::stringContains('custom'),
                self::stringStartsWith('topup:77'),
                5,
            );

        $em = $this->createStub(EntityManagerInterface::class);

        $service = $this->makeService($em, $paymentRepo, bot: $bot);
        $payment = $service->sendInvoiceForStars($user, 5, 999);

        self::assertSame(PaymentStatus::Pending, $payment->getStatus());
        self::assertSame(5, $payment->getMetadata()['usd_cents']);
        self::assertSame(5, $payment->getMetadata()['stars']);
    }

    public function testSendInvoiceForStarsRejectsBelowMinimum(): void
    {
        $user = $this->userWithId(2, '888');

        $this->expectException(InvalidTopUpAmountException::class);
        $this->makeService()->sendInvoiceForStars($user, 4, 999);
    }

    private function makeService(
        ?EntityManagerInterface $em = null,
        ?PaymentRepository $paymentRepo = null,
        ?BalanceService $balance = null,
        ?TelegramBotClient $bot = null,
    ): PaymentTopUpService {
        return new PaymentTopUpService(
            $em          ?? $this->createStub(EntityManagerInterface::class),
            $paymentRepo ?? $this->createStub(PaymentRepository::class),
            $this->packRegistry,
            $balance ?? $this->createStub(BalanceService::class),
            $bot     ?? $this->createStub(TelegramBotClient::class),
            new NullLogger(),
        );
    }

    private function userWithId(int $id, string $telegramId): User
    {
        $user = (new User())->setTelegramId($telegramId);
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function pendingPayment(int $id, User $user, int $usdCents, int $stars): Payment
    {
        $payment = (new Payment())
            ->setUser($user)
            ->setAmount($usdCents / 100.0)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars)
            ->setStatus(PaymentStatus::Pending)
            ->setMetadata(['pack_id' => 'pack_100', 'usd_cents' => $usdCents, 'stars' => $stars]);

        $ref = new \ReflectionProperty(Payment::class, 'id');
        $ref->setValue($payment, $id);

        return $payment;
    }

    private function transactionalEm(Payment $payment): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $func) use ($payment) {
                $result = $func();

                return $result;
            });
        $em->expects(self::once())->method('refresh')->with(self::identicalTo($payment));
        $em->expects(self::once())->method('flush');

        return $em;
    }
}
