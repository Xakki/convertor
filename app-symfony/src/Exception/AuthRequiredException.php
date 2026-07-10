<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Бросается когда пара требует полного логина (ai или video), а текущий
 * пользователь — гость (нет ROLE_USER). Контроллер ловит и отдаёт HTTP 403
 * с телом `{error:"auth_required", message:"…"}`.
 */
final class AuthRequiredException extends \RuntimeException
{
}
