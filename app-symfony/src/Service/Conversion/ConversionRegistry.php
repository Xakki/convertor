<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\WorkerCapability;
use App\Enum\FileCategory;
use App\Repository\WorkerCapabilityRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Capability-driven conversion routing.
 *
 * Источник матрицы (приоритет):
 *   1. БД — таблица worker_capabilities, построенная из register-запросов воркеров.
 *   2. Hardcoded fallback {@see workerCapabilities()} — когда БД пуста или недоступна
 *      (Phase 1: пока воркеры не успели зарегистрироваться / при DB-ошибке).
 *
 * Матрица кешируется в Symfony cache.app (filesystem) и сбрасывается при каждом
 * вызове {@see invalidateMatrix()} (вызывается из register-эндпоинта).
 *
 * AI-воркеры — только запасной вариант: пара назначается AI только если ни один
 * non-AI воркер её не занял. AI-пары объявляются плоско (mp3→txt, txt→mp3 и т.д.)
 * через блок 'ai' в {@see workerCapabilities()}; FileCategory берётся из matrix_categories.
 *
 * Сигнатуры {@see getSupportedFormats()}, {@see isSupported()}, {@see streamFor()} не меняются.
 */
class ConversionRegistry
{
    private const CACHE_KEY = 'conv.worker.matrix';

    /**
     * Explicit OCR capability set: {jpg,png,tiff,pdf} × {txt,md,docx}. Owned by
     * the image worker, isAi=false. Used BOTH to seed the direct raster matrix
     * entries (jpg/png/tiff) AND to resolve/validate the pdf case under the OCR
     * flag (pdf OCR is flag-only — never a plain matrix entry, so pdf→txt without
     * the flag stays document text-extraction).
     */
    private const OCR_SOURCES = ['jpg', 'png', 'tiff', 'pdf'];
    private const OCR_TARGETS = ['txt', 'md', 'docx'];
    private const OCR_RASTER  = ['jpg', 'png', 'tiff'];

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
     * (ConversionRegistryFallbackTest) fails if a future `FileCategory` case
     * is added here unranked.
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
     * (home-02-text-input) — Документы(txt) + Разметка(md/rst/latex/html/wiki)
     * + Данные(csv/json/xml/yaml/toml), per ROADMAP.md «Матрица поддерживаемых
     * конвертаций». A FIXED format list, not a category lookup: the DB-backed
     * matrix ({@see buildMatrixFromCapabilities()}) collapses markup/data pairs
     * into whatever `FileCategory` the registering worker declared (in
     * production today that is `document` for md/html/rst — no dedicated
     * `markup` category is actually registered), so branching on
     * {@see getCategory()} would silently reject legitimate textual sources.
     * Binary Document members (docx/odt/rtf/epub/pages/pdf) are deliberately
     * NOT in this list.
     */
    private const TEXTUAL_SOURCE_FORMATS = [
        'txt', 'md', 'rst', 'latex', 'wiki', 'html', 'csv', 'json', 'xml', 'yaml', 'toml',
    ];

    /**
     * Lazy per-request cache (строится однократно за запрос).
     *
     * @var array<string, array<string, array{category: FileCategory, isAi: bool}>>|null
     */
    private ?array $matrix = null;

    /**
     * Параметры — опциональны: unit-тесты создают `new ConversionRegistry()` без аргументов
     * и автоматически получают hardcoded fallback. В production-контейнере все три
     * инжектируются через autowiring.
     */
    public function __construct(
        private readonly ?WorkerCapabilityRepository $repository = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}>
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
     * - otherwise: AI pairs → 'ai'; `markup` folds into 'document' (no dedicated
     *   markup worker); everything else routes to its stored category.
     */
    public function streamFor(string $from, string $to, bool $ocr = false): string
    {
        if ($ocr) {
            if (! $this->isOcrSupported($from, $to)) {
                throw new \InvalidArgumentException("Unsupported OCR conversion: {$from} → {$to}");
            }

            return FileCategory::Image->value;
        }

        if ($this->isAi($from, $to)) {
            return 'ai';
        }

        $category = $this->getCategory($from, $to)->value;

        return $category === FileCategory::Markup->value ? FileCategory::Document->value : $category;
    }

    /**
     * Сбрасывает кеш матрицы (per-request + cross-request).
     * Вызывается register-эндпоинтом после upsert воркера.
     */
    public function invalidateMatrix(): void
    {
        $this->matrix = null;
        $this->cache?->delete(self::CACHE_KEY);
    }

