<?php

declare(strict_types=1);

namespace App\Service\Oauth;

/**
 * Результат гашения `state` в {@see OauthStateStore::consume()}: сопутствующие
 * данные, привязанные к state на этапе `start`. Пока — только PKCE `codeVerifier`;
 * device_id VK приходит в query callback'а, не хранится здесь.
 */
final readonly class OauthStateData
{
    public function __construct(
        public ?string $codeVerifier = null,
    ) {
    }
}
