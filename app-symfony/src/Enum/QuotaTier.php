<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Тир квоты: вычисляется в рантайме из category + isAi (CNV-30).
 * OCR — НЕ AI (image-воркер); isAi=true только STT/TTS.
 */
enum QuotaTier: string
{
    case Light  = 'light';
    case Medium = 'medium';
    case Heavy  = 'heavy';
    case Ai     = 'ai';

    public static function resolve(FileCategory $category, bool $isAi): self
    {
        if ($isAi) {
            return self::Ai;
        }

        return match ($category) {
            FileCategory::Document,
            FileCategory::Markup,
            FileCategory::Data,
            FileCategory::Archive => self::Light,
            FileCategory::Image,
            FileCategory::Audio => self::Medium,
            FileCategory::Video => self::Heavy,
        };
    }
}
