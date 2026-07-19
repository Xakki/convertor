<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Нормализованный профиль пользователя от OAuth-провайдера — единый вход в
 * {@see \App\Service\Oauth\SocialIdentityResolver::findOrCreateUser()}. Каждый
 * провайдер-адаптер приводит свой сырой ответ к этому DTO, чтобы ядро
 * link/create не зависело от формата конкретного провайдера.
 *
 * `emailVerified` — доверять email для линковки к существующему User МОЖНО
 * только если true (см. «Ядро корректности» в oauth-00-epic). Провайдер обязан
 * ставить false, если не подтвердил адрес.
 */
final readonly class OAuthUserInfo
{
    public function __construct(
        public string $providerUid,
        public ?string $email,
        public bool $emailVerified,
        public ?string $username = null,
        public ?string $displayName = null,
    ) {
    }
}
