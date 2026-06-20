<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Enum\FileCategory;

/**
 * Capability-driven conversion routing.
 *
 * The single source of truth is {@see workerCapabilities()}: an explicit list of
 * workers, each identified by its stream suffix (the part after `conv_`), its
 * `isAi` flag, and the `from → to` pairs it can handle. The `matrix` and the
 * pure {@see streamFor()} routing function are both derived FROM that config.
 *
 * AI workers are only ever chosen as a last resort: a pair is assigned to an AI
 * worker only when no non-AI worker already claims it.
 */
class ConversionRegistry
{
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
     * Matrix: fromFormat → [toFormat => [category, isAi]]
     *
     * @var array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private array $matrix;

    public function __construct()
    {
        $this->matrix = $this->buildMatrix();
    }

    /**
     * @return list<array{from: string, to: string, category: string, isAi: bool}>
     */
    public function getSupportedFormats(): array
    {
        $result = [];
        foreach ($this->matrix as $from => $targets) {
            foreach ($targets as $to => $meta) {
                $result[] = [
                    'from'     => $from,
                    'to'       => $to,
                    'category' => $meta['category']->value,
                    'isAi'     => $meta['isAi'],
                ];
            }
        }

        return $result;
    }

    public function isSupported(string $from, string $to): bool
    {
        return isset($this->matrix[$from][$to]);
    }

    public function getCategory(string $from, string $to): FileCategory
    {
        return $this->matrix[$from][$to]['category']
            ?? throw new \InvalidArgumentException("Unsupported conversion: {$from} → {$to}");
    }

    public function isAi(string $from, string $to): bool
    {
        return $this->matrix[$from][$to]['isAi']
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
     * Per-worker capability config — the single source of truth.
     *
     * Each entry: stream suffix => {isAi, pairs}. `pairs` is a list of
     * [fromList, toList] blocks; a block expands to every from×to combination
     * (self-pairs from===to are skipped). Order matters: later workers in this
     * list win on collisions (last-write precedence), which reproduces the
     * historical override behaviour (e.g. markup overriding document for
     * html→pdf/docx). AI workers are listed last so they are only chosen for
     * pairs no non-AI worker already claims.
     *
     * @return array<string, array{isAi: bool, pairs: list<array{0: list<string>, 1: list<string>}>}>
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
                ],
            ],

            // Data worker (structured data)
            'data' => [
                'isAi'  => false,
                'pairs' => [
                    [['csv', 'json', 'xml', 'yaml', 'toml'], ['csv', 'json', 'xml', 'yaml']],
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
                    // video → audio (extract)
                    [['mp4', 'avi', 'mkv', 'mov'], ['mp3', 'wav', 'ogg', 'flac']],
                ],
            ],

            // Video worker (ffmpeg)
            'video' => [
                'isAi'  => false,
                'pairs' => [
                    [['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv'],
                        ['mp4', 'avi', 'mkv', 'mov', 'webm']],
                ],
            ],

            // Archive worker
            'archive' => [
                'isAi'  => false,
                'pairs' => [
                    [['zip', 'tar', 'gz', 'bz2', '7z'], ['zip', 'tar.gz']],
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
     * @return array<string, array<string, array{category: FileCategory, isAi: bool}>>
     */
    private function buildMatrix(): array
    {
        $matrix = [];

        foreach ($this->workerCapabilities() as $stream => $worker) {
            $category = $this->categoryForStream($stream);
            $isAi     = $worker['isAi'];

            foreach ($worker['pairs'] as [$fromList, $toList]) {
                foreach ($fromList as $from) {
                    foreach ($toList as $to) {
                        if ($from === $to) {
                            continue;
                        }
                        // AI worker is last resort: never override a pair already
                        // claimed by a non-AI worker.
                        if ($isAi && isset($matrix[$from][$to]) && ! $matrix[$from][$to]['isAi']) {
                            continue;
                        }
                        $matrix[$from][$to] = ['category' => $category, 'isAi' => $isAi];
                    }
                }
            }
        }

        // STT / TTS — virtual AI source keys (mp3_stt, txt_tts, …). OUT OF SCOPE
        // for the capability refactor: kept exactly as-is, isAi=true, category
        // audio/document, routed to the `ai` stream.
        $sttSources = ['mp3', 'wav', 'ogg', 'm4a', 'opus'];
        foreach ($sttSources as $from) {
            foreach (['txt', 'srt', 'vtt'] as $to) {
                $matrix[$from . '_stt'][$to] = ['category' => FileCategory::Audio, 'isAi' => true];
            }
        }
        foreach (['txt', 'md'] as $from) {
            foreach (['mp3', 'wav', 'ogg'] as $to) {
                $matrix[$from . '_tts'][$to] = ['category' => FileCategory::Document, 'isAi' => true];
            }
        }

        return $matrix;
    }
}
