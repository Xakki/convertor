<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentGateway;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'UNIQ_PAYMENTS_EXTERNAL_ID', columns: ['external_id'])]
#[ORM\Index(name: 'IDX_PAYMENTS_USER_ID', columns: ['user_id'])]
#[ORM\Index(name: 'IDX_PAYMENTS_STATUS', columns: ['status'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'float')]
    private float $amount;

    #[ORM\Column(type: 'string', length: 10)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 30, enumType: PaymentGateway::class)]
    private PaymentGateway $gateway;

    #[ORM\Column(type: 'string', length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Pending;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $externalId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

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

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getGateway(): PaymentGateway
    {
        return $this->gateway;
    }

    public function setGateway(PaymentGateway $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
