<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Бросается когда пара (from→to) отключена админом
 * ({@see \App\Service\Conversion\ConversionToggleService}). Контроллер ловит и
 * отдаёт HTTP 409 с телом `{error:"conversion_disabled", message:"…"}`.
 * Отличается от «неподдерживаемой» пары (пара валидна, но временно выключена).
 */
final class ConversionDisabledException extends \RuntimeException
{
}
