<?php

declare(strict_types=1);

namespace App\Service\Oauth;

/**
 * Запрошен провайдер, которого нет в {@see OauthProviderRegistry} (неизвестный
 * ключ или провайдер не сконфигурирован в этом окружении). Контроллер маппит
 * это в HTTP 404.
 */
final class UnknownOauthProviderException extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Unknown or unconfigured OAuth provider: %s', $key));
    }
}
