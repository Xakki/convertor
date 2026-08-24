<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/**
 * Каталог настроек конвертации (CNV-85) — ЕДИНСТВЕННЫЙ источник правды о том,
 * какие поля существуют и какой паре `from→to` какой профиль назначен.
 *
 * Артефакт — коммиченный `config/catalog/conversion_settings.json`. Он НЕ
 * генерируется (в отличие от `conversion_pairs.json`, который делает
 * `make formats-catalog` и стерегут `ConversionPairsCatalogDriftTest` /
 * `ConversionRegistryGoldenTest`) и НЕ трогает тот файл: профили «накладываются»
 * на пары правилами {@see $assignments}, а не переписыванием матрицы.
 *
 * Структура файла:
 * ```
 * {
 *   "version":  "<строка версии каталога, уходит клиенту>",
 *   "profiles": { "<id>": { "label": "...", "fields": [ ... ] } },
 *   "assignments": [ { "profile": "<id>", "category": "image", "to": ["jpg"], "ocr": false } ]
 * }
 * ```
 *
 * **Как доменной карточке (CNV-95/97/100/103/106) добавить свой профиль:**
 *  1) добавить объект в `profiles` под своим id (`document.pdf`, `media.audio`…);
 *  2) добавить в конец `assignments` блок правил своей категории.
 * Правила проверяются СВЕРХУ ВНИЗ, первое совпадение выигрывает, поэтому блоки
 * разных категорий независимы и порядок между ними значения не имеет. Ни один
 * PHP-класс при этом не меняется.
 *
 * Матчер правила: `category` (строка `FileCategory`), `from` / `to` (списки
 * форматов), `ocr` (bool) и `animated` (bool, CNV-106) — любой опущенный ключ
 * означает «любое значение». `animated` — request-scoped дискриминатор той же
 * формы, что и `ocr` (см. `ConversionRegistry::streamFor()`/class docblock и
 * `$comment` файла каталога): нужен, потому что animated SVG→GIF (CNV-106) —
 * ТА ЖЕ from/to пара, что и уже опубликованный статичный svg→gif (CNV-95),
 * и `conversion_pairs.json` физически не может нести два маршрута на одну
 * пару. `ConversionCatalogPresenter`/`POST /convert` резолвят профиль ТОЛЬКО
 * с `animated=false` (параметр не читается ни из какого запроса сегодня) —
 * так пара НЕ публикуется, пока владелец browser-воркера не заведёт это поле
 * так же, как уже заведено `ocr`.
 *
 * Ошибки файла — ГРОМКИЕ (`\RuntimeException` на загрузке), той же политикой,
 * что и `ConversionRegistry::loadCatalogMatrix()`: битый каталог настроек
 * должен падать сразу, а не отдавать клиенту профиль-призрак.
 */
class ConversionSettingsCatalog
{
    private const DEFAULT_CATALOG_RELATIVE_PATH = '/config/catalog/conversion_settings.json';

    private ?string $version = null;

    /** @var array<string, SettingsProfile>|null */
    private ?array $profiles = null;

    /** @var list<array{profile: string, category: ?string, from: ?list<string>, to: ?list<string>, ocr: ?bool, animated: ?bool}>|null */
    private ?array $assignments = null;

    /**
     * `$catalogPath` — DI-биндинг в `config/services.yaml`. `null` (например
     * `new ConversionSettingsCatalog()` в unit-тесте без контейнера)
     * резолвится в ТОТ ЖЕ реальный коммиченный файл, так что тест без явного
     * пути видит боевой каталог. Тесты, которым нужен маленький синтетический
     * каталог, передают путь к своему JSON той же формы.
     */
    public function __construct(
        private readonly ?string $catalogPath = null,
    ) {
    }

    public function getVersion(): string
    {
        $this->load();

        return (string) $this->version;
    }

    /** @return array<string, SettingsProfile> id → профиль */
    public function getProfiles(): array
    {
        $this->load();

        return (array) $this->profiles;
    }

    public function getProfile(string $id): ?SettingsProfile
    {
        return $this->getProfiles()[$id] ?? null;
    }

    /**
     * Профиль, назначенный конкретной паре, ЛИБО `null` — «у пары настроек нет».
     * `null` — не «не знаем», а явное утверждение: `GET /formats` сериализует
     * его как `settingsProfile: null`, а `POST /convert` отвергает любые опции.
     */
    public function resolveProfileId(string $from, string $to, string $category, bool $ocr = false, bool $animated = false): ?string
    {
        $this->load();

        $from = strtolower($from);
        $to   = strtolower($to);

        foreach ((array) $this->assignments as $rule) {
            if ($rule['category'] !== null && $rule['category'] !== $category) {
                continue;
            }
            if ($rule['ocr'] !== null && $rule['ocr'] !== $ocr) {
                continue;
            }
            if ($rule['animated'] !== null && $rule['animated'] !== $animated) {
                continue;
            }
            if ($rule['from'] !== null && ! in_array($from, $rule['from'], true)) {
                continue;
            }
            if ($rule['to'] !== null && ! in_array($to, $rule['to'], true)) {
                continue;
            }

            return $rule['profile'];
        }

        return null;
    }

