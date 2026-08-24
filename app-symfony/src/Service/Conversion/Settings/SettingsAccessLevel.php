<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/**
 * Уровень доступа к полям настроек (CNV-85): ЛЕСТНИЦА, а не набор флагов —
 * каждый следующий уровень видит всё, что видит предыдущий.
 *
 * `Guest` — не залогинен (нет ROLE_USER, см. `ConversionController::convert()`);
 * остальные — значение `User::getPlan()` (`free`/`basic`/`pro`, см. таблицу
 * `plans`, миграция Version20260419000001). Неизвестное имя плана — НЕ ошибка
 * (колонка `users.plan` — свободная строка): резолвится в самый низкий
 * авторизованный уровень {@see self::Free}, чтобы неожиданное значение в БД
 * не открывало доступ, а закрывало его.
 */
enum SettingsAccessLevel: string
{
    case Guest = 'guest';
    case Free  = 'free';
    case Basic = 'basic';
    case Pro   = 'pro';

    /** Ранг для сравнения «не ниже чем» — единственный способ сравнивать уровни. */
    public function rank(): int
    {
        return match ($this) {
            self::Guest => 0,
            self::Free  => 1,
            self::Basic => 2,
            self::Pro   => 3,
        };
    }

    public function isAtLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * Имя плана из `User::getPlan()` → уровень. Вызывается ТОЛЬКО для
     * залогиненного (ROLE_USER); гость получает {@see self::Guest} без
     * обращения к плану.
     */
    public static function fromPlanName(string $plan): self
    {
        return self::tryFrom(strtolower(trim($plan))) ?? self::Free;
    }
}
