<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_TELEGRAM_ID', columns: ['telegram_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_PHONE', columns: ['phone'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_EMAIL', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_GUEST_ID', columns: ['guest_id'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface
{
    // Nullable: транзиентный (ещё не персистнутый) гость имеет id===null до
    // ленивой материализации в ConversionManager::createConversion.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $telegramId = null;

    /**
     * Имя из Telegram-профиля (`first_name`). Обновляется при каждом bot-логине.
     * Nullable — у гостя и у старых записей отсутствует.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $firstName = null;

    /**
     * Username из Telegram-профиля (без @). Обновляется при каждом bot-логине
     * (username в TG изменяем). Nullable — не у всех пользователей он задан.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username = null;

    /**
     * Наша ссылка на закешированный в S3 аватар (ключ объекта, напр.
     * `avatars/{id}.jpg`), НЕ сырой TG-URL. Сырой getFile-URL несёт bot-токен и
     * протухает — его НЕ персистим и не отдаём наружу. Nullable — аватара может
     * не быть (пользователь без фото / фетч не удался).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $photoUrl = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $plan = 'free';

    #[ORM\Column(type: 'integer')]
    private int $lightDailyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $lightMonthlyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $mediumDailyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $mediumMonthlyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $heavyDailyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $heavyMonthlyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $aiDailyConversions = 0;

    #[ORM\Column(type: 'integer')]
    private int $aiMonthlyConversions = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $quotaResetAt;

    /** Начало текущего скользящего 30-дневного месячного окна (CNV-30). */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $monthlyResetAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /**
     * Админ-тир поверх обычного пользователя. Флаг выдаётся вручную
     * (console-команда app:user:make-admin), UI-управления ролями нет.
     * В getRoles() даёт ROLE_ADMIN — доступ к ^/admin и ^/api/v1/admin.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isAdmin = false;

    /**
     * Гость — анонимный пользователь, привязанный к httpOnly-cookie `guest_id`.
     * `telegramId`/`phone`/`email` у гостя = null. При Telegram-логине история
     * гостя перепривязывается к реальному User (см. GuestUserService::mergeInto).
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isGuest = false;

    /**
     * Сырое значение cookie-id гостя (уникальное). У обычного пользователя = null.
     * Аутентификатор ищет гостя по этому полю (только среди активных).
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $guestId = null;

    public function __construct()
    {
        $now                  = new \DateTimeImmutable();
        $this->createdAt      = $now;
        $this->quotaResetAt   = $now;
        $this->monthlyResetAt = $now;
    }

    public function getId(): ?int
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

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

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

    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl;
    }

    public function setPhotoUrl(?string $photoUrl): self
    {
        $this->photoUrl = $photoUrl;

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

    public function getLightDailyConversions(): int
    {
        return $this->lightDailyConversions;
    }

    public function setLightDailyConversions(int $lightDailyConversions): self
    {
        $this->lightDailyConversions = $lightDailyConversions;

        return $this;
    }

    public function getLightMonthlyConversions(): int
    {
        return $this->lightMonthlyConversions;
    }

    public function setLightMonthlyConversions(int $lightMonthlyConversions): self
    {
        $this->lightMonthlyConversions = $lightMonthlyConversions;

        return $this;
    }

    public function getMediumDailyConversions(): int
    {
        return $this->mediumDailyConversions;
    }

    public function setMediumDailyConversions(int $mediumDailyConversions): self
    {
        $this->mediumDailyConversions = $mediumDailyConversions;

        return $this;
    }

    public function getMediumMonthlyConversions(): int
    {
        return $this->mediumMonthlyConversions;
    }

    public function setMediumMonthlyConversions(int $mediumMonthlyConversions): self
    {
        $this->mediumMonthlyConversions = $mediumMonthlyConversions;

        return $this;
    }

    public function getHeavyDailyConversions(): int
    {
        return $this->heavyDailyConversions;
    }

    public function setHeavyDailyConversions(int $heavyDailyConversions): self
    {
        $this->heavyDailyConversions = $heavyDailyConversions;

        return $this;
    }

    public function getHeavyMonthlyConversions(): int
    {
        return $this->heavyMonthlyConversions;
    }

    public function setHeavyMonthlyConversions(int $heavyMonthlyConversions): self
    {
        $this->heavyMonthlyConversions = $heavyMonthlyConversions;

        return $this;
    }

    public function getAiDailyConversions(): int
    {
        return $this->aiDailyConversions;
    }

    public function setAiDailyConversions(int $aiDailyConversions): self
    {
        $this->aiDailyConversions = $aiDailyConversions;

        return $this;
    }

    public function getAiMonthlyConversions(): int
    {
        return $this->aiMonthlyConversions;
    }

    public function setAiMonthlyConversions(int $aiMonthlyConversions): self
    {
        $this->aiMonthlyConversions = $aiMonthlyConversions;

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

    public function getMonthlyResetAt(): \DateTimeImmutable
    {
        return $this->monthlyResetAt;
    }

    public function setMonthlyResetAt(\DateTimeImmutable $monthlyResetAt): self
    {
        $this->monthlyResetAt = $monthlyResetAt;

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

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function setIsAdmin(bool $isAdmin): self
    {
        $this->isAdmin = $isAdmin;

        return $this;
    }

    public function isGuest(): bool
    {
        return $this->isGuest;
    }

    public function setIsGuest(bool $isGuest): self
    {
        $this->isGuest = $isGuest;

        return $this;
    }

    public function getGuestId(): ?string
    {
        return $this->guestId;
    }

    public function setGuestId(?string $guestId): self
    {
        $this->guestId = $guestId;

        return $this;
    }

    public function getRoles(): array
    {
        // Гость получает ТОЛЬКО ROLE_GUEST — это единственный признак, по которому
        // гейт ai/video режет анонима (!isGranted('ROLE_USER')). role_hierarchy
        // (ROLE_USER: [ROLE_GUEST]) даёт залогиненному проходить guest-роуты.
        if ($this->isGuest) {
            return ['ROLE_GUEST'];
        }

        // Обычный пользователь → ROLE_USER; админ дополнительно получает
        // ROLE_ADMIN (гейт ^/admin и ^/api/v1/admin в security.yaml).
        $roles = ['ROLE_USER'];
        if ($this->isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        // Гость идентифицируется по guestId: транзиентный (ещё не персистнутый)
        // гость имеет id===null, но всегда имеет guestId. Обычный пользователь — по id.
        $identifier = $this->isGuest ? (string) $this->guestId : (string) $this->id;

        if ($identifier === '') {
            // Инвариант: гость всегда с guestId, обычный юзер — с id (после persist).
            throw new \LogicException('User has no identifier (transient without guestId/id)');
        }

        return $identifier;
    }
}
