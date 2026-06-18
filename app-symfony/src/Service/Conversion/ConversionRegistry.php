<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Enum\FileCategory;

class ConversionRegistry
{
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

    private function buildMatrix(): array
    {
        $matrix = [];

        // Documents → documents/pdf
        $docSources = ['doc', 'docx', 'odt', 'rtf', 'txt', 'html', 'epub', 'pages'];
        $docTargets = ['docx', 'odt', 'pdf', 'txt', 'html', 'md', 'rtf', 'epub'];
        foreach ($docSources as $from) {
            foreach ($docTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Document, 'isAi' => false];
                }
            }
        }

        // PDF → other formats
        foreach (['docx', 'txt', 'md', 'jpg'] as $to) {
            $matrix['pdf'][$to] = ['category' => FileCategory::Document, 'isAi' => false];
        }

        // Markup conversions (Pandoc)
        $markupSources = ['md', 'rst', 'latex', 'html', 'wiki'];
        $markupTargets = ['md', 'rst', 'html', 'pdf', 'docx'];
        foreach ($markupSources as $from) {
            foreach ($markupTargets as $to) {
                if ($from !== $to) {
                    // html already set via documents above; override is fine here
                    $matrix[$from][$to] = ['category' => FileCategory::Markup, 'isAi' => false];
                }
            }
        }

        // Data conversions
        $dataSources = ['csv', 'json', 'xml', 'yaml', 'toml'];
        $dataTargets = ['csv', 'json', 'xml', 'yaml'];
        foreach ($dataSources as $from) {
            foreach ($dataTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Data, 'isAi' => false];
                }
            }
        }

        // Images
        $imageSources = ['jpg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'svg', 'ico', 'avif', 'heic'];
        $imageTargets = ['jpg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'ico', 'avif', 'pdf'];
        foreach ($imageSources as $from) {
            foreach ($imageTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Image, 'isAi' => false];
                }
            }
        }

        // OCR (AI)
        $ocrSources = ['jpg', 'png', 'pdf', 'tiff'];
        $ocrTargets = ['txt', 'md', 'docx'];
        foreach ($ocrSources as $from) {
            foreach ($ocrTargets as $to) {
                $matrix[$from . '_ocr'][$to] = ['category' => FileCategory::Image, 'isAi' => true];
            }
        }

        // Audio conversions
        $audioSources = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus', 'wma'];
        $audioTargets = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'];
        foreach ($audioSources as $from) {
            foreach ($audioTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Audio, 'isAi' => false];
                }
            }
        }

        // Video conversions
        $videoSources = ['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv'];
        $videoTargets = ['mp4', 'avi', 'mkv', 'mov', 'webm'];
        foreach ($videoSources as $from) {
            foreach ($videoTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Video, 'isAi' => false];
                }
            }
        }

        // Video → Audio
        $videoAudioSources = ['mp4', 'avi', 'mkv', 'mov'];
        $videoAudioTargets = ['mp3', 'wav', 'ogg', 'flac'];
        foreach ($videoAudioSources as $from) {
            foreach ($videoAudioTargets as $to) {
                $matrix[$from][$to] = ['category' => FileCategory::Audio, 'isAi' => false];
            }
        }

        // Speech → Text (AI / Whisper)
        $sttSources = ['mp3', 'wav', 'ogg', 'm4a', 'opus'];
        foreach ($sttSources as $from) {
            foreach (['txt', 'srt', 'vtt'] as $to) {
                $matrix[$from . '_stt'][$to] = ['category' => FileCategory::Audio, 'isAi' => true];
            }
        }

        // Text → Speech (AI / TTS)
        foreach (['txt', 'md'] as $from) {
            foreach (['mp3', 'wav', 'ogg'] as $to) {
                $matrix[$from . '_tts'][$to] = ['category' => FileCategory::Document, 'isAi' => true];
            }
        }

        // Archives
        $archiveSources = ['zip', 'tar', 'gz', 'bz2', '7z'];
        foreach ($archiveSources as $from) {
            foreach (['zip', 'tar.gz'] as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Archive, 'isAi' => false];
                }
            }
        }

        // CAD/DWG
        foreach (['dwg', 'dxf'] as $from) {
            foreach (['pdf', 'svg', 'png'] as $to) {
                $matrix[$from][$to] = ['category' => FileCategory::Document, 'isAi' => false];
            }
        }

        // Spreadsheets
        $spreadsheetSources = ['xls', 'xlsx', 'ods', 'csv'];
        $spreadsheetTargets = ['xlsx', 'ods', 'csv', 'pdf'];
        foreach ($spreadsheetSources as $from) {
            foreach ($spreadsheetTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Document, 'isAi' => false];
                }
            }
        }

        // Presentations
        $presentationSources = ['ppt', 'pptx', 'odp'];
        $presentationTargets = ['pptx', 'odp', 'pdf'];
        foreach ($presentationSources as $from) {
            foreach ($presentationTargets as $to) {
                if ($from !== $to) {
                    $matrix[$from][$to] = ['category' => FileCategory::Document, 'isAi' => false];
                }
            }
        }

        return $matrix;
    }
}
