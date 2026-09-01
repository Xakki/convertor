<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

use App\Exception\InvalidConversionOptionException;
use App\Service\Conversion\ConversionRegistry;

/**
 * Server-side валидация и НОРМАЛИЗАЦИЯ опций конвертации (CNV-85).
 *
 * Это единственная дверь, через которую опции попадают в задачу: `POST /convert`
 * не доверяет тому, что показал каталог, а заново резолвит профиль пары,
 * проверяет доступ по плану, тип, границы, enum/pattern — и отдаёт наружу
 * ТОЛЬКО whitelisted-значения в каноническом виде. Ключа, которого нет в
 * профиле, в результате быть не может физически: результат собирается обходом
 * полей профиля, а не копированием входного массива.
 *
 * Нормализация (AC карточки): поля профиля с непустым `default`
 * материализуются в РЕЗУЛЬТАТ, даже если клиент их не прислал — в задачу и в
 * историю уходит применённое значение, а не «пусто»/sentinel. Для полей без
 * `default` отсутствие остаётся отсутствием (у image-профилей CNV-85 все
 * `default` = null, поэтому боевой payload не меняется — см. hard constraint 1).
 */
class ConversionOptionsValidator
{
    public function __construct(
        private readonly ConversionSettingsCatalog $catalog,
        private readonly ConversionRegistry $registry,
        private readonly ApiModelAvailability $apiModels,
    ) {
    }

    /**
     * Профиль пары с точки зрения роутинга (категория берётся из реестра, а не
     * от клиента). `null` для неизвестной реестру пары — у неё настроек нет.
     *
     * `$animated` (CNV-106) — тот же request-scoped дискриминатор, что и
     * `$ocr` (см. `ConversionSettingsCatalog` class docblock). Ни один живой
     * вызывающий код сегодня не передаёт `true` — контроллер не читает такое
     * поле запроса вовсе, поэтому пара, у которой есть animated-профиль
     * (сегодня — только svg→gif, CNV-106), продолжает резолвиться в свой
     * ОБЫЧНЫЙ профиль для любого реального запроса.
     */
    public function resolveProfile(string $from, string $to, bool $ocr = false, bool $animated = false): ?SettingsProfile
    {
        if (! $this->registry->isSupported($from, $to)) {
            return null;
        }

        return $this->catalog->resolveProfile($from, $to, $this->registry->getCategory($from, $to)->value, $ocr, $animated);
    }

    /**
     * @param array<mixed> $raw присланные клиентом опции (multipart → строки)
     *
     * @return array<string, bool|int|string> нормализованные whitelisted-опции
     *
     * @throws InvalidConversionOptionException 422 на любой из отказов карточки
     */
    public function validate(
        string $from,
        string $to,
        array $raw,
        SettingsAccessLevel $level,
        bool $ocr = false,
        bool $animated = false,
    ): array {
        $profile = $this->resolveProfile($from, $to, $ocr, $animated);

        if ($profile === null) {
            if ($raw !== []) {
                throw new InvalidConversionOptionException(
                    InvalidConversionOptionException::CODE_NOT_SUPPORTED,
                    "Conversion {$from} → {$to} has no configurable settings",
                );
            }

            return [];
        }

        $liveModels = null;
        if ($profile->id === 'api.chat') {
            $modelField = $profile->field('model');
            $liveModels = $modelField?->dynamic === true ? $this->apiModels->current() : null;
            if ($liveModels === null) {
                throw new InvalidConversionOptionException(
                    InvalidConversionOptionException::CODE_NOT_SUPPORTED,
                    'Chat conversion has no live validated API model',
                );
            }
            if (array_key_exists('model', $raw)
                && (! is_string($raw['model'])
                    || ! in_array($raw['model'], array_column($liveModels['choices'], 'value'), true))) {
                throw new InvalidConversionOptionException(
                    InvalidConversionOptionException::CODE_INVALID_VALUE,
                    'Selected chat model is not currently available',
                );
            }
        }

        foreach (array_keys($raw) as $key) {
            if (! is_string($key) || $profile->field($key) === null) {
                $printable = is_string($key) ? $key : (string) $key;

                throw new InvalidConversionOptionException(
                    InvalidConversionOptionException::CODE_UNKNOWN_OPTION,
                    "Unknown option \"{$printable}\" for {$from} → {$to}",
                );
            }
        }

        $normalized = [];
        foreach ($profile->fields as $field) {
            if (! array_key_exists($field->key, $raw)) {
                // Дефолт материализуется НЕЗАВИСИМО от плана: гость, который не
                // может редактировать поле, всё равно получает его применённое
                // значение по умолчанию.
                if ($field->default !== null) {
                    $normalized[$field->key] = $field->default;
                }
                continue;
            }

            if (! $field->isEditableFor($level)) {
                throw new InvalidConversionOptionException(
                    InvalidConversionOptionException::CODE_PLAN_REQUIRED,
                    "Option \"{$field->key}\" is not available on your plan",
                );
            }

            if ($liveModels !== null && $field->key === 'model') {
                $normalized[$field->key] = $raw[$field->key];
                continue;
            }

            $value = $field->normalizeValue($raw[$field->key]);

            if ($field->type === SettingsFieldType::Select) {
                $option = $field->selectOption((string) $value);
                if ($option !== null && ! $option->isEditableFor($level)) {
                    throw new InvalidConversionOptionException(
                        InvalidConversionOptionException::CODE_PLAN_REQUIRED,
                        "Value \"{$value}\" of option \"{$field->key}\" is not available on your plan",
                    );
                }
            }

            $normalized[$field->key] = $value;
        }

        if ($liveModels !== null && ! array_key_exists('model', $normalized)) {
            $normalized['model'] = $liveModels['default'];
        }

        return $normalized;
    }
}
