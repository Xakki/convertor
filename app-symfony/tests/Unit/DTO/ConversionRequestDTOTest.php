<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\ConversionRequestDTO;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * home-02-text-input: {@see ConversionRequestDTO::fromText()} materializes pasted
 * text to a temp file wrapped in an `UploadedFile` (test mode) so `ConversionManager`
 * stays untouched (file vs text is indistinguishable below the DTO boundary).
 * {@see ConversionRequestDTO::cleanupTempFile()} is the matching teardown the
 * controller calls in `finally`.
 */
final class ConversionRequestDTOTest extends TestCase
{
    public function testFromTextMaterializesContentAndExtension(): void
    {
        $dto = ConversionRequestDTO::fromText(new User(), "# Заголовок\n\nПривет мир", 'md', 'html', true);

        self::assertSame('md', $dto->file->getClientOriginalExtension());
        self::assertSame("# Заголовок\n\nПривет мир", file_get_contents($dto->file->getPathname()));
        self::assertSame('html', $dto->toFormat);
        self::assertFalse($dto->ocr, 'text input is never an OCR request');
        self::assertTrue($dto->privileged);

        $dto->cleanupTempFile();
    }

    public function testFromTextPreservesValidatedOptions(): void
    {
        $dto = ConversionRequestDTO::fromText(
            new User(),
            'prompt',
            'txt',
            'json',
            true,
            ['model' => 'fast'],
        );

        self::assertSame(['model' => 'fast'], $dto->options);
        $dto->cleanupTempFile();
    }

    public function testFromTextByteSizeMatchesFileGetSize(): void
    {
        // UTF-8 multibyte text: getSize() must reflect BYTE length (what the
        // server's per-plan size gate checks), not the PHP string char count.
        $text = str_repeat('и', 100); // 2 bytes/char in UTF-8 → 200 bytes
        $dto  = ConversionRequestDTO::fromText(new User(), $text, 'txt', 'md');

        self::assertSame(\strlen($text), $dto->file->getSize());

        $dto->cleanupTempFile();
    }

    public function testCleanupTempFileRemovesTheMaterializedFile(): void
    {
        $dto  = ConversionRequestDTO::fromText(new User(), 'hello', 'txt', 'md');
        $path = $dto->file->getPathname();

        self::assertFileExists($path);

        $dto->cleanupTempFile();

        self::assertFileDoesNotExist($path);
    }

    public function testCleanupTempFileIsNoOpForFileInput(): void
    {
        // File-input DTOs never carry a tempFilePath — cleanupTempFile() must be
        // a safe no-op (controller calls it unconditionally in `finally`).
        $path = tempnam(sys_get_temp_dir(), 'conv_upload_test_');
        self::assertNotFalse($path);
        file_put_contents($path, 'irrelevant');
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'sample.txt', null, null, true);

        $dto = new ConversionRequestDTO(new User(), $file, 'pdf');
        $dto->cleanupTempFile();

        // The real upload path is untouched — cleanupTempFile() only ever
        // removes fromText()'s OWN temp file, never a caller-owned upload.
        self::assertFileExists($path);

        unlink($path);
    }

    public function testCleanupTempFileIsIdempotent(): void
    {
        $dto = ConversionRequestDTO::fromText(new User(), 'hello', 'txt', 'md');
        $dto->cleanupTempFile();
        $dto->cleanupTempFile(); // second call must not error (is_file guard)

        self::addToAssertionCount(1);
    }
}
