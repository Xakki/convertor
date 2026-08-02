<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BalanceTransactionSource;
use App\Enum\BalanceTransactionType;
use App\Repository\BalanceTransactionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only ledger prepaid-баланса (CNV-28). Сумма в USD cents; +credit / −debit.
 */
#[ORM\Entity(repositoryClass: BalanceTransactionRepository::class)]
#[ORM\Table(name: 'balance_transactions')]
#[ORM\Index(name: 'IDX_BALANCE_TRANSACTIONS_USER_ID', columns: ['user_id'])]
#[ORM\Index(name: 'IDX_BALANCE_TRANSACTIONS_CREATED_AT', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_BALANCE_TRANSACTIONS_USER_CREATED_AT', columns: ['user_id', 'created_at'])]
class BalanceTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /** Подписанная сумма в USD cents: положительная — зачисление, отрицательная — списание. */
    #[ORM\Column(type: 'integer')]
    private int $amountCents;

    #[ORM\Column(type: 'string', length: 20, enumType: BalanceTransactionType::class)]
    private BalanceTransactionType $type;

    #[ORM\Column(type: 'string', length: 20, enumType: BalanceTransactionSource::class)]
    private BalanceTransactionSource $source;

    /** Внешний ключ операции (напр. telegram_payment_charge_id) — до 255, как payments.external_id. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $refId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getType(): BalanceTransactionType
    {
        return $this->type;
    }

    public function setType(BalanceTransactionType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSource(): BalanceTransactionSource
    {
        return $this->source;
    }

    public function setSource(BalanceTransactionSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getRefId(): ?string
    {
        return $this->refId;
    }

    public function setRefId(?string $refId): self
    {
        $this->refId = $refId;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
