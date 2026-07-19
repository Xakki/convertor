<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider\Yandex;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Сырой userinfo-ответ `GET https://login.yandex.ru/info?format=json`, типизированный
 * геттерами. Email-квирк (нет top-level `email`, только `default_email`/`emails[]`)
 * НЕ резолвится здесь — эта обёртка только читает сырые поля, решение какое значение
 * считать email/verified принимает {@see \App\Service\Oauth\Provider\YandexProvider}.
 *
 * Пример ответа: {@link https://yandex.ru/dev/id/doc/ru/user-information#response-format}
 */
final class YandexResourceOwner implements ResourceOwnerInterface
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(private readonly array $response)
    {
    }

    /** Стабильный numeric-id аккаунта Yandex (`id`). */
    public function getId(): ?string
    {
        $id = $this->response['id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }

    public function getLogin(): ?string
    {
        $login = $this->response['login'] ?? null;

        return is_string($login) && $login !== '' ? $login : null;
    }

    public function getDisplayName(): ?string
    {
        $name = $this->response['display_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Primary-адрес по мнению Yandex (см. email-квирк в PHPDoc класса). */
    public function getDefaultEmail(): ?string
    {
        $email = $this->response['default_email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Полный список email-адресов аккаунта (может быть пустым/отсутствовать,
     * если scope `login:email` не выдан).
     *
     * @return list<string>
     */
    public function getEmails(): array
    {
        $emails = $this->response['emails'] ?? null;
        if (! is_array($emails)) {
            return [];
        }

        return array_values(array_filter(
            $emails,
            static fn (mixed $email): bool => is_string($email) && $email !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }
}
