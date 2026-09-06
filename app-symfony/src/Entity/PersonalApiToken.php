<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PersonalApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PersonalApiTokenRepository::class)]
#[ORM\Table(name: 'personal_api_tokens')]
#[ORM\Index(name: 'IDX_PERSONAL_TOKEN_VERIFIER', columns: ['verifier'])]
#[ORM\Index(name: 'IDX_PERSONAL_TOKEN_USER_ACTIVE', columns: ['user_id', 'revoked_at'])]
class PersonalApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    #[ORM\Column(type: 'string', length: 16)]
    private string $tokenPrefix;

    #[ORM\Column(type: 'string', length: 64)]
    private string $verifier;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $label, string $tokenPrefix, string $verifier)
    {
        $this->user        = $user;
        $this->label       = $label;
        $this->tokenPrefix = $tokenPrefix;
        $this->verifier    = $verifier;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getUser(): User
    {
        return $this->user;
    }
    public function getLabel(): string
    {
        return $this->label;
    }
    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
    public function setLastUsedAt(?\DateTimeImmutable $value): self
    {
        $this->lastUsedAt = $value;

        return $this;
    }
    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
    public function revoke(\DateTimeImmutable $at = new \DateTimeImmutable()): self
    {
        $this->revokedAt = $at;

        return $this;
    }
    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
    public function matches(string $secret): bool
    {
        return hash_equals($this->verifier, hash('sha256', $secret));
    }
}