    public function resolveProfile(string $from, string $to, string $category, bool $ocr = false, bool $animated = false): ?SettingsProfile
    {
        $id = $this->resolveProfileId($from, $to, $category, $ocr, $animated);

        return $id === null ? null : $this->getProfile($id);
    }

    // -----------------------------------------------------------------------

    private function load(): void
    {
        if ($this->profiles !== null) {
            return;
        }

        $path = $this->catalogPath ?? self::defaultCatalogPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException("Conversion settings catalog is missing or unreadable: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Conversion settings catalog could not be read: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Conversion settings catalog is not valid JSON: {$path}", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException("Conversion settings catalog must be a JSON object: {$path}");
        }

        // `$comment` — единственный игнорируемый ключ: инструкция «как добавить
        // свой профиль» должна жить в самом файле, который открывает автор
        // доменной карточки, а JSON не поддерживает комментарии.
        $unknown = array_diff(array_keys($decoded), ['$comment', 'version', 'profiles', 'assignments']);
        if ($unknown !== []) {
            throw new \RuntimeException('Settings catalog: unknown top-level keys: ' . implode(', ', $unknown));
        }

        $version = $decoded['version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new \RuntimeException('Settings catalog: `version` must be a non-empty string');
        }

        $profilesRaw = $decoded['profiles'] ?? null;
        if (! is_array($profilesRaw) || $profilesRaw === []) {
            throw new \RuntimeException('Settings catalog: `profiles` must be a non-empty object');
        }

        $profiles = [];
        foreach ($profilesRaw as $id => $profileRaw) {
            if (! is_string($id) || ! is_array($profileRaw)) {
                throw new \RuntimeException('Settings catalog: every profile must be keyed by a string id and be an object');
            }
            /** @var array<string, mixed> $profileRaw */
            $profiles[$id] = SettingsProfile::fromArray($id, $profileRaw);
        }

        $this->version     = $version;
        $this->profiles    = $profiles;
        $this->assignments = self::parseAssignments($decoded['assignments'] ?? null, $profiles);
    }

    /**
     * @param array<string, SettingsProfile> $profiles
     *
     * @return list<array{profile: string, category: ?string, from: ?list<string>, to: ?list<string>, ocr: ?bool, animated: ?bool}>
     */
    private static function parseAssignments(mixed $raw, array $profiles): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new \RuntimeException('Settings catalog: `assignments` must be a non-empty list');
        }

        $parsed = [];
        foreach ($raw as $index => $rule) {
            if (! is_array($rule)) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} must be an object");
            }

            $unknown = array_diff(array_keys($rule), ['profile', 'category', 'from', 'to', 'ocr', 'animated']);
            if ($unknown !== []) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} — unknown keys: " . implode(', ', $unknown));
            }

            $profileId = $rule['profile'] ?? null;
            if (! is_string($profileId) || ! isset($profiles[$profileId])) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} references an unknown profile");
            }

            $category = $rule['category'] ?? null;
            if ($category !== null && (! is_string($category) || $category === '')) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} — `category` must be a non-empty string");
            }

            $ocr = $rule['ocr'] ?? null;
            if ($ocr !== null && ! is_bool($ocr)) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} — `ocr` must be a boolean");
            }

            // CNV-106: request-scoped дискриминатор той же формы, что и `ocr` —
            // см. class docblock.
            $animated = $rule['animated'] ?? null;
            if ($animated !== null && ! is_bool($animated)) {
                throw new \RuntimeException("Settings catalog: assignment #{$index} — `animated` must be a boolean");
            }

            $parsed[] = [
                'profile'  => $profileId,
                'category' => $category,
                'from'     => self::parseFormatList($rule['from'] ?? null, $index, 'from'),
                'to'       => self::parseFormatList($rule['to'] ?? null, $index, 'to'),
                'ocr'      => $ocr,
                'animated' => $animated,
            ];
        }

        return $parsed;
    }

    /** @return list<string>|null */
    private static function parseFormatList(mixed $raw, int|string $index, string $key): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw) || $raw === []) {
            throw new \RuntimeException("Settings catalog: assignment #{$index} — `{$key}` must be a non-empty list of formats");
        }

        $list = [];
        foreach ($raw as $format) {
            if (! is_string($format) || $format === '') {
                throw new \RuntimeException("Settings catalog: assignment #{$index} — `{$key}` must contain non-empty strings");
            }
            $list[] = strtolower($format);
        }

        return $list;
    }

    private static function defaultCatalogPath(): string
    {
        // src/Service/Conversion/Settings → корень app-symfony (4 уровня вверх).
        return dirname(__DIR__, 4) . self::DEFAULT_CATALOG_RELATIVE_PATH;
    }
}
