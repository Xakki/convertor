<?php

declare(strict_types=1);

namespace App\Service\Oauth;

/**
 * `APP_URL` (переиспользуется как base для redirect_uri провайдеров — см.
 * services.yaml) пуст или не абсолютный `http(s)://` URL. Раньше это молча
 * приводило к ОТНОСИТЕЛЬНОМУ `redirect_uri` в authorize-URL — все провайдеры
 * (Google/GitHub/Yandex/VK) отвергали такой запрос без понятной ошибки в
 * логах (инцидент 2026-07-19: все 4 OAuth-логина были сломаны). Теперь
 * адаптер падает сразу при конструировании с явной причиной вместо тихой
 * отправки битого redirect_uri. `APP_URL` задан в трекаемом `app-symfony/.env`
 * (не секрет — публичный origin приложения).
 */
final class InvalidOauthRedirectBaseUrlException extends \RuntimeException
{
    public function __construct(string $providerKey, string $redirectBaseUrl)
    {
        parent::__construct(sprintf(
            'OAuth provider "%s": APP_URL must be an absolute http(s):// URL, got %s. Check app-symfony/.env / .env.local.',
            $providerKey,
            $redirectBaseUrl === '' ? '(empty)' : var_export($redirectBaseUrl, true),
        ));
    }
}
