<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SocialIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Привязка внешнего OAuth-провайдера (google|github|yandex|vk) к нашему User.
 * Один User может иметь несколько провайдеров (ManyToOne → User). Telegram-логин
 * НЕ хранится здесь — он остаётся отдельным механизмом (User.telegramId).
 *
 * `email` фиксируется на момент линковки (verified у провайдера); если провайдер
 * email не отдал — синтетический плейсхолдер `{provider}:{uid}@{provider}.oauth.local`,
 * чтобы поле было NOT NULL и никогда не совпало с реальным адресом при поиске.
 *
 * UNIQUE(provider, provider_uid) — одна и та же учётка провайдера не может
 * привязаться дважды; на этот индекс опирается race-обработка в
 * {@see \App\Service\Oauth\SocialIdentityResolver}.
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\Table(name: 'social_identities')]
#[ORM\UniqueConstraint(name: 'uniq_social_provider_uid', columns: ['provider', 'provider_uid'])]
class SocialIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 32)]
    private string $provider;

    #[ORM\Column(type: 'string', length: 255)]
    private string $providerUid;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProviderUid(): string
    {
        return $this->providerUid;
    }

    public function setProviderUid(string $providerUid): self
    {
        $this->providerUid = $providerUid;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
