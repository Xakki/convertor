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

    /**
     * CNV-88 regression guard (report proof (c)): a plain existing image
     * pair in the REAL committed catalog (no `executionKind` there today)
     * still routes to `image`, never to `browser` — CNV-88 adds an override
     * mechanism, it must not change any existing pair's routing.
     */
    public function testExistingImagePairStillRoutesToImageNotBrowser(): void
    {
        self::assertTrue($this->registry->isSupported('jpg', 'png'));
        self::assertSame('image', $this->registry->streamFor('jpg', 'png'));
        self::assertNotSame('browser', $this->registry->streamFor('jpg', 'png'));
    }

    // -------------------------------------------------------------------
    // CNV-106: `$animated` flag — mirrors the `$ocr` flag shape exactly.
    // -------------------------------------------------------------------

    /**
     * Can-fail proof (b) for the card: the animated flag routes svg→gif to
     * the browser worker, via the hardcoded allowlist — NOT the catalog's
     * per-pair `executionKind` (see {@see ConversionRegistry} class docblock:
     * that field would also reroute the ALREADY-published static svg→gif).
     */
    public function testAnimatedFlagRoutesSvgGifToBrowser(): void
    {
        self::assertTrue($this->registry->isAnimatedConversionSupported('svg', 'gif'));
        self::assertSame('browser', $this->registry->streamFor('svg', 'gif', ocr: false, animated: true));
    }

    /**
     * Same real committed catalog, `$animated` omitted (default false) — the
     * static pair keeps routing exactly as CNV-95 left it. This is the "not
     * published" pin at the registry level: nothing here changes just
     * because the animated mechanism now exists.
     */
    public function testAnimatedFlagDefaultsToFalseAndDoesNotChangeStaticSvgGifRouting(): void
    {
        self::assertSame('image', $this->registry->streamFor('svg', 'gif'));
        self::assertSame('image', $this->registry->streamFor('svg', 'gif', ocr: false, animated: false));
    }

    /**
     * Animated flag rejects any pair outside the hardcoded allowlist —
     * mirrors {@see testStreamForWithOcrFlagRejectsNonOcrPair()}.
     */
    public function testAnimatedFlagRejectsUnsupportedPair(): void
    {
        self::assertTrue($this->registry->isSupported('jpg', 'png'), 'precondition: a real, otherwise-valid pair');

        $this->expectException(\InvalidArgumentException::class);
        $this->registry->streamFor('jpg', 'png', ocr: false, animated: true);
    }

    public function testAnimatedSetMembership(): void
    {
        self::assertTrue($this->registry->isAnimatedConversionSupported('svg', 'gif'));
        self::assertFalse($this->registry->isAnimatedConversionSupported('svg', 'png'));
        self::assertFalse($this->registry->isAnimatedConversionSupported('jpg', 'gif'));
    }
}
