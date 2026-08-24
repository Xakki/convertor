<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/**
 * ЗАКРЫТАЯ грамматика полей настроек конвертации (CNV-85).
 *
 * Другого типа поля не существует и появиться не может без правки этого enum —
 * это и есть гарантия «никаких raw-аргументов движка»: клиент присылает только
 * значения объявленных полей, а не куски командной строки FFmpeg/LibreOffice.
 *
 * Семантика типов:
 *  - `Range`   — целое в [min, max] с шагом `step` (UI: слайдер);
 *  - `Number`  — целое в [min, max] (UI: числовой инпут); валидация та же, что
 *                у `Range`, различие ЧИСТО презентационное;
 *  - `Select`  — значение из явного списка `options` (каждый вариант может
 *                иметь собственный `minPlan`);
 *  - `Text`    — строка, ограниченная `maxLength` и опциональным `pattern`;
 *  - `Boolean` — true/false;
 *  - `Color`   — строка строго вида `#RRGGBB` (нормализуется в верхний регистр).
 */
enum SettingsFieldType: string
{
    case Range   = 'range';
    case Select  = 'select';
    case Number  = 'number';
    case Text    = 'text';
    case Boolean = 'boolean';
    case Color   = 'color';

    /** Числовые типы с обязательными границами `min`/`max`. */
    public function isBoundedNumeric(): bool
    {
        return $this === self::Range || $this === self::Number;
    }
}
