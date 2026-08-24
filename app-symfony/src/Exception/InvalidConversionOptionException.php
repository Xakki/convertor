<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Отказ server-side валидации опций конвертации (CNV-85).
 *
 * Несёт МАШИННЫЙ код (`getErrorCode()`) — он уходит клиенту в поле `error`,
 * человекочитаемая деталь — в `message` (конвенция ошибок API, см. скилл
 * `api-design`). Контроллер маппит это исключение в 422.
 *
 * Коды закрыты этим списком констант — фронт (CNV-92) и документация (CNV-93)
 * опираются на них, поэтому новый код добавляется сюда, а не строкой на месте.
 */
class InvalidConversionOptionException extends \RuntimeException
{
    /** У пары `from→to` нет профиля настроек, а клиент прислал опции. */
    public const CODE_NOT_SUPPORTED = 'settings_not_supported';

    /** Ключ отсутствует в профиле пары. */
    public const CODE_UNKNOWN_OPTION = 'unknown_option';

    /** Значение не того типа, что объявлен полем. */
    public const CODE_INVALID_TYPE = 'invalid_option_type';

    /** Значение вне объявленных границ `min`/`max` (или шага `step`). */
    public const CODE_OUT_OF_RANGE = 'option_out_of_range';

    /** Значение не входит в enum / не совпало с pattern / не `#RRGGBB`. */
    public const CODE_INVALID_VALUE = 'invalid_option_value';

    /** Поле (или конкретный вариант select) недоступно на текущем плане. */
    public const CODE_PLAN_REQUIRED = 'option_plan_required';

    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
