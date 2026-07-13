<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\S3Storage;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: скачивание результата с не-ASCII (кириллическим) именем не должно
 * падать 500. makeDisposition без ASCII-fallback кидал InvalidArgumentException
 * на любом не-ASCII имени (напр. `Техлид _ Архитектор-1.md`) → HTTP 500. Проверяем,
 * что contentDisposition() не бросает, а заголовок содержит валидный ASCII-`filename=`
 * плюс `filename*=utf-8''` с закодированным оригиналом.
 */
final class S3StorageDispositionTest extends TestCase
{
    public function testCyrillicFilenameDoesNotThrowAndEmitsAsciiFallback(): void
    {
        $name = 'Техлид _ Архитектор-1.md';

        $header = S3Storage::contentDisposition($name);

        self::assertStringStartsWith('attachment;', $header);

        // ASCII-fallback: извлекаем значение filename= (не filename*).
        self::assertMatchesRegularExpression('/(?<!\*)filename=/i', $header);
        preg_match('/(?<!\*)filename="?([^";]+)"?/i', $header, $m);
        self::assertNotEmpty($m, 'filename= fallback отсутствует в заголовке');
        $fallback = $m[1];

        // Fallback обязан быть чистым ASCII без запрещённых в нём символов.
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $fallback);
        self::assertStringNotContainsString('%', $fallback);
        self::assertStringNotContainsString('/', $fallback);
        self::assertStringNotContainsString('\\', $fallback);
        // Расширение сохраняется.
        self::assertStringEndsWith('.md', $fallback);

        // Оригинальное UTF-8 имя доступно современным браузерам через filename* (RFC 5987).
        self::assertStringContainsStringIgnoringCase("filename*=utf-8''", $header);
        self::assertStringContainsString(rawurlencode($name), $header);
    }

    /**
     * Крайний случай: имя целиком из символов, вычищаемых при построении fallback
     * (напр. только не-ASCII без латинизации/расширения) — fallback не пустой,
     * makeDisposition не падает.
     */
    public function testNonAsciiWithoutExtensionGetsDefaultFallback(): void
    {
        $name = '№№№';

        $header = S3Storage::contentDisposition($name);

        preg_match('/(?<!\*)filename="?([^";]+)"?/i', $header, $m);
        self::assertNotEmpty($m);
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $m[1]);
        self::assertNotSame('', trim($m[1]));
    }

    /**
     * ASCII-имя не должно портиться и не обязано тащить filename* (fallback == имя).
     */
    public function testAsciiFilenamePreserved(): void
    {
        $header = S3Storage::contentDisposition('report-6.pdf');

        self::assertStringContainsString('filename=report-6.pdf', $header);
    }
}
