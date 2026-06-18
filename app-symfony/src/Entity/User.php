<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'bigint', nullable: true, unique: true)]
    private ?string $telegramId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, unique: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $plan = 'free';

    #[ORM\Column(type: 'integer')]
    private int $dailyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $dailyAiConversions = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $quotaResetAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->quotaResetAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegramId;
    }

    public function setTelegramId(?string $telegramId): self
    {
        $this->telegramId = $telegramId;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function getDailyConversions(): int
    {
        return $this->dailyConversions;
    }

    public function setDailyConversions(int $dailyConversions): self
    {
        $this->dailyConversions = $dailyConversions;
        return $this;
    }

    public function incrementDailyConversions(): self
    {
        $this->dailyConversions++;
        return $this;
    }

    public function getDailyAiConversions(): int
    {
        return $this->dailyAiConversions;
    }

    public function setDailyAiConversions(int $dailyAiConversions): self
    {
        $this->dailyAiConversions = $dailyAiConversions;
        return $this;
    }

    public function incrementDailyAiConversions(): self
    {
        $this->dailyAiConversions++;
        return $this;
    }

    public function getQuotaResetAt(): \DateTimeImmutable
    {
        return $this->quotaResetAt;
    }

    public function setQuotaResetAt(\DateTimeImmutable $quotaResetAt): self
    {
        $this->quotaResetAt = $quotaResetAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }
}