    /**
     * Admin-visible health signal: per AI worker (`isAi=true` in the registered
     * capability blob), which `from` formats it declared in `matrix` but that
     * got silently dropped from the routing matrix because `matrix_categories`
     * has no (or an unresolvable) entry for them — see the `continue` in
     * {@see buildMatrixFromCapabilities()}. Computed on the fly directly from
     * the repository, independent of the (possibly cached) routing matrix, so
     * it reflects the current DB state even when the matrix cache is warm.
     * Only DB-backed registrations are considered (no repository / DB error /
     * hardcoded fallback → no warnings, nothing to report on).
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
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function getMatrix(): array
    {
        return $this->matrix ??= $this->buildMatrix();
    }

    /**
     * Строит матрицу: из кеша (если есть) или прямым вызовом buildRoutingPairs().
     *
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildMatrix(): array
    {
        if ($this->cache === null) {
            return $this->buildRoutingPairs();
        }

        /** @var array<string, array<string, array{category: FileCategory, isAi: bool}>> */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(3600); // 1ч — страховка; основная инвалидация через delete()

            return $this->buildRoutingPairs();
        });
    }

    /**
     * Строит routing-пары: из БД если непусто, иначе — hardcoded fallback.
     *
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildRoutingPairs(): array
    {
        if ($this->repository !== null) {
            try {
                $capabilities = $this->repository->findAllCapabilities();
                if ($capabilities !== []) {
                    return $this->buildMatrixFromCapabilities($capabilities);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('ConversionRegistry: БД недоступна, используется hardcoded fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildMatrixFromHardcode();
    }

    /**
     * Строит матрицу из зарегистрированных в БД возможностей воркеров.
     * Политика коллизий: non-AI побеждает AI (независимо от порядка — non-AI
     * всегда обрабатываются раньше по сортировке ниже); коллизия МЕЖДУ двумя
     * non-AI воркерами на одну пару разрешается через {@see NON_AI_PRECEDENCE}
     * (registry-03 tie-break, детерминированно, не зависит от порядка строк из
     * БД); при равном ранге (тот же workerType, несколько инстансов) —
     * last-write, как раньше.
     *
     * `$capabilities` — плоский список рядов, по ряду на пару (workerType,
     * instanceId) (registry-02: ключ БД составной, несколько инстансов одного
     * workerType — норма, напр. два хоста с одинаковым воркером). Цикл ниже НЕ
     * группирует по workerType — он проходит по рядам как есть и накапливает
     * пары в общий `$matrix`, поэтому несколько рядов одного workerType уже
     * объединяются (union) построчно; повторяющиеся пары дедуплицируются самой
     * структурой ассоциативного массива по правилу ранга выше.
     *
     * @param WorkerCapability[] $capabilities
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildMatrixFromCapabilities(array $capabilities): array
    {
        $matrix = [];
        // Ранг non-AI победителя текущей пары (registry-03 tie-break, см.
        // NON_AI_PRECEDENCE) — параллельно $matrix, не часть возвращаемой формы.
        $nonAiRank = [];

        // Сортировка: non-AI обрабатываются раньше, AI — только для незанятых пар
        usort($capabilities, static fn (WorkerCapability $a, WorkerCapability $b): int
            => (int) ($a->getCapabilities()['isAi'] ?? false)
            <=> (int) ($b->getCapabilities()['isAi'] ?? false));

        foreach ($capabilities as $cap) {
            $blob   = $cap->getCapabilities();
            $stream = $cap->getWorkerType();
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
                        $matrix[$from][$to] = ['category' => $category, 'isAi' => true];
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
     * Строит матрицу из hardcoded workerCapabilities() (fallback).
     * AI-пары несут FileCategory в 3-м элементе каждой группы;
     * non-AI используют categoryForStream().
     *
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildMatrixFromHardcode(): array
    {
        $matrix = [];

        foreach ($this->workerCapabilities() as $stream => $worker) {
            $isAi = $worker['isAi'];

            if ($isAi) {
                foreach ($worker['pairs'] as $pair) {
                    $fromList = $pair[0];
                    $toList   = $pair[1];
                    $category = $pair[2] ?? throw new \LogicException("AI pair group must carry FileCategory at index 2");

                    foreach ($fromList as $from) {
                        foreach ($toList as $to) {
                            if ($from === $to) {
                                continue;
                            }
                            // AI — последний резерв: non-AI пара не вытесняется
                            if (isset($matrix[$from][$to]) && ! $matrix[$from][$to]['isAi']) {
                                continue;
                            }
                            $matrix[$from][$to] = ['category' => $category, 'isAi' => true];
                        }
                    }
                }
            } else {
                $category = $this->categoryForStream($stream);

                foreach ($worker['pairs'] as [$fromList, $toList]) {
                    foreach ($fromList as $from) {
                        foreach ($toList as $to) {
                            if ($from === $to) {
                                continue;
                            }
                            $matrix[$from][$to] = ['category' => $category, 'isAi' => false];
                        }
                    }
                }
            }
        }

        return $matrix;
    }

    /**
     * Per-worker capability config — the single source of truth (fallback, Phase 2 удалит).
     *
     * Each entry: stream suffix => {isAi, pairs}. `pairs` is a list of
     * [fromList, toList] blocks; a block expands to every from×to combination
     * (self-pairs from===to are skipped). Order matters: later workers in this
     * list win on collisions (last-write precedence), which reproduces the
     * historical override behaviour (e.g. markup overriding document for
     * html→pdf/docx). AI workers are listed last so they are only chosen for
     * pairs no non-AI worker already claims.
     *
     * AI-блок (последний) несёт FileCategory в 3-м элементе каждой pair-группы
     * (categoryForStream('ai') бросает ValueError — для AI не используется).
     *
     * @return array<string, array{isAi: bool, pairs: list<array{0: list<string>, 1: list<string>, 2?: FileCategory}>}>
     */
    private function workerCapabilities(): array
    {
        return [
            // Document worker (LibreOffice / Pandoc / pdf text-extraction): the
            // catch-all for office, pdf, spreadsheets, presentations, CAD.
            'document' => [
                'isAi'  => false,
                'pairs' => [
                    // office documents
                    [['doc', 'docx', 'odt', 'rtf', 'txt', 'html', 'epub', 'pages'],
                        ['docx', 'odt', 'pdf', 'txt', 'html', 'md', 'rtf', 'epub']],
                    // pdf → other (incl. pdf→txt/md text extraction — NOT OCR)
                    [['pdf'], ['docx', 'txt', 'md', 'jpg']],
                    // CAD/DWG
                    [['dwg', 'dxf'], ['pdf', 'svg', 'png']],
                    // spreadsheets
                    [['xls', 'xlsx', 'ods', 'csv'], ['xlsx', 'ods', 'csv', 'pdf']],
                    // presentations
                    [['ppt', 'pptx', 'odp'], ['pptx', 'odp', 'pdf']],
                ],
            ],

            // Markup worker (Pandoc) — folded into the document stream at routing
            // time, but stored with its own category. Listed after document so it
            // overrides shared pairs (e.g. html→pdf, html→docx).
            'markup' => [
                'isAi'  => false,
                'pairs' => [
                    [['md', 'rst', 'latex', 'html', 'wiki'], ['md', 'rst', 'html', 'pdf', 'docx']],
                    // md-only office targets (LibreOffice handles md→odt/rtf/txt/epub)
                    [['md'], ['odt', 'rtf', 'txt', 'epub']],
                ],
            ],

            // Data worker (structured data)
            'data' => [
                'isAi'  => false,
                'pairs' => [
                    [['csv', 'json', 'xml', 'yaml', 'toml'], ['csv', 'json', 'xml', 'yaml', 'toml']],
                ],
            ],

            // Image worker (Pillow). svg/heic/avif need extra libs not yet in the
            // worker image, so they are excluded. Also owns direct raster OCR
            // (jpg/png/tiff → txt/md/docx, isAi=false).
            'image' => [
                'isAi'  => false,
                'pairs' => [
                    [['jpg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'ico'],
                        ['jpg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'ico', 'pdf']],
                    // OCR raster (image worker decides OCR by text targetFormat)
                    [self::OCR_RASTER, self::OCR_TARGETS],
                ],
            ],

            // Audio worker (ffmpeg)
            'audio' => [
                'isAi'  => false,
                'pairs' => [
                    [['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus', 'wma'],
                        ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus']],
                    // video → audio (extract); 3gp is input-only, never a target
                    [['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv', '3gp'],
                        ['mp3', 'wav', 'ogg', 'flac']],
                ],
            ],

            // Video worker (ffmpeg); 3gp is input-only, never a target
            'video' => [
                'isAi'  => false,
                'pairs' => [
                    [['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv', '3gp'],
                        ['mp4', 'avi', 'mkv', 'mov', 'webm']],
                ],
            ],

            // AI worker (Whisper STT / TTS / embedding). Объявлен последним: AI —
            // последний резерв, non-AI пары не вытесняются. Каждая группа несёт
            // FileCategory в 3-м элементе (categoryForStream('ai') невалиден).
            'ai' => [
                'isAi'  => true,
                'pairs' => [
                    // STT: audio → text (включая flac)
                    [['mp3', 'wav', 'ogg', 'm4a', 'opus', 'flac'], ['txt', 'srt', 'vtt'], FileCategory::Audio],
                    // TTS: text → audio
                    [['txt', 'md'], ['mp3', 'wav', 'ogg'], FileCategory::Document],
                    // Embedding: txt → json
                    [['txt'], ['json'], FileCategory::Document],
                ],
            ],
        ];
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
     * Single source of truth shared by {@see buildMatrixFromCapabilities()} (drops
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
