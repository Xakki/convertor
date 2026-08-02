<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\BalanceTransaction;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\BalanceTransactionType;
use App\Exception\InsufficientBalanceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prepaid-баланс pay-per-use (CNV-28): атомарные credit/debit/refund + append-only ledger.
 */
class BalanceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:PAY_PER_USE_CENTS)%')]
        private readonly int $payPerUseCents,
        #[Autowire('%env(int:PAY_PER_USE_AI_CENTS)%')]
        private readonly int $payPerUseAiCents,
    ) {
    }

    public function getBalanceCents(User $user): int
    {
        return $user->getBalanceCents();
    }

    public function getPayPerUseCostCents(bool $isAi): int
    {
        return $isAi ? $this->payPerUseAiCents : $this->payPerUseCents;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function credit(
        User $user,
        int $amountCents,
        BalanceTransactionSource $source,
        ?string $refId = null,
        ?array $metadata = null,
    ): BalanceTransaction {
        $this->assertPositiveAmount($amountCents);

        return $this->applyCreditLike(
            $user,
            $amountCents,
            BalanceTransactionType::Credit,
            $source,
            $refId,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function debit(
        User $user,
        int $amountCents,
        BalanceTransactionSource $source,
        ?string $refId = null,
        ?array $metadata = null,
    ): BalanceTransaction {
        $this->assertPositiveAmount($amountCents);

        return $this->em->wrapInTransaction(function () use ($user, $amountCents, $source, $refId, $metadata): BalanceTransaction {
            $userId = $this->requireUserId($user);

            $affected = $this->em->getConnection()->executeStatement(
                'UPDATE users SET balance_cents = balance_cents - :amount WHERE id = :id AND balance_cents >= :amount',
                ['amount' => $amountCents, 'id' => $userId],
            );

            if ($affected === 0) {
                throw new InsufficientBalanceException(sprintf(
                    'Insufficient balance for user %d: need %d cents, have %d cents.',
                    $userId,
                    $amountCents,
                    $user->getBalanceCents(),
                ));
            }

            $tx = $this->createLedgerEntry(
                $user,
                -$amountCents,
                BalanceTransactionType::Debit,
                $source,
                $refId,
                $metadata,
            );

            $this->em->persist($tx);
            $this->em->flush();
            $this->em->refresh($user);

            return $tx;
        });
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function refund(
        User $user,
        int $amountCents,
        BalanceTransactionSource $source,
        ?string $refId = null,
        ?array $metadata = null,
    ): BalanceTransaction {
        $this->assertPositiveAmount($amountCents);

        return $this->applyCreditLike(
            $user,
            $amountCents,
            BalanceTransactionType::Refund,
            $source,
            $refId,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function applyCreditLike(
        User $user,
        int $amountCents,
        BalanceTransactionType $type,
        BalanceTransactionSource $source,
        ?string $refId,
        ?array $metadata,
    ): BalanceTransaction {
        return $this->em->wrapInTransaction(function () use ($user, $amountCents, $type, $source, $refId, $metadata): BalanceTransaction {
            $userId = $this->requireUserId($user);

            $this->em->getConnection()->executeStatement(
                'UPDATE users SET balance_cents = balance_cents + :amount WHERE id = :id',
                ['amount' => $amountCents, 'id' => $userId],
            );

            $tx = $this->createLedgerEntry($user, $amountCents, $type, $source, $refId, $metadata);

            $this->em->persist($tx);
            $this->em->flush();
            $this->em->refresh($user);

            return $tx;
        });
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function createLedgerEntry(
        User $user,
        int $signedAmountCents,
        BalanceTransactionType $type,
        BalanceTransactionSource $source,
        ?string $refId,
        ?array $metadata,
    ): BalanceTransaction {
        return (new BalanceTransaction())
            ->setUser($user)
            ->setAmountCents($signedAmountCents)
            ->setType($type)
            ->setSource($source)
            ->setRefId($refId)
            ->setMetadata($metadata);
    }

    private function assertPositiveAmount(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException(sprintf('Amount must be positive, got %d.', $amountCents));
        }
    }

    private function requireUserId(User $user): int
    {
        $id = $user->getId();
        if ($id === null) {
            throw new \LogicException('User must be persisted before balance operations.');
        }

        return $id;
    }
}
