<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PublicSkillTest extends TestCase
{
    private const OPENAPI_URL = 'https://convertor.xakki.pro/api/doc.json';

    private string $skillPath;

    protected function setUp(): void
    {
        $this->skillPath = dirname(__DIR__, 2) . '/public/convertor-api/SKILL.md';
    }

    public function testSkillHasValidAgentSkillsFrontmatter(): void
    {
        self::assertFileExists($this->skillPath);
        $content = file_get_contents($this->skillPath);
        self::assertIsString($content);

        self::assertSame(1, preg_match(
            '/\A---\R(?<yaml>.*?)\R---\R(?<body>.+)\z/s',
            $content,
            $matches,
        ));

        $frontmatter = Yaml::parse($matches['yaml']);
        self::assertIsArray($frontmatter);
        self::assertSame('convertor-api', $frontmatter['name'] ?? null);
        self::assertSame(basename(dirname($this->skillPath)), $frontmatter['name']);
        self::assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $frontmatter['name']);
        self::assertIsString($frontmatter['description'] ?? null);
        self::assertNotSame('', trim($frontmatter['description']));
        self::assertLessThanOrEqual(1024, mb_strlen($frontmatter['description']));
        self::assertLessThanOrEqual(250, mb_strlen($frontmatter['description']));
        foreach (['documents', 'images', 'audio', 'video', 'structured data', 'text', 'OCR', 'transcription', 'text-to-speech'] as $keyword) {
            self::assertStringContainsString($keyword, $frontmatter['description']);
        }
        self::assertIsString($frontmatter['compatibility'] ?? null);
        self::assertLessThanOrEqual(500, mb_strlen($frontmatter['compatibility']));
        self::assertIsArray($frontmatter['metadata'] ?? null);
        foreach ($frontmatter['metadata'] as $value) {
            self::assertIsString($value);
        }
        $tags = array_map('trim', explode(',', $frontmatter['metadata']['tags'] ?? ''));
        foreach ([
            'file-conversion',
            'document-conversion',
            'image-conversion',
            'audio-conversion',
            'video-conversion',
            'data-conversion',
            'text-conversion',
            'ocr',
            'transcription',
            'text-to-speech',
        ] as $requiredTag) {
            self::assertContains($requiredTag, $tags);
        }
        self::assertCount(count(array_unique($tags)), $tags);
        self::assertNotSame('', trim($matches['body']));
        self::assertLessThanOrEqual(500, substr_count($content, "\n") + 1);
        self::assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $content);
    }

    public function testSkillRequiresFreshAbsoluteOpenApiSchema(): void
    {
        $content = file_get_contents($this->skillPath);
        self::assertIsString($content);

        self::assertGreaterThanOrEqual(3, substr_count($content, self::OPENAPI_URL));
        self::assertStringContainsString('Before every use of the API', $content);
        self::assertStringContainsString('do not make API requests', $content);
        self::assertStringContainsString('requestBody', $content);
        self::assertStringContainsString('security', $content);
        self::assertStringContainsString('responses', $content);
    }

    public function testSkillDocumentsOptionalAuthenticationResolutionAndFirstUse(): void
    {
        $content = file_get_contents($this->skillPath);
        self::assertIsString($content);

        self::assertStringContainsString('Authentication and first use', $content);
        self::assertStringContainsString('CONVERTOR_TOKEN', $content);
        self::assertStringContainsString('${XDG_CONFIG_HOME:-$HOME/.config}/convertor-api/token', $content);
        self::assertStringContainsString('environment variable takes precedence', $content);
        self::assertStringContainsString('guest', $content);
        self::assertStringContainsString('configure', $content);
        self::assertStringContainsString('Do not ask the user to paste a token into chat', $content);
        self::assertStringContainsString('authentication-required', $content);
    }

    public function testCredentialContractRequiresRaceSafeReadingAndSecretSafeTransport(): void
    {
        $content = file_get_contents($this->skillPath);
        self::assertIsString($content);

        self::assertStringContainsString('non-symlink', $content);
        self::assertStringContainsString('no-follow', $content);
        self::assertStringContainsString('opened file descriptor', $content);
        self::assertStringContainsString('ignore it and offer guest mode or external configuration', $content);
        self::assertMatchesRegularExpression('/process\s+listing/', $content);
        self::assertStringContainsString('in-memory HTTP client', $content);
        self::assertMatchesRegularExpression('/curl config supplied through\s+standard input/', $content);
        self::assertStringNotContainsString('curl -H', $content);
    }

    public function testSkillContainsNoEmbeddedSecretsOrUnsafeCredentialInstructions(): void
    {
        $content = file_get_contents($this->skillPath);
        self::assertIsString($content);

        self::assertDoesNotMatchRegularExpression('/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', $content);
        self::assertDoesNotMatchRegularExpression('/Authorization:\s*Bearer\s+(?!\$)[A-Za-z0-9._~-]{16,}/i', $content);
        self::assertDoesNotMatchRegularExpression('/(?:TOKEN|SECRET|PASSWORD|API_KEY)\s*=\s*["\'][^$"\']{12,}["\']/i', $content);
        self::assertDoesNotMatchRegularExpression('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $content);
        self::assertDoesNotMatchRegularExpression('#/(?:home|Users)/[^\s`]+#', $content);
        self::assertDoesNotMatchRegularExpression('/\b(?:ROLE_ADMIN|user id|account:)\b/i', $content);
    }
}
