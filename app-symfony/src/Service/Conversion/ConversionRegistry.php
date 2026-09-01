<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Enum\FileCategory;
use App\Enum\WorkerType;
use App\Repository\WorkerCapabilityRepository;
use Psr\Log\LoggerInterface;

/**
 * Capability-driven conversion routing.
 *
 * CNV-71-02: единственный источник МАТРИЦЫ РОУТИНГА — коммиченный статический
 * каталог `config/catalog/conversion_pairs.json` ({@see loadCatalogMatrix()}),
 * НЕ таблица `worker_capabilities`. Каталог — резолвленный список пар
 * `{from, to, category, isAi}`, сгенерированный из зарегистрированных
 * capability-блобов воркеров той же самой редукцией, что применяется к
 * live-блобам ({@see reduceCapabilities()}, через `getSupportedFormatsFromBlobs()`
 * и `App\Command\GenerateConversionPairsCommand`) — так что политика
 * (non-AI побеждает AI, {@see NON_AI_PRECEDENCE} tie-break, резолв AI-категории)
 * определена ОДИН раз и общая для генерации каталога и (раньше) для DB-backed
 * матрицы. `worker_capabilities`/{@see WorkerCapabilityRepository} остаётся
 * источником ТОЛЬКО для live-диагностики воркеров — {@see getCapabilityWarnings()}
 * и `WorkerStatsProvider`/`Admin\WorkerController`/`WorkerCapabilityGcService`/
 * `WorkerLivenessReconciler` — и НИКОГДА не читается для построения роутинг-матрицы.
 *
 * Отсутствующий, невалидный (не-JSON, не-массив, ряд без from/to/category/isAi)
 * ИЛИ ПУСТОЙ (`[]`) файл каталога — ГРОМКАЯ ошибка (`\RuntimeException` из
 * {@see loadCatalogMatrix()}), не тихий откат к пустой матрице: пустая матрица
 * здесь означала бы, что весь сайт разом теряет все форматы и все конвертации
 * 400-ят. Легитимного случая коммиченного пустого каталога не существует —
 * синтаксически валидный, но пустой `[]` трактуется так же громко, как и
 * повреждённый файл.
 *
 * Матрица держится ТОЛЬКО в per-request memo (`$this->matrix`, строится один раз
 * за запрос при первом обращении) — межзапросного кеша (`cache.app`) больше нет:
 * каталог статичен (коммиченный файл, меняется только релизом кода), инвалидация
 * по регистрации/GC воркера ему не нужна. Метод `invalidateMatrix()` и её 3
 * колл-сайта (register-эндпоинт, admin `deleteStale()`,
 * `WorkerCapabilityGcService::run()`) удалены вместе с cross-request кешем.
 *
 * AI-воркеры — только запасной вариант: пара назначается AI только если ни один
 * non-AI воркер её не занял. AI-пары объявляются плоско (mp3→txt, txt→mp3 и т.д.)
 * в самом capability-blob воркера ('isAi' => true, 'matrix', 'matrix_categories');
 * FileCategory резолвится через {@see resolveAiCategory()}. Эта политика применяется
 * ТОЛЬКО во время генерации каталога (`getSupportedFormatsFromBlobs()`) — не во
 * время чтения уже резолвленного `conversion_pairs.json`.
 *
 * Сигнатуры {@see getSupportedFormats()}, {@see isSupported()}, {@see streamFor()} не меняются.
 *
 * CNV-88/CNV-27: ряд каталога МОЖЕТ нести `executionKind` — валидное значение
 * {@see \App\Enum\WorkerType}. После специальных request-scoped OCR/animated-
 * веток {@see streamFor()} проверяет этот override ДО `isAi`: `isAi` остаётся
 * quota/auth-флагом, а transport выбирается независимо. CNV-27 первым публикует
 * `txt→json` с `isAi=true` и `executionKind=api`. Отсутствующий/null override
 * сохраняет прежний isAi/category-based маршрут. Невалидное значение громко
 * отклоняется при загрузке каталога.
 *
 * CNV-106: скорректирована находка CNV-88 «animated SVG→GIF может нести
 * `executionKind: browser`» — НЕВЕРНО как факт: `conversion_pairs.json`
 * хранит ОДИН маршрут на from→to пару (ассоциативный `$matrix[$from][$to]`),
 * а статичный svg→gif УЖЕ опубликован (CNV-95, `image.raster`) на той же
 * паре — `executionKind: browser` на этом ряду увёл бы В БРАУЗЕР и статичный
 * трафик тоже, сломав CNV-95. Реальный механизм — {@see isAnimatedConversionSupported()}
 * / `$animated`-параметр {@see streamFor()}, request-scoped флаг той же
 * формы, что и `$ocr` (см. {@see ANIMATED_SOURCES} докблок и class docblock
 * `App\Service\Conversion\Settings\ConversionSettingsCatalog`).
 */
