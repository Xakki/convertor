<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

use App\Service\Conversion\ConversionRegistry;

/**
 * Сборка тела `GET /api/v1/formats` (CNV-85): матрица пар + ДЕДУПЛИЦИРОВАННЫЕ
 * версионированные профили настроек, персонализированные под уровень доступа
 * вызывающего.
 *
 * Дедупликация: профиль сериализуется РОВНО ОДИН раз в `settings.profiles`,
 * каждая пара несёт только `settingsProfile` — id профиля ЛИБО `null`. `null`
 * присутствует всегда и явно: «у этой пары настроек нет» — а не «поле забыли».
 *
 * Персонализация — через `editable` у каждого поля/варианта. Предикат тот же
 * самый ({@see SettingsField::isEditableFor()}), которым `POST /convert`
 * принимает значение, поэтому «показали, но не приняли» невозможно по оси плана.
 *
 * ⚠ Профиль резолвится для НЕ-OCR маршрута (`ocr = false`), поэтому пара с
 * `ocrCapable: true` публикуется И с профилем: при submit с `ocr=1` опции будут
 * отвергнуты (422 `settings_not_supported`). Скрывать настройки при включённом
 * OCR — задача клиента (CNV-92). См. {@see SettingsField::isEditableFor()}.
 */
class ConversionCatalogPresenter
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly ConversionSettingsCatalog $catalog,
        private readonly ApiModelAvailability $apiModels,
    ) {
    }

    /**
     * @return array{
     *     formats: list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool, settingsProfile: string|null}>,
     *     settings: array{version: string, profiles: array<string, array{id: string, label: string, fields: list<array<string, mixed>>}>}
     * }
     */
    public function present(SettingsAccessLevel $level): array
    {
        $formats    = [];
        $used       = [];
        $apiProfile = $this->catalog->getProfiles()['api.chat'] ?? null;
        $modelField = $apiProfile?->field('model');
        $liveModels = $modelField?->dynamic === true ? $this->apiModels->current() : null;

        foreach ($this->registry->getSupportedFormats() as $pair) {
            $profileId = $this->catalog->resolveProfileId($pair['from'], $pair['to'], $pair['category']);
            if ($profileId === 'api.chat' && $liveModels === null) {
                continue;
            }
            if ($profileId !== null) {
                $used[$profileId] = true;
            }

            $pair['settingsProfile'] = $profileId;
            $formats[]               = $pair;
        }

        $profiles = [];
        foreach ($this->catalog->getProfiles() as $id => $profile) {
            // В ответ попадают ТОЛЬКО профили, на которые реально ссылается
            // хотя бы одна пара — объявленный, но никому не назначенный профиль
            // не раздувает payload.
            if (isset($used[$id])) {
                $profiles[$id] = $profile->toArray($level);
                if ($id === 'api.chat' && $liveModels !== null) {
                    foreach ($profiles[$id]['fields'] as &$field) {
                        if ($field['key'] !== 'model') {
                            continue;
                        }
                        $field['default'] = $liveModels['default'];
                        $field['options'] = array_map(
                            static fn (array $choice): array => $choice + ['minPlan' => $field['minPlan'], 'editable' => $field['editable']],
                            $liveModels['choices'],
                        );
                    }
                    unset($field);
                }
            }
        }

        return [
            'formats'  => $formats,
            'settings' => [
                'version'  => $this->catalog->getVersion(),
                'profiles' => $profiles,
            ],
        ];
    }
}
