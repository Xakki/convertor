<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Enum\FileCategory;
use App\Service\Conversion\ConversionRegistry;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;

final class ConversionRegistryRoutingTest extends TestCase
{
    use SeedsConversionRegistry;

    private ConversionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = $this->newSeedRegistry();
    }

    public function testRasterOcrPairIsSupportedAndRoutesToImageNonAi(): void
    {
        self::assertTrue($this->registry->isSupported('jpg', 'txt'));
        self::assertFalse($this->registry->isAi('jpg', 'txt'));
        self::assertSame(FileCategory::Image, $this->registry->getCategory('jpg', 'txt'));
        self::assertSame('image', $this->registry->streamFor('jpg', 'txt'));
    }

    public function testPdfToTextWithoutFlagStaysDocument(): void
    {
        self::assertTrue($this->registry->isSupported('pdf', 'txt'));
        self::assertFalse($this->registry->isAi('pdf', 'txt'));
        self::assertSame(FileCategory::Document, $this->registry->getCategory('pdf', 'txt'));
        self::assertSame('document', $this->registry->streamFor('pdf', 'txt'));
    }

    public function testPdfToTextWithOcrFlagRoutesToImage(): void
    {
        self::assertSame('image', $this->registry->streamFor('pdf', 'txt', true));
        self::assertTrue($this->registry->isOcrSupported('pdf', 'txt'));
    }

    public function testPdfOcrIsNotAPlainMatrixRasterEntry(): void
    {
        // pdf OCR is flag-only; without the flag pdf→md is document text extraction.
        self::assertSame('document', $this->registry->streamFor('pdf', 'md'));
        // and the raster OCR set still covers pdf for the flag path.
        self::assertTrue($this->registry->isOcrSupported('pdf', 'md'));
    }

    public function testOcrSetMembership(): void
    {
        foreach (['jpg', 'png', 'tiff', 'pdf'] as $from) {
            foreach (['txt', 'md', 'docx'] as $to) {
                self::assertTrue($this->registry->isOcrSupported($from, $to), "{$from}->{$to}");
            }
        }
        self::assertFalse($this->registry->isOcrSupported('gif', 'txt'));
        self::assertFalse($this->registry->isOcrSupported('jpg', 'png'));
    }

    public function testStreamForWithOcrFlagRejectsNonOcrPair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->streamFor('gif', 'txt', true);
    }

    public function testDeadOcrVirtualKeysRemoved(): void
    {
        self::assertFalse($this->registry->isSupported('jpg_ocr', 'txt'));
        self::assertFalse($this->registry->isSupported('pdf_ocr', 'md'));
    }
}