class ConversionRegistry
{
    private const string DEFAULT_CATALOG_RELATIVE_PATH = '/config/catalog/conversion_pairs.json';

    /**
     * Explicit OCR capability set: {jpg,png,tiff,pdf} × {txt,md,docx}. Owned by
     * the image worker, isAi=false. Used to resolve/validate the `ocr` flag
     * path in {@see isOcrSupported()}/{@see streamFor()} — pdf OCR is flag-only
     * (never a plain matrix entry, so pdf→txt without the flag stays document
     * text-extraction). The raster (jpg/png/tiff) OCR pairs themselves are
     * plain matrix entries declared directly in the static catalog
     * (`config/catalog/conversion_pairs.json`, CNV-71-02) — no separate
     * constant needed here for that half since registry-05 removed the
     * hardcoded fallback that used to seed them (`OCR_RASTER`, now deleted as
     * dead/unused).
     */
    private const OCR_SOURCES = ['jpg', 'png', 'tiff', 'pdf'];
    private const OCR_TARGETS = ['txt', 'md', 'docx'];

    /**
     * CNV-106: explicit animated-conversion capability set — mirrors
     * {@see OCR_SOURCES}/{@see OCR_TARGETS} EXACTLY in shape: a flat
     * hardcoded allowlist, checked ONLY when the caller explicitly requests
     * `$animated` in {@see streamFor()}. Deliberately independent of the
     * catalog's per-row `executionKind` (CNV-88) — that field is per-PAIR and
     * unconditional, so putting `executionKind: browser` on the svg→gif row
     * would reroute the ALREADY-PUBLISHED static svg→gif pair (CNV-95,
     * `image.raster`) too, since `conversion_pairs.json` can only carry ONE
     * route per from→to pair (see {@see resolveAiCategory()}-style single-
     * winner reduction). A request-scoped flag is the only mechanism that can
     * express "same pair, two different behaviours" — exactly the `ocr` flag
     * already does for the OCR-vs-plain-extraction split on pdf→txt/md/docx.
     *
     * NOT wired to any live HTTP request today: `ConversionController` never
     * reads an `animated` request field, so no real caller ever passes
     * `$animated = true` to {@see streamFor()} — the branch below is fully
     * implemented and unit-tested, but structurally unreachable in
     * production until a future card wires the request field (mirroring how
     * `ocr` is wired) AND a browser worker actually exists to consume
     * `conv.browser` (see `App\Enum\WorkerType::Browser`, CNV-88).
     */
    private const ANIMATED_SOURCES = ['svg'];
    private const ANIMATED_TARGETS = ['gif'];

    /**
     * INTERIM (Phase 2) tie-break for a from→to pair legitimately declared by
     * TWO non-AI worker types (registry-03 review: pdf→docx/md/txt is claimed
     * by both `document`, plain poppler/pandoc text extraction, and `image`,
     * whose OCR branch also accepts a pdf source — the `ocr` flag picks the
     * worker/stream, so both are honest to declare it). Index = priority rank,
     * lower wins. Applied ONLY between two non-AI candidates for the SAME pair
     * (the existing non-AI-beats-AI rule is unrelated and stays as-is); result
     * is independent of DB row/insertion order — see {@see nonAiPrecedenceRank()}.
     * Superseded by the epic's Phase 3 multi-candidate router (pdf→txt
     * document-extract vs image-OCR is its named reference case).
     *
     * Covers ALL 7 `FileCategory` cases, not just today's 5 registered worker
     * types — `categoryForStream()` is `FileCategory::from($stream)`, which
     * accepts `archive` and `markup` too without throwing (registry-02/03
     * review: two unlisted types colliding would both fall through to
     * {@see nonAiPrecedenceRank()}'s `PHP_INT_MAX` default and reproduce the
     * exact order-dependent bug this constant exists to fix, just outside the
     * 5 types visible today). `testNonAiPrecedenceCoversEveryFileCategoryCase`
     * (ConversionRegistryReduceCapabilitiesTest) fails if a future `FileCategory`
     * case is added here unranked.
     *   - `document` highest: primary text-extraction path (the fix's own case).
     *   - `markup` right after `document`: no live workerType='markup' registrant
     *     exists (folded into `document` only at routing time by streamFor()),
     *     so this rank is purely defensive — kept adjacent to its routing target
     *     to minimise surprise if it were ever collided against.
     *   - `data`, `audio`, `video` next: distinct format families, no known
     *     current overlap with anything else.
     *   - `image` before last: the losing side of the fix's own pdf case.
     *   - `archive` lowest: no worker implements it yet (grepped `workers/` —
     *     no archive worker directory), least-established category.
     */
    private const NON_AI_PRECEDENCE = ['document', 'markup', 'data', 'audio', 'video', 'image', 'archive'];

