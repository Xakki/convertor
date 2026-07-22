<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Service\Conversion\ConversionRegistry;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * home-02-text-input: {@see ConversionRegistry::isTextSourceSupported()} — text-mode
 * source gate. A fixed textual-format allowlist (txt/md/rst/latex/wiki/html/
 * csv/json/xml/yaml/toml) ANDed with the SAME {@see ConversionRegistry::isSupported()}
 * pair check as the file-upload path (no separate compatibility table). The
 * allowlist is format-based, NOT category-based: the DB-backed matrix can
 * (and in production does) register md/html/rst under `FileCategory::Document`
 * rather than a dedicated `Markup` category, so branching on getCategory()
 * would silently reject legitimate textual sources.
 */
final class ConversionRegistryTextSourceTest extends TestCase
{
    use SeedsConversionRegistry;

    private ConversionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = $this->newSeedRegistry();
    }

    public function testMarkupSourceIsAllowed(): void
    {
        self::assertTrue($this->registry->isTextSourceSupported('md', 'html'));
        self::assertTrue($this->registry->isTextSourceSupported('html', 'pdf'));
    }

    public function testDataSourceIsAllowed(): void
    {
        self::assertTrue($this->registry->isTextSourceSupported('csv', 'json'));
        self::assertTrue($this->registry->isTextSourceSupported('json', 'yaml'));
    }

    public function testPlainTxtDocumentSourceIsAllowed(): void
    {
        self::assertTrue($this->registry->isTextSourceSupported('txt', 'pdf'));
    }

    public function testBinaryDocumentSourceIsRejectedEvenThoughUploadPairIsValid(): void
    {
        // docx→pdf IS a valid isSupported() pair for the file-upload path, but
        // docx is a binary container — pasted text claiming source_format=docx
        // must be rejected (no MIME-sniff safety net for text input).
        self::assertTrue($this->registry->isSupported('docx', 'pdf'), 'precondition: valid upload pair');
        self::assertFalse($this->registry->isTextSourceSupported('docx', 'pdf'));
    }

    public function testPdfSourceIsRejected(): void
    {
        self::assertTrue($this->registry->isSupported('pdf', 'txt'), 'precondition: valid upload pair');
        self::assertFalse($this->registry->isTextSourceSupported('pdf', 'txt'));
    }

    public function testImageAudioVideoSourcesAreRejected(): void
    {
        self::assertFalse($this->registry->isTextSourceSupported('jpg', 'png'));
        self::assertFalse($this->registry->isTextSourceSupported('mp3', 'wav'));
        self::assertFalse($this->registry->isTextSourceSupported('mp4', 'avi'));
    }

    public function testUnsupportedPairIsRejected(): void
    {
        self::assertFalse($this->registry->isTextSourceSupported('md', 'zzz'));
    }
}
