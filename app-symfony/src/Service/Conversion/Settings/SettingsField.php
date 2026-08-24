<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

use App\Exception\InvalidConversionOptionException;

/**
 * Одно поле профиля настроек (CNV-85) — ОПИСАНИЕ грамматики + её же исполнение.
 *
 * Описание (тип, границы, enum, plan-гейт, default) читается из каталога
 * `config/catalog/conversion_settings.json`; проверка присланного значения
 * ({@see normalizeValue()}) живёт ЗДЕСЬ же, поэтому «что показали в каталоге» и
 * «что приняли в POST /convert» физически не могут разойтись — второе
 * вычисляется тем же объектом, что и первое.
 *
 * Разбор каталога — ГРОМКИЙ: любая некорректность (неизвестный тип, `range`
 * без границ, `select` без вариантов, `default` вне собственных правил поля)
 * бросает `\RuntimeException` на загрузке, а не молча пропускается. Это точка
 * расширения для CNV-95/97/100/103/106 — она обязана падать сразу, а не
 * отдавать битый профиль клиенту.
 */
final class SettingsField
{
    /** Разрешённые ключи описания поля — всё прочее в каталоге = ошибка загрузки. */
    private const KNOWN_KEYS = [
        'key', 'type', 'label', 'minPlan', 'ai', 'default',
        'min', 'max', 'step', 'unit',
        'options',
        'minLength', 'maxLength', 'pattern',
    ];

