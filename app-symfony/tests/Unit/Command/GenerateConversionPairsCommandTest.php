<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GenerateConversionPairsCommand;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CNV-71-01: command mechanics in isolation (temp project dir, tiny synthetic
 * capability blob) — deliberately NOT the real committed catalog (that
 * end-to-end drift check is {@see \App\Tests\Unit\Service\Conversion\ConversionPairsCatalogDriftTest}).
 */
final class GenerateConversionPairsCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/cnv71_gen_pairs_' . uniqid();
        mkdir($this->projectDir . '/config/catalog', 0o777, true);
    }

    protected function tearDown(): void
    {
        $catalogDir = $this->projectDir . '/config/catalog';
        foreach (['worker_capabilities.json', 'conversion_pairs.json'] as $f) {
            @unlink($catalogDir . '/' . $f);
        }
        @rmdir($catalogDir);
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    /** @return list<array<string, mixed>> */
    private static function sampleBlobs(): array
    {
        return [
            [
                'workerType'        => 'document',
                'isAi'              => false,
                'streams'           => ['document'],
                'routingKeys'       => ['document'],
                'matrix'            => ['txt' => ['md', 'html']],
                'matrix_categories' => [],
            ],
        ];
    }

    public function testWritesSortedTwoSpacePairsFile(): void
    {
        file_put_contents(
            $this->projectDir . '/config/catalog/worker_capabilities.json',
            json_encode(self::sampleBlobs(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $tester = new CommandTester(new GenerateConversionPairsCommand(new ConversionRegistry(), $this->projectDir));
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);

        $outputPath = $this->projectDir . '/config/catalog/conversion_pairs.json';
        self::assertFileExists($outputPath);

        $written = file_get_contents($outputPath);
        self::assertNotFalse($written);
        self::assertStringEndsWith("]\n", $written, 'trailing newline expected');
        // 2-space indent, not PHP's default 4-space: top-level array items indent
        // exactly 2 spaces (nested object properties are 4 = 2 levels × 2).
        self::assertStringContainsString("[\n  {\n", $written, '2-space indent expected at the top array level');
        self::assertStringNotContainsString("[\n    {\n", $written, '4-space indent leaked — expected 2-space');

        /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $pairs */
        $pairs = json_decode($written, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                ['from' => 'txt', 'to' => 'html', 'category' => 'document', 'isAi' => false, 'ocrCapable' => false],
                ['from' => 'txt', 'to' => 'md', 'category' => 'document', 'isAi' => false, 'ocrCapable' => false],
            ],
            $pairs,
        );
    }

    public function testCheckFailsWhenCommittedFileIsStale(): void
    {
        file_put_contents(
            $this->projectDir . '/config/catalog/worker_capabilities.json',
            json_encode(self::sampleBlobs(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->projectDir . '/config/catalog/conversion_pairs.json', "[]\n");

        $tester = new CommandTester(new GenerateConversionPairsCommand(new ConversionRegistry(), $this->projectDir));
        $status = $tester->execute(['--check' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('РАСХОДИТСЯ', $tester->getDisplay());
    }

    public function testCheckPassesWhenCommittedFileMatchesFreshReduction(): void
    {
        file_put_contents(
            $this->projectDir . '/config/catalog/worker_capabilities.json',
            json_encode(self::sampleBlobs(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        // First run writes the file, second run in --check mode must see it as fresh.
        (new CommandTester(new GenerateConversionPairsCommand(new ConversionRegistry(), $this->projectDir)))->execute([]);

        $tester = new CommandTester(new GenerateConversionPairsCommand(new ConversionRegistry(), $this->projectDir));
        $status = $tester->execute(['--check' => true]);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testFailsClearlyWhenCapabilitiesFileMissing(): void
    {
        $tester = new CommandTester(new GenerateConversionPairsCommand(new ConversionRegistry(), $this->projectDir));
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Файл не найден', $tester->getDisplay());
    }
}