    /**
     * Explicit textual-source allowlist for {@see isTextSourceSupported()}
     * (home-02-text-input) — текстовые source-форматы из live-матрицы: документы
     * (txt/md/html) + данные (csv/json/xml/yaml/toml). A FIXED format list, not
     * a category lookup: the DB-backed matrix registers these under various
     * `FileCategory` values (md/html под `document`), so branching on
     * {@see getCategory()} would silently reject legitimate textual sources.
     * Binary Document members (docx/odt/rtf/epub/pages/pdf) are deliberately
     * NOT in this list.
     */
    private const TEXTUAL_SOURCE_FORMATS = [
        'txt', 'md', 'html', 'csv', 'json', 'xml', 'yaml', 'toml',
    ];

    /**
     * Lazy per-request cache (строится однократно за запрос).
     *
     * @var array<string, array<string, array{category: FileCategory, isAi: bool, executionKind: ?string}>>|null
     */
    private ?array $matrix = null;

    /**
     * `$repository` — опционален для конструктора, но `null` НЕ является
     * production-путём для роутинг-матрицы (та больше не читает репозиторий
     * вообще): autowiring в контейнере всегда инжектирует реальный репозиторий,
     * а `null` остаётся только ради unit-тестов {@see getCapabilityWarnings()}
     * без БД (тот метод — единственное, что ещё читает `$repository`).
     *
     * `$catalogPath` — путь к резолвленному каталогу пар (см. класс-докблок).
     * DI-биндинг — `config/services.yaml` (`%kernel.project_dir%/config/catalog/
     * conversion_pairs.json`); `null` (напр. `new ConversionRegistry()` без DI
     * в unit-тестах) резолвится в {@see defaultCatalogPath()} — ТОТ ЖЕ реальный
     * коммиченный файл, так что тесты без явного `$catalogPath` видят полный
     * production-каталог. Тесты, которым нужна конкретная маленькая матрица,
     * передают путь к своему JSON-фикстур-файлу той же формы (`{from, to,
     * category, isAi}`).
     */
    public function __construct(
        private readonly ?WorkerCapabilityRepository $repository = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $catalogPath = null,
    ) {
    }

    /**
     * @return list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool, executionKind?: string}>
     */
    public function getSupportedFormats(): array
    {
        $result = [];
        foreach ($this->getMatrix() as $from => $targets) {
            foreach ($targets as $to => $meta) {
                $result[] = [
                    'from'       => $from,
                    'to'         => $to,
                    'category'   => $meta['category']->value,
                    'isAi'       => $meta['isAi'],
                    'ocrCapable' => $this->isOcrSupported($from, $to),
                ];
            }
        }

        return $result;
    }

    public function isSupported(string $from, string $to): bool
    {
        return isset($this->getMatrix()[$from][$to]);
    }

