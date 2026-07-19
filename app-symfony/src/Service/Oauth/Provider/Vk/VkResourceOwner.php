<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider\Vk;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Сырой userinfo-ответ `POST https://id.vk.com/oauth2/user_info`, типизированный
 * геттерами. VK ID оборачивает профиль во вложенный объект `user` — эта обёртка
 * читает его "как есть", решение какое значение считать email/verified
 * принимает {@see \App\Service\Oauth\Provider\VkProvider} (та же схема, что
 * {@see \App\Service\Oauth\Provider\Yandex\YandexResourceOwner}).
 *
 * Пример ответа: `{"user": {"user_id": "123", "first_name": "...",
 * "last_name": "...", "email": "...", "phone": "...", "avatar": "..."}}`.
 */
final class VkResourceOwner implements ResourceOwnerInterface
{
    /** @var array<string, mixed> */
    private readonly array $user;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(private readonly array $response)
    {
        $user       = $this->response['user'] ?? null;
        $this->user = is_array($user) ? $user : [];
    }

    /** Стабильный numeric-id аккаунта VK (`user.user_id`, фоллбек `user.id`). */
    public function getId(): ?string
    {
        $id = $this->user['user_id'] ?? $this->user['id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }

    public function getFirstName(): ?string
    {
        return $this->stringOrNull($this->user['first_name'] ?? null);
    }

    public function getLastName(): ?string
    {
        return $this->stringOrNull($this->user['last_name'] ?? null);
    }

    /** Email VK ID не гарантирует — см. email-квирк в PHPDoc {@see \App\Service\Oauth\Provider\VkProvider}. */
    public function getEmail(): ?string
    {
        return $this->stringOrNull($this->user['email'] ?? null);
    }

    public function getPhone(): ?string
    {
        return $this->stringOrNull($this->user['phone'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
