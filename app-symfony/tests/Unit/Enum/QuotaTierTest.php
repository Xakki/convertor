<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\FileCategory;
use App\Enum\QuotaTier;
use PHPUnit\Framework\TestCase;

/**
 * CNV-30: tier resolution from category + isAi (OCR ≠ AI).
 */
final class QuotaTierTest extends TestCase
{
    public function testOcrImageResolvesToMediumNotAi(): void
    {
        self::assertSame(QuotaTier::Medium, QuotaTier::resolve(FileCategory::Image, false));
    }

    public function testSttTtsResolvesToAiTier(): void
    {
        self::assertSame(QuotaTier::Ai, QuotaTier::resolve(FileCategory::Audio, true));
    }

    public function testVideoResolvesToHeavyWhenNotAi(): void
    {
        self::assertSame(QuotaTier::Heavy, QuotaTier::resolve(FileCategory::Video, false));
    }

    public function testDocumentResolvesToLight(): void
    {
        self::assertSame(QuotaTier::Light, QuotaTier::resolve(FileCategory::Document, false));
    }
}