    /**
     * Same shape/policy as {@see getSupportedFormats()}, but the matrix is built
     * from an INJECTED list of capability blobs instead of {@see getMatrix()}
     * (the catalog file) — no repository, no container, no DB, no filesystem
     * required. Runs the exact same reduction ({@see reduceCapabilities()}) that
     * (until CNV-71-02) also fed the live routing matrix from the DB, so
     * category/isAi/precedence policy has exactly ONE implementation (CNV-71-01:
     * the static `config/catalog/conversion_pairs.json` generator is the caller —
     * see `App\Command\GenerateConversionPairsCommand`).
     *
     * `ocrCapable` is computed the same way as {@see getSupportedFormats()}
     * ({@see isOcrSupported()} is a pure constant lookup, independent of matrix
     * source) — no divergence between the DB-backed and blob-backed outputs.
     *
     * @param list<array<string, mixed>> $blobs raw register-payload blobs — same
     *   shape as the `capabilities` column / `config/catalog/worker_capabilities.json`
     *   entries, each carrying its own `workerType` key.
     * @return list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool, executionKind?: string}>
     */
    public function getSupportedFormatsFromBlobs(array $blobs): array
    {
        $entries = array_map(
            static fn (array $blob): array => [
                'workerType' => (string) ($blob['workerType'] ?? ''),
                'blob'       => $blob,
            ],
            $blobs,
        );

        $result = [];
        foreach ($this->reduceCapabilities($entries) as $from => $targets) {
            foreach ($targets as $to => $meta) {
                $row = [
                    'from'       => $from,
                    'to'         => $to,
                    'category'   => $meta['category']->value,
                    'isAi'       => $meta['isAi'],
                    'ocrCapable' => $this->isOcrSupported($from, $to),
                ];
                if (($meta['executionKind'] ?? null) !== null) {
                    $row['executionKind'] = $meta['executionKind'];
                }
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Shortest conversion path over the routing matrix (BFS, depth-capped).
     *
     * Uses the same matrix as {@see isSupported()} / {@see getMatrix()} — never
     * invents OCR-only edges (matrix membership only). Format keys containing
     * `_` (virtual STT/TTS) are excluded as graph nodes. Same-format
     * (`$from === $to`) returns null. A direct edge is a valid length-1 path;
     * Manager should still prefer {@see isSupported()} before chaining.
     * Among equal-length paths, prefers fewest AI hops (non-AI first).
     * Formats are NOT case-normalized here — same style as {@see isSupported()}.
     *
     * @return list<array{from: string, to: string, category: FileCategory, isAi: bool}>|null
     */
    public function findPath(string $from, string $to, int $maxDepth = 2): ?array
    {
        if ($from === $to || $maxDepth < 1) {
            return null;
        }
        if (str_contains($from, '_') || str_contains($to, '_')) {
            return null;
        }

        $matrix = $this->getMatrix();
        if (! isset($matrix[$from])) {
            return null;
        }

        /** @var list<array{from: string, to: string, category: FileCategory, isAi: bool}>|null $bestPath */
        $bestPath   = null;
        $bestDepth  = PHP_INT_MAX;
        $bestAiHops = PHP_INT_MAX;

        /** @var \SplQueue<array{0: string, 1: list<array{from: string, to: string, category: FileCategory, isAi: bool}>, 2: int}> $queue */
        $queue = new \SplQueue();
        $queue->enqueue([$from, [], 0]);

        /** @var array<string, array{0: int, 1: int}> $bestReached depth + aiHops per node */
        $bestReached = [$from => [0, 0]];

        while (! $queue->isEmpty()) {
            /** @var array{0: string, 1: list<array{from: string, to: string, category: FileCategory, isAi: bool}>, 2: int} $frame */
            $frame                  = $queue->dequeue();
            [$node, $path, $aiHops] = $frame;
            $depth                  = count($path);

            if ($depth >= $maxDepth || $depth >= $bestDepth) {
                continue;
            }

            foreach ($matrix[$node] ?? [] as $next => $meta) {
                if (str_contains((string) $next, '_')) {
                    continue;
                }

                $edge = [
                    'from'     => $node,
                    'to'       => $next,
                    'category' => $meta['category'],
                    'isAi'     => $meta['isAi'],
                ];
                $newPath  = [...$path, $edge];
                $newDepth = $depth  + 1;
                $newAi    = $aiHops + ($meta['isAi'] ? 1 : 0);

                if ($next === $to) {
                    if ($newDepth < $bestDepth || ($newDepth === $bestDepth && $newAi < $bestAiHops)) {
                        $bestDepth  = $newDepth;
                        $bestAiHops = $newAi;
                        $bestPath   = $newPath;
                    }
                    continue;
                }

                if ($newDepth >= $bestDepth) {
                    continue;
                }

                $prev = $bestReached[$next] ?? null;
                if ($prev !== null) {
                    [$prevDepth, $prevAi] = $prev;
                    if ($newDepth > $prevDepth || ($newDepth === $prevDepth && $newAi >= $prevAi)) {
                        continue;
                    }
                }

                $bestReached[$next] = [$newDepth, $newAi];
                $queue->enqueue([$next, $newPath, $newAi]);
            }
        }

        return $bestPath;
    }

    public function getCategory(string $from, string $to): FileCategory
    {
        return $this->getMatrix()[$from][$to]['category']
            ?? throw new \InvalidArgumentException("Unsupported conversion: {$from} → {$to}");
    }

    public function isAi(string $from, string $to): bool
    {
        return $this->getMatrix()[$from][$to]['isAi']
            ?? throw new \InvalidArgumentException("Unsupported conversion: {$from} → {$to}");
    }

    /**
     * Is the pair part of the explicit OCR capability set?
     * (Used by the OCR flag path; covers the pdf raster case which is NOT a
     * plain matrix entry.)
     */
    public function isOcrSupported(string $from, string $to): bool
    {
        return in_array($from, self::OCR_SOURCES, true)
            && in_array($to, self::OCR_TARGETS, true);
    }

    /**
     * Is the pair part of the explicit animated-conversion capability set
     * (CNV-106)? Used by the `$animated` flag path in {@see streamFor()} —
     * see {@see ANIMATED_SOURCES}/{@see ANIMATED_TARGETS} docblock for why
     * this is a hardcoded allowlist rather than a catalog lookup.
     */
    public function isAnimatedConversionSupported(string $from, string $to): bool
    {
        return in_array($from, self::ANIMATED_SOURCES, true)
            && in_array($to, self::ANIMATED_TARGETS, true);
    }

    /**
     * Text-mode source gate (home-02-text-input): is `$from` BOTH a genuinely
     * textual format ({@see TEXTUAL_SOURCE_FORMATS}) AND a valid `isSupported()`
     * pair with `$to`? Pasted text has no MIME-sniff safety net (unlike an
     * uploaded file), so binary sources (docx/pdf/images/audio/video) are
     * rejected here even though some of them ARE valid `isSupported()` pairs
     * for the file-upload path. No separate from→to compatibility table — the
     * pair check still delegates to {@see isSupported()}; only the source
     * FORMAT allowlist is new.
     */
    public function isTextSourceSupported(string $from, string $to): bool
    {
        return in_array($from, self::TEXTUAL_SOURCE_FORMATS, true) && $this->isSupported($from, $to);
    }

    /**
     * Pure routing function: returns the stream suffix (the part after `conv_`)
     * for a conversion pair.
     *
     * - OCR ($ocr=true): validated image-worker job → 'image', never AI.
     * - animated ($animated=true, CNV-106): validated browser-worker job →
     *   'browser' ({@see WorkerType::Browser}), via the hardcoded allowlist
     *   {@see isAnimatedConversionSupported()} — NOT via the catalog's
     *   per-pair `executionKind` (see {@see ANIMATED_SOURCES} docblock for
     *   why). Checked before the `executionKind`/`isAi()` branches below,
     *   same precedence position as `$ocr` — both are explicit
     *   execution-mode overrides requested by the caller. NOT reachable from
     *   any live request today (see {@see ANIMATED_SOURCES} docblock).
     * - otherwise: AI pairs → 'ai'; a pair carrying an explicit `executionKind`
     *   (CNV-88, e.g. `browser`) routes there REGARDLESS of its stored
     *   category — see class docblock; `markup` folds into 'document' (no
     *   dedicated markup worker); everything else routes to its stored
     *   category.
     */
    public function streamFor(string $from, string $to, bool $ocr = false, bool $animated = false): string
    {
        if ($ocr) {
            if (! $this->isOcrSupported($from, $to)) {
                throw new \InvalidArgumentException("Unsupported OCR conversion: {$from} → {$to}");
            }

            return FileCategory::Image->value;
        }

        if ($animated) {
            if (! $this->isAnimatedConversionSupported($from, $to)) {
                throw new \InvalidArgumentException("Unsupported animated conversion: {$from} → {$to}");
            }

            return WorkerType::Browser->value;
        }

        $executionKind = $this->getMatrix()[$from][$to]['executionKind'] ?? null;
        if ($executionKind !== null) {
            return $executionKind;
        }

        if ($this->isAi($from, $to)) {
            return 'ai';
        }

        $category = $this->getCategory($from, $to)->value;

        return $category === FileCategory::Markup->value ? FileCategory::Document->value : $category;
    }

    /**
     * Admin-visible health signal: per AI worker (`isAi=true` in the registered
     * capability blob), which `from` formats it declared in `matrix` but would
     * get silently dropped by {@see reduceCapabilities()} (the same reduction
     * catalog generation runs) because `matrix_categories` has no (or an
     * unresolvable) entry for them — see the `continue` in that method's AI
     * branch. Computed on the fly directly from the repository, entirely
     * independent of the routing matrix (CNV-71-02: that matrix no longer reads
     * the repository at all) — this is a live DB diagnostic, not a routing
     * input. Only DB-backed registrations are considered (no repository / DB
     * error → no warnings, nothing to report on).
     *
     * @return list<array{workerType: string, droppedFormats: list<string>, droppedCount: int, totalFormats: int}>
     */
    public function getCapabilityWarnings(): array
    {
        if ($this->repository === null) {
            return [];
        }

        try {
            $capabilities = $this->repository->findAllCapabilities();
        } catch (\Throwable) {
            return [];
        }

        $warnings = [];
        foreach ($capabilities as $cap) {
            $blob = $cap->getCapabilities();
            if (! ($blob['isAi'] ?? false)) {
                continue;
            }

            /** @var array<string, list<string>> $rawMatrix */
            $rawMatrix = $blob['matrix'] ?? [];
            /** @var array<string, string> $matrixCategories */
            $matrixCategories = $blob['matrix_categories'] ?? [];

            $dropped = [];
            foreach (array_keys($rawMatrix) as $from) {
                if (self::resolveAiCategory($matrixCategories[$from] ?? '') === null) {
                    $dropped[] = $from;
                }
            }

            if ($dropped !== []) {
                $warnings[] = [
                    'workerType'     => $cap->getWorkerType(),
                    'droppedFormats' => $dropped,
                    'droppedCount'   => count($dropped),
                    'totalFormats'   => count($rawMatrix),
                ];
            }
        }

        return $warnings;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, array{category: FileCategory, isAi: bool, executionKind: ?string}>>
     */
    private function getMatrix(): array
    {
        return $this->matrix ??= $this->buildMatrix();
    }

    /**
     * Строит матрицу из статического каталога (per-request memo — {@see getMatrix()}
     * вызывает этот метод максимум раз за запрос). Ловит ЛЮБУЮ ошибку загрузки
     * (см. {@see loadCatalogMatrix()}) и перевыбрасывает с контекстом пути файла,
     * не глотает молча.
     *
     * @return array<string, array<string, array{category: FileCategory, isAi: bool, executionKind: ?string}>>
     */
    private function buildMatrix(): array
    {
        return $this->loadCatalogMatrix($this->catalogPath ?? self::defaultCatalogPath());
    }

    /**
     * Путь к коммиченному production-каталогу — вычисляется от расположения
     * ЭТОГО файла (`src/Service/Conversion/`), три уровня вверх = корень
     * `app-symfony/`. Используется, когда `$catalogPath` не передан в
     * конструктор (DI обычно передаёт тот же путь явно — см.
     * `config/services.yaml` — но unit-тесты, создающие `new ConversionRegistry()`
     * напрямую без контейнера, должны видеть тот же реальный каталог).
     */
    private static function defaultCatalogPath(): string
    {
        return \dirname(__DIR__, 3) . self::DEFAULT_CATALOG_RELATIVE_PATH;
    }

    /**
     * Читает и парсит резолвленный каталог пар (`{from, to, category, isAi}`,
     * см. `App\Command\GenerateConversionPairsCommand`) в форму роутинг-матрицы.
     *
     * ГРОМКО падает (`\RuntimeException`) на: отсутствующий файл, ошибку чтения,
     * невалидный JSON, JSON-корень не массив, ряд без обязательных ключей, а
     * также на синтаксически валидный, но ПУСТОЙ массив `[]` (см. класс-докблок:
     * пустая матрица означала бы полную потерю форматов сайтом — легитимного
     * случая коммиченного пустого каталога не существует). Опциональное поле
     * ряда `executionKind` (CNV-88, см. класс-докблок) — если присутствует и не
     * `null`, ДОЛЖНО быть непустой строкой и валидным значением
     * {@see WorkerType}, иначе тоже громкая `\RuntimeException`.
     *
     * @return array<string, array<string, array{category: FileCategory, isAi: bool, executionKind: ?string}>>
     */
    private function loadCatalogMatrix(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException(
                "ConversionRegistry: файл каталога не найден: {$path}. Запустите `make formats-catalog` "
                . 'и закоммитьте результат — это коммиченный, НЕ генерируемый на лету артефакт.',
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("ConversionRegistry: не удалось прочитать файл каталога: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "ConversionRegistry: невалидный JSON в файле каталога {$path}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                "ConversionRegistry: файл каталога {$path} должен содержать JSON-массив, получено: "
                . get_debug_type($decoded),
            );
        }

        $matrix = [];
        foreach ($decoded as $i => $row) {
            if (
                ! is_array($row)
                || ! isset($row['from'], $row['to'], $row['category'], $row['isAi'])
                || ! is_string($row['from']) || ! is_string($row['to']) || ! is_string($row['category'])
            ) {
                throw new \RuntimeException(
                    "ConversionRegistry: файл каталога {$path}, ряд #{$i} — ожидались строковые "
                    . 'from/to/category и булев isAi',
                );
            }

            try {
                $category = FileCategory::from($row['category']);
            } catch (\ValueError $e) {
                throw new \RuntimeException(
                    "ConversionRegistry: файл каталога {$path}, ряд #{$i} — неизвестная category "
                    . "\"{$row['category']}\": {$e->getMessage()}",
                    previous: $e,
                );
            }

            // CNV-88: необязательное поле-override маршрутизации — см. class docblock.
            $executionKindRaw = $row['executionKind'] ?? null;
            $executionKind    = null;
            if ($executionKindRaw !== null) {
                if (! is_string($executionKindRaw) || $executionKindRaw === '') {
                    throw new \RuntimeException(
                        "ConversionRegistry: файл каталога {$path}, ряд #{$i} — executionKind должен быть "
                        . 'непустой строкой или отсутствовать',
                    );
                }
                if (WorkerType::tryFrom($executionKindRaw) === null) {
                    $allowed = implode(', ', array_map(static fn (WorkerType $t): string => $t->value, WorkerType::cases()));

                    throw new \RuntimeException(
                        "ConversionRegistry: файл каталога {$path}, ряд #{$i} — неизвестный executionKind "
                        . "\"{$executionKindRaw}\" (допустимые значения: {$allowed})",
                    );
                }
                $executionKind = $executionKindRaw;
            }

            $matrix[$row['from']][$row['to']] = [
                'category'      => $category,
                'isAi'          => (bool) $row['isAi'],
                'executionKind' => $executionKind,
            ];
        }

        if ($matrix === []) {
            throw new \RuntimeException(
                "ConversionRegistry: файл каталога {$path} пуст — коммиченный каталог не должен быть "
                . 'пустым (это означало бы полную потерю всех форматов сайтом). Запустите '
                . '`make formats-catalog` и закоммитьте непустой результат.',
            );
        }

        return $matrix;
    }

    /**
     * Pure reduction (CNV-71-01 seam): the ENTIRE collision/precedence policy
     * (non-AI beats AI, {@see NON_AI_PRECEDENCE} tie-break, {@see resolveAiCategory()})
     * over a list of (workerType, blob) pairs. No entity, no repository, no DB
     * touched here — the sole remaining caller is {@see getSupportedFormatsFromBlobs()},
     * which `App\Command\GenerateConversionPairsCommand` runs over the committed
     * `worker_capabilities.json` to (re)generate `conversion_pairs.json` (the
     * catalog {@see loadCatalogMatrix()} reads at runtime). CNV-71-02 removed the
     * DB-backed twin of this reduction (`buildMatrixFromCapabilities()`, which
     * ran the SAME policy over live `WorkerCapability[]` rows) — the routing
     * matrix no longer touches the DB at all, so this method now runs ONLY at
     * catalog-generation time, never per-request.
     *
     * @param list<array{workerType: string, blob: array<string, mixed>}> $entries
     * @return array<string, array<string, array{category: FileCategory, isAi: bool, executionKind?: ?string}>>
     */
    private function reduceCapabilities(array $entries): array
    {
        $matrix = [];
        // Ранг non-AI победителя текущей пары (registry-03 tie-break, см.
        // NON_AI_PRECEDENCE) — параллельно $matrix, не часть возвращаемой формы.
        $nonAiRank = [];

        // Сортировка: non-AI обрабатываются раньше, AI — только для незанятых пар
        usort($entries, static fn (array $a, array $b): int
            => (int) ($a['blob']['isAi'] ?? false)
            <=> (int) ($b['blob']['isAi'] ?? false));

        foreach ($entries as $entry) {
            $blob   = $entry['blob'];
            $stream = $entry['workerType'];
            $isAi   = (bool) ($blob['isAi'] ?? false);

            /** @var array<string, list<string>> $rawMatrix */
            $rawMatrix = $blob['matrix'] ?? [];

            if ($isAi) {
                /** @var array<string, string> $matrixCategories */
                $matrixCategories = $blob['matrix_categories'] ?? [];
                foreach ($rawMatrix as $from => $targets) {
                    $category = self::resolveAiCategory($matrixCategories[$from] ?? '');
                    if ($category === null) {
                        $this->logger?->warning('ConversionRegistry: AI worker: нет matrix_categories для формата', [
                            'from' => $from,
                        ]);
                        continue;
                    }
                    foreach ($targets as $to) {
                        if ($from === $to) {
                            continue;
                        }
                        // AI — последний резерв: non-AI пара не вытесняется
                        if (isset($matrix[$from][$to]) && ! $matrix[$from][$to]['isAi']) {
                            continue;
                        }
                        $executionKind      = isset($blob['executionKind']) ? (string) $blob['executionKind'] : null;
                        $matrix[$from][$to] = [
                            'category'      => $category,
                            'isAi'          => true,
                            'executionKind' => $executionKind,
                        ];
                    }
                }
            } else {
                try {
                    $category = $this->categoryForStream($stream);
                } catch (\ValueError) {
                    $this->logger?->warning('ConversionRegistry: неизвестный workerType, пропускаем', [
                        'workerType' => $stream,
                    ]);
                    continue;
                }
                $rank = self::nonAiPrecedenceRank($stream);
                foreach ($rawMatrix as $from => $targets) {
                    foreach ($targets as $to) {
                        if ($from === $to) {
                            continue;
                        }
                        $existingRank = $nonAiRank[$from][$to] ?? null;
                        if ($existingRank !== null && $existingRank < $rank) {
                            // Пара уже занята non-AI воркером СТРОГО более высокого
                            // приоритета (registry-03 tie-break) — не перезаписываем.
                            // При равном ранге (тот же/сопоставимый workerType, напр.
                            // несколько инстансов) сохраняется прежнее last-write.
                            continue;
                        }
                        $matrix[$from][$to]    = ['category' => $category, 'isAi' => false];
                        $nonAiRank[$from][$to] = $rank;
                    }
                }
            }
        }

        return $matrix;
    }

    /**
     * Stream suffix → stored FileCategory. `markup` keeps its own stored
     * category (folded to document only at routing time); everything else maps
     * stream suffix straight onto the category enum.
     */
    private function categoryForStream(string $stream): FileCategory
    {
        return FileCategory::from($stream);
    }

    /**
     * AI `matrix_categories` value → FileCategory, or null when missing/unresolvable.
     * Single source of truth shared by {@see reduceCapabilities()} (drops
     * the pair) and {@see getCapabilityWarnings()} (reports it as dropped).
     */
    private static function resolveAiCategory(string $catStr): ?FileCategory
    {
        return match ($catStr) {
            'audio'    => FileCategory::Audio,
            'document' => FileCategory::Document,
            default    => null,
        };
    }

    /**
     * Priority rank for {@see NON_AI_PRECEDENCE} — lower wins. NON_AI_PRECEDENCE
     * lists all 7 `FileCategory` cases, so `PHP_INT_MAX` here is a defensive
     * fallback for a genuinely unrecognised/malformed workerType string only —
     * NOT an expected path for any valid registration — matching the
     * graceful-degradation style of the rest of this build path (never throws,
     * just never wins a tie).
     */
    private static function nonAiPrecedenceRank(string $workerType): int
    {
        $rank = array_search($workerType, self::NON_AI_PRECEDENCE, true);

        return $rank === false ? PHP_INT_MAX : $rank;
    }
}
