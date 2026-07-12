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
     * Политика коллизий: non-AI побеждает AI; при одинаковом isAi — last-write.
     *
     * @param WorkerCapability[] $capabilities
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildMatrixFromCapabilities(array $capabilities): array
    {
        $matrix = [];

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
                    $catStr   = $matrixCategories[$from] ?? '';
                    $category = match ($catStr) {
                        'audio'    => FileCategory::Audio,
                        'document' => FileCategory::Document,
                        default    => null,
                    };
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
                foreach ($rawMatrix as $from => $targets) {
                    foreach ($targets as $to) {
                        if ($from === $to) {
                            continue;
                        }
                        $matrix[$from][$to] = ['category' => $category, 'isAi' => false];
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
}
