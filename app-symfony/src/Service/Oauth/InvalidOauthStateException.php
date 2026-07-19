<?php

declare(strict_types=1);

namespace App\Service\Oauth;

/**
 * `state` OAuth-callback'а не прошёл проверку: неизвестен, протух, уже погашен
 * (replay) или принадлежит другому провайдеру (CSRF). Контроллер маппит это в
 * редирект на `/login?oauth_error=state`.
 */
final class InvalidOauthStateException extends \RuntimeException
{
}
