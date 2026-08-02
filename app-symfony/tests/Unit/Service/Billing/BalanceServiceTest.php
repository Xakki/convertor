<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\BalanceTransaction;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\BalanceTransactionType;
use App\Exception\InsufficientBalanceException;
use App\Service\Billing\BalanceService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-покрытие BalanceService: атомарные credit/debit/refund + ledger.
 */
final class BalanceServiceTest extends TestCase
{
    private const PAY_PER_USE_CENTS    = 5;
    private const PAY_PER_USE_AI_CENTS = 15;

    private function makeService(EntityManagerInterface $em): BalanceService
    {
        return new BalanceService($em, self::PAY_PER_USE_CENTS, self::PAY_PER_USE_AI_CENTS);
    }

    private function userWithId(int $id, int $balanceCents = 0): User
    {
        $user = (new User())->setBalanceCents($balanceCents);
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    /**
     * @param callable(string):bool $expectSql
     */
    private function emForBalanceOp(
        User $user,
        callable $expectSql,
        int $executeReturn = 1,
        bool $expectLedgerWrite = true,
    ): EntityManagerInterface {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => $expectSql($sql)),
                self::identicalTo(['amount' => 10, 'id' => 42]),
            )
            ->willReturn($executeReturn);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $func) => $func());

        if ($expectLedgerWrite) {
            $em->expects(self::once())->method('persist')->with(self::isInstanceOf(BalanceTransaction::class));
            $em->expects(self::once())->method('flush');
            $em->expects(self::once())->method('refresh')->with(self::identicalTo($user));
        } else {
            $em->expects(self::never())->method('persist');
            $em->expects(self::never())->method('flush');
            $em->expects(self::never())->method('refresh');
        }

        return $em;
    }

    public function testGetBalanceCentsReturnsUserField(): void
    {
        $user = $this->userWithId(1, 250);

        self::assertSame(250, $this->makeService($this->createStub(EntityManagerInterface::class))->getBalanceCents($user));
    }

    public function testGetPayPerUseCostCentsOrdinary(): void
    {
        self::assertSame(
            self::PAY_PER_USE_CENTS,
            $this->makeService($this->createStub(EntityManagerInterface::class))->getPayPerUseCostCents(false),
        );
    }

    public function testGetPayPerUseCostCentsAi(): void
    {
        self::assertSame(
            self::PAY_PER_USE_AI_CENTS,
            $this->makeService($this->createStub(EntityManagerInterface::class))->getPayPerUseCostCents(true),
        );
    }

    public function testCreditIncrementsBalanceAndWritesLedger(): void
    {
        $user    = $this->userWithId(42, 100);
        $service = $this->makeService($this->emForBalanceOp(
            $user,
            static fn (string $sql): bool => str_contains($sql, 'balance_cents = balance_cents + :amount'),
        ));

        $tx = $service->credit(
            $user,
            10,
            BalanceTransactionSource::Payment,
            'pay-1',
            ['provider' => 'telegram'],
        );

        self::assertSame(BalanceTransactionType::Credit, $tx->getType());
        self::assertSame(BalanceTransactionSource::Payment, $tx->getSource());
        self::assertSame(10, $tx->getAmountCents());
        self::assertSame('pay-1', $tx->getRefId());
        self::assertSame(['provider' => 'telegram'], $tx->getMetadata());
        self::assertSame($user, $tx->getUser());
    }

    public function testDebitDecrementsBalanceAndWritesNegativeLedger(): void
    {
        $user    = $this->userWithId(42, 100);
        $service = $this->makeService($this->emForBalanceOp(
            $user,
            static fn (string $sql): bool => str_contains($sql, 'balance_cents = balance_cents - :amount')
                && str_contains($sql, 'balance_cents >= :amount'),
        ));

        $tx = $service->debit($user, 10, BalanceTransactionSource::Conversion, 'conv-7');

        self::assertSame(BalanceTransactionType::Debit, $tx->getType());
        self::assertSame(BalanceTransactionSource::Conversion, $tx->getSource());
        self::assertSame(-10, $tx->getAmountCents());
        self::assertSame('conv-7', $tx->getRefId());
    }

    public function testDebitThrowsWhenBalanceInsufficient(): void
    {
        $user = $this->userWithId(42, 5);
        $em   = $this->emForBalanceOp(
            $user,
            static fn (string $sql): bool => str_contains($sql, 'balance_cents - :amount'),
            executeReturn: 0,
            expectLedgerWrite: false,
        );

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('Insufficient balance for user 42: need 10 cents, have 5 cents.');

        $this->makeService($em)->debit($user, 10, BalanceTransactionSource::Conversion);
    }

    public function testRefundIncrementsBalanceWithRefundType(): void
    {
        $user    = $this->userWithId(42, 90);
        $service = $this->makeService($this->emForBalanceOp(
            $user,
            static fn (string $sql): bool => str_contains($sql, 'balance_cents = balance_cents + :amount'),
        ));

        $tx = $service->refund($user, 10, BalanceTransactionSource::Conversion, 'conv-7');

        self::assertSame(BalanceTransactionType::Refund, $tx->getType());
        self::assertSame(BalanceTransactionSource::Conversion, $tx->getSource());
        self::assertSame(10, $tx->getAmountCents());
    }

    public function testNonPositiveAmountRejected(): void
    {
        $user    = $this->userWithId(42);
        $service = $this->makeService($this->createStub(EntityManagerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->credit($user, 0, BalanceTransactionSource::Admin);
    }
}
