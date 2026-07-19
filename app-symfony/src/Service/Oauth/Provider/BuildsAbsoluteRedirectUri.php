<?php

declare(strict_types=1);

namespace App\Service\Oauth\Provider;

use App\Service\Oauth\InvalidOauthRedirectBaseUrlException;

/**
 * Общий билдер `redirect_uri` для всех 4 OAuth-адаптеров (Google/GitHub/
 * Yandex/VK) — вместо копии в каждом классе. Fail loud: `APP_URL` (см.
 * `$redirectBaseUrl` в services.yaml) пуст/относителен → сразу
 * {@see InvalidOauthRedirectBaseUrlException} вместо тихой отправки битого
 * (относительного) redirect_uri провайдеру (инцидент 2026-07-19 — все 4
 * OAuth-логина ломались без понятной ошибки в логах).
 */
trait BuildsAbsoluteRedirectUri
{
    private function buildRedirectUri(string $redirectBaseUrl, string $providerKey): string
    {
        if (! preg_match('#^https?://#i', $redirectBaseUrl)) {
            throw new InvalidOauthRedirectBaseUrlException($providerKey, $redirectBaseUrl);
        }

        return rtrim($redirectBaseUrl, '/') . '/api/v1/auth/oauth/' . $providerKey . '/callback';
    }
}