    /**
     * @param list<SettingsSelectOption> $options варианты для `select`, иначе []
     */
    private function __construct(
        public readonly string $key,
        public readonly SettingsFieldType $type,
        public readonly string $label,
        public readonly SettingsAccessLevel $minPlan,
        /** Поле относится к AI-настройкам: в CNV-85 недоступно НИКОМУ (карточка: «basic/pro — все НЕ-AI поля»). */
        public readonly bool $ai,
        public readonly bool|int|string|null $default,
        public readonly ?int $min,
        public readonly ?int $max,
        public readonly int $step,
        public readonly ?string $unit,
        public readonly array $options,
        public readonly int $minLength,
        public readonly ?int $maxLength,
        public readonly ?string $pattern,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \RuntimeException при некорректном описании поля в каталоге
     */
    public static function fromArray(array $raw, string $profileId): self
    {
        $key = $raw['key'] ?? null;
        if (! is_string($key) || preg_match('/^[a-z][a-zA-Z0-9_]*$/', $key) !== 1) {
            throw new \RuntimeException("Settings catalog: profile \"{$profileId}\" has a field with a missing/invalid `key`");
        }
        $where = "profile \"{$profileId}\" field \"{$key}\"";

        $unknown = array_diff(array_keys($raw), self::KNOWN_KEYS);
        if ($unknown !== []) {
            throw new \RuntimeException("Settings catalog: {$where} — unknown keys: " . implode(', ', $unknown));
        }

        $typeRaw = $raw['type'] ?? null;
        if (! is_string($typeRaw)) {
            throw new \RuntimeException("Settings catalog: {$where} — `type` must be a string");
        }
        $type = SettingsFieldType::tryFrom($typeRaw);
        if ($type === null) {
            throw new \RuntimeException("Settings catalog: {$where} — unknown field type \"{$typeRaw}\"");
        }

        $label = $raw['label'] ?? null;
        if (! is_string($label) || $label === '') {
            throw new \RuntimeException("Settings catalog: {$where} — `label` must be a non-empty string");
        }

        // Guest-политика (см. CLAUDE.md): `minPlan` выбирается ПО СТОИМОСТИ поля
        // (CPU/память), а не «на всякий случай», и потому обязателен — implicit
        // default в любую сторону имеет тихий отказ: default=free тихо прячет
        // дешёвую фичу от гостя, default=guest тихо раздаёт дорогую бесплатно.
        // Явное требование ключа исключает оба случая и заставляет автора
        // профиля осознанно решить вопрос стоимости для каждого поля.
        if (! array_key_exists('minPlan', $raw)) {
            throw new \RuntimeException("Settings catalog: {$where} — `minPlan` is required (choose by cost, no implicit default)");
        }
        $minPlanRaw = $raw['minPlan'];
        if (! is_string($minPlanRaw)) {
            throw new \RuntimeException("Settings catalog: {$where} — `minPlan` must be a string");
        }
        $minPlan = SettingsAccessLevel::tryFrom($minPlanRaw);
        if ($minPlan === null) {
            throw new \RuntimeException("Settings catalog: {$where} — unknown `minPlan` \"{$minPlanRaw}\"");
        }

        $ai = $raw['ai'] ?? false;
        if (! is_bool($ai)) {
            throw new \RuntimeException("Settings catalog: {$where} — `ai` must be a boolean");
        }

        [$min, $max, $step, $unit] = self::parseNumeric($raw, $type, $where);
        $options                   = self::parseOptions($raw, $type, $where);
        self::assertOptionsRespectFieldMinPlan($options, $minPlan, $where);
        [$minLength, $maxLength, $pattern] = self::parseText($raw, $type, $where);

        $field = new self(
            $key,
            $type,
            $label,
            $minPlan,
            $ai,
            null,
            $min,
            $max,
            $step,
            $unit,
            $options,
            $minLength,
            $maxLength,
            $pattern,
        );

        $default = $raw['default'] ?? null;
        if ($default === null) {
            return $field;
        }

        // `default` проверяется ТЕМИ ЖЕ правилами, что и присланное значение —
        // профиль с невалидным дефолтом падает на загрузке, а не при первой
        // конвертации.
        try {
            $normalizedDefault = $field->normalizeValue($default);
        } catch (InvalidConversionOptionException $e) {
            throw new \RuntimeException("Settings catalog: {$where} — invalid `default`: " . $e->getMessage(), 0, $e);
        }

        return new self(
            $key,
            $type,
            $label,
            $minPlan,
            $ai,
            $normalizedDefault,
            $min,
            $max,
            $step,
            $unit,
            $options,
            $minLength,
            $maxLength,
            $pattern,
        );
    }

    /**
     * Поле РЕДАКТИРУЕМО на уровне `$level`. Ровно этот предикат используется и
     * при сериализации каталога (`editable` в `GET /formats`), и при приёме
     * `POST /convert`, поэтому расхождение «показали, но не приняли» невозможно
     * ПО ОСИ ПЛАНА.
     *
     * ⚠ Ось `ocr` — отдельная и этим предикатом НЕ покрыта: профиль резолвится
     * для НЕ-OCR маршрута, поэтому у `ocrCapable`-пары (напр. jpg→txt) каталог
     * покажет профиль, а submit с `ocr=1` вернёт 422 `settings_not_supported`
     * (так же было и до CNV-85 — опции с OCR не принимались никогда). Клиент
     * обязан прятать настройки при включённом OCR (задача CNV-92).
     */
    public function isEditableFor(SettingsAccessLevel $level): bool
    {
        if ($this->ai) {
            // AI-поля в CNV-85 не редактирует никто (карточка: доступны «все
            // НЕ-AI поля»). Отдельная AI-политика — предмет будущей карточки,
            // здесь достаточно закрытого гейта.
            return false;
        }

        return $level->isAtLeast($this->minPlan);
    }

    /**
     * Приводит присланное значение к каноническому виду ИЛИ бросает
     * {@see InvalidConversionOptionException} (тип / границы / enum / pattern).
     * План здесь НЕ проверяется — это делает {@see ConversionOptionsValidator},
     * у которого есть уровень доступа.
     */
    public function normalizeValue(mixed $value): bool|int|string
    {
        return match ($this->type) {
            SettingsFieldType::Range, SettingsFieldType::Number => $this->normalizeNumeric($value),
            SettingsFieldType::Boolean                          => $this->normalizeBoolean($value),
            SettingsFieldType::Color                            => $this->normalizeColor($value),
            SettingsFieldType::Select                           => $this->normalizeSelect($value),
            SettingsFieldType::Text                             => $this->normalizeText($value),
        };
    }

    /** Вариант `select` по значению (после {@see normalizeValue()}). */
    public function selectOption(string $value): ?SettingsSelectOption
    {
        foreach ($this->options as $option) {
            if ($option->value === $value) {
                return $option;
            }
        }

        return null;
    }

    /** @return array<string, mixed> публичное описание поля для `GET /formats` */
    public function toArray(SettingsAccessLevel $level): array
    {
        $out = [
            'key'      => $this->key,
            'type'     => $this->type->value,
            'label'    => $this->label,
            'minPlan'  => $this->minPlan->value,
            'ai'       => $this->ai,
            'default'  => $this->default,
            'editable' => $this->isEditableFor($level),
        ];

        if ($this->type->isBoundedNumeric()) {
            $out['min']  = $this->min;
            $out['max']  = $this->max;
            $out['step'] = $this->step;
            if ($this->unit !== null) {
                $out['unit'] = $this->unit;
            }
        }

        if ($this->type === SettingsFieldType::Select) {
            $out['options'] = array_map(
                static fn (SettingsSelectOption $o): array => $o->toArray($level),
                $this->options,
            );
        }

        if ($this->type === SettingsFieldType::Text) {
            $out['minLength'] = $this->minLength;
            $out['maxLength'] = $this->maxLength;
            if ($this->pattern !== null) {
                $out['pattern'] = $this->pattern;
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Разбор описания (загрузка каталога)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{0: ?int, 1: ?int, 2: int, 3: ?string}
     */
    private static function parseNumeric(array $raw, SettingsFieldType $type, string $where): array
    {
        if (! $type->isBoundedNumeric()) {
            foreach (['min', 'max', 'step', 'unit'] as $numericKey) {
                if (array_key_exists($numericKey, $raw)) {
                    throw new \RuntimeException("Settings catalog: {$where} — `{$numericKey}` is only valid for range/number fields");
                }
            }

            return [null, null, 1, null];
        }

        $min = $raw['min'] ?? null;
        $max = $raw['max'] ?? null;
        if (! is_int($min) || ! is_int($max)) {
            throw new \RuntimeException("Settings catalog: {$where} — range/number requires integer `min` and `max`");
        }
        if ($min > $max) {
            throw new \RuntimeException("Settings catalog: {$where} — `min` must not exceed `max`");
        }

        $step = $raw['step'] ?? 1;
        if (! is_int($step) || $step < 1) {
            throw new \RuntimeException("Settings catalog: {$where} — `step` must be a positive integer");
        }

        $unit = $raw['unit'] ?? null;
        if ($unit !== null && ! is_string($unit)) {
            throw new \RuntimeException("Settings catalog: {$where} — `unit` must be a string");
        }

        return [$min, $max, $step, $unit];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<SettingsSelectOption>
     */
    private static function parseOptions(array $raw, SettingsFieldType $type, string $where): array
    {
        if ($type !== SettingsFieldType::Select) {
            if (array_key_exists('options', $raw)) {
                throw new \RuntimeException("Settings catalog: {$where} — `options` is only valid for select fields");
            }

            return [];
        }

        $options = $raw['options'] ?? null;
        if (! is_array($options) || $options === []) {
            throw new \RuntimeException("Settings catalog: {$where} — select requires a non-empty `options` list");
        }

        $parsed = [];
        $seen   = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                throw new \RuntimeException("Settings catalog: {$where} — every select option must be an object");
            }
            /** @var array<string, mixed> $option */
            $parsedOption = SettingsSelectOption::fromArray($option, $where);
            if (isset($seen[$parsedOption->value])) {
                throw new \RuntimeException("Settings catalog: {$where} — duplicate select option \"{$parsedOption->value}\"");
            }
            $seen[$parsedOption->value] = true;
            $parsed[]                   = $parsedOption;
        }

        return $parsed;
    }

    /**
     * Загрузочный guard (CNV-103, находка ревью CNV-100): select-вариант не
     * может быть ДОСТУПНЕЕ поля, которое его содержит — иначе `GET /formats`
     * покажет `field.editable:false` рядом с `option.editable:true`. Submit
     * такое значение всё равно отклонит через {@see ConversionOptionsValidator}
     * (гейт поля проверяется раньше гейта варианта), поэтому инверсия — не
     * дыра в безопасности, а дефект отображения, но каталог с ней не должен
     * загрузиться вовсе, той же громкой политикой, что и остальные ошибки
     * этого класса.
     *
     * @param list<SettingsSelectOption> $options
     */
    private static function assertOptionsRespectFieldMinPlan(array $options, SettingsAccessLevel $minPlan, string $where): void
    {
        foreach ($options as $option) {
            if (! $option->minPlan->isAtLeast($minPlan)) {
                throw new \RuntimeException(
                    "Settings catalog: {$where} — select option \"{$option->value}\" has minPlan \"{$option->minPlan->value}\", "
                    . "which is below the field's own minPlan \"{$minPlan->value}\"",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{0: int, 1: ?int, 2: ?string}
     */
    private static function parseText(array $raw, SettingsFieldType $type, string $where): array
    {
        if ($type !== SettingsFieldType::Text) {
            foreach (['minLength', 'maxLength', 'pattern'] as $textKey) {
                if (array_key_exists($textKey, $raw)) {
                    throw new \RuntimeException("Settings catalog: {$where} — `{$textKey}` is only valid for text fields");
                }
            }

            return [0, null, null];
        }

        $maxLength = $raw['maxLength'] ?? null;
        if (! is_int($maxLength) || $maxLength < 1) {
            throw new \RuntimeException("Settings catalog: {$where} — text requires a positive integer `maxLength`");
        }

        $minLength = $raw['minLength'] ?? 0;
        if (! is_int($minLength) || $minLength < 0 || $minLength > $maxLength) {
            throw new \RuntimeException("Settings catalog: {$where} — `minLength` must be an integer in [0, maxLength]");
        }

        $pattern = $raw['pattern'] ?? null;
        if ($pattern !== null) {
            if (! is_string($pattern) || $pattern === '') {
                throw new \RuntimeException("Settings catalog: {$where} — `pattern` must be a non-empty string");
            }
            // Каталог хранит pattern БЕЗ разделителей и модификаторов — их
            // добавляем мы (см. compiledPattern()), поэтому подсунуть через
            // каталог `/e`-подобный модификатор невозможно.
            if (@preg_match(self::compiledPattern($pattern), '') === false) {
                throw new \RuntimeException("Settings catalog: {$where} — `pattern` is not a valid regular expression");
            }
        }

        return [$minLength, $maxLength, $pattern];
    }

    private static function compiledPattern(string $pattern): string
    {
        return '/^(?:' . $pattern . ')$/u';
    }

    // -----------------------------------------------------------------------
    // Нормализация значения
    // -----------------------------------------------------------------------

    private function normalizeNumeric(mixed $value): int
    {
        // Целое ЛИБО строка-целое: multipart/form-data всегда приносит строки,
        // поэтому строковая форма — основной боевой путь. Дробное, булево,
        // пустая строка и «01» отклоняются как неверный тип (историческое
        // поведение image-опций сохранено: см. CNV-85, hard constraint 1).
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_TYPE,
                "{$this->key} must be an integer",
            );
        }

        $min = $this->min ?? PHP_INT_MIN;
        $max = $this->max ?? PHP_INT_MAX;
        if ($int < $min || $int > $max) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_OUT_OF_RANGE,
                "{$this->key} must be between {$min} and {$max}",
            );
        }

        if ($this->step > 1 && ($int - $min) % $this->step !== 0) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_OUT_OF_RANGE,
                "{$this->key} must follow step {$this->step} from {$min}",
            );
        }

        return $int;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        throw new InvalidConversionOptionException(
            InvalidConversionOptionException::CODE_INVALID_TYPE,
            "{$this->key} must be a boolean",
        );
    }

    private function normalizeColor(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_TYPE,
                "{$this->key} must be a string",
            );
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_VALUE,
                "{$this->key} must be a #RRGGBB colour",
            );
        }

        return strtoupper($value);
    }

    private function normalizeSelect(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_TYPE,
                "{$this->key} must be a string",
            );
        }
        if ($this->selectOption($value) === null) {
            $allowed = implode(', ', array_map(static fn (SettingsSelectOption $o): string => $o->value, $this->options));

            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_VALUE,
                "{$this->key} must be one of: {$allowed}",
            );
        }

        return $value;
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_TYPE,
                "{$this->key} must be a string",
            );
        }

        $length = mb_strlen($value);
        $max    = $this->maxLength ?? 0;
        if ($length < $this->minLength || $length > $max) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_OUT_OF_RANGE,
                "{$this->key} length must be between {$this->minLength} and {$max}",
            );
        }

        if ($this->pattern !== null && preg_match(self::compiledPattern($this->pattern), $value) !== 1) {
            throw new InvalidConversionOptionException(
                InvalidConversionOptionException::CODE_INVALID_VALUE,
                "{$this->key} does not match the allowed pattern",
            );
        }

        return $value;
    }
}
