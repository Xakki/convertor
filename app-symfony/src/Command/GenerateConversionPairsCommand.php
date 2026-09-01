<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Conversion\ConversionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * CNV-71-01 stage 2: PHP half of the two-stage `make formats-catalog` pipeline.
 * Stage 1 (Python, `workers/tools/gen_worker_capabilities.py`) statically
 * extracts the 7 registering workers' hardcoded matrices into the COMMITTED
 * `config/catalog/worker_capabilities.json` (register-payload blobs). This
 * command is stage 2: reads that file and reduces it into the RESOLVED pair
 * catalog `config/catalog/conversion_pairs.json` — `{from, to, category, isAi,
 * ocrCapable}`, one row per supported conversion.
 *
 * The reduction itself (category resolution, non-AI-beats-AI precedence,
 * {@see ConversionRegistry::NON_AI_PRECEDENCE} tie-break) is NOT reimplemented
 * here — it runs through {@see ConversionRegistry::getSupportedFormatsFromBlobs()},
 * the exact same pure reduction the DB-backed routing matrix uses
 * ({@see ConversionRegistry::reduceCapabilities()}). One implementation, two
 * callers (live DB registrations vs. this static catalog) — the whole point
 * of the CNV-71-01 seam, so the static catalog can never silently diverge in
 * POLICY from what `/formats`/submit actually route at runtime (it can still
 * diverge in CONTENT — e.g. `pages` — see the class-level note in
 * `gen_worker_capabilities.py` and the task card).
 *
 * Output is deterministic: pairs sorted by (from, to), 2-space indent
 * (matching `worker_capabilities.json`'s own formatting), trailing newline —
 * so a re-run over unchanged input produces a byte-identical file (required
 * for `--check` and for the PHPUnit drift test
 * `App\Tests\Unit\Service\Conversion\ConversionPairsCatalogDriftTest` to be
 * meaningful).
 */
#[AsCommand(
    name: 'app:catalog:generate-conversion-pairs',
    description: 'Собрать резолвленный каталог пар конвертации (config/catalog/conversion_pairs.json) из worker_capabilities.json',
)]
final class GenerateConversionPairsCommand extends Command
{
    private const string CAPABILITIES_RELATIVE_PATH = '/config/catalog/worker_capabilities.json';

    private const string OUTPUT_RELATIVE_PATH = '/config/catalog/conversion_pairs.json';

    public function __construct(
        private readonly ConversionRegistry $registry,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Ничего не писать — сравнить закоммиченный файл со свежей редукцией и выйти с кодом ошибки при расхождении',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $capabilitiesPath = $this->projectDir . self::CAPABILITIES_RELATIVE_PATH;
        $outputPath       = $this->projectDir . self::OUTPUT_RELATIVE_PATH;

        $blobs = $this->readBlobs($capabilitiesPath, $io);
        if ($blobs === null) {
            return Command::FAILURE;
        }

        $pairs = $this->registry->getSupportedFormatsFromBlobs($blobs);
        usort($pairs, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        $fresh = self::encodeJson($pairs);

        if ((bool) $input->getOption('check')) {
            return $this->runCheck($outputPath, $pairs, $fresh, $io);
        }

        if (file_put_contents($outputPath, $fresh) === false) {
            $io->error("Не удалось записать {$outputPath}");

            return Command::FAILURE;
        }

        $io->success(sprintf('Записано %d пар(ы) в %s', count($pairs), $outputPath));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null null — ошибка уже выведена в $io
     */
    private function readBlobs(string $path, SymfonyStyle $io): ?array
    {
        if (! is_file($path)) {
            $io->error("Файл не найден: {$path}. Сначала запустите `make formats-catalog` (стадия 1, Python) — этот файл коммитится, руками не создаётся.");

            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $io->error("Не удалось прочитать {$path}");

            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error("Невалидный JSON в {$path}: {$e->getMessage()}");

            return null;
        }

        if (! is_array($decoded)) {
            $io->error("{$path}: ожидался JSON-массив блобов, получено: " . get_debug_type($decoded));

            return null;
        }

        /** @var list<array<string, mixed>> $decoded */
        return array_values($decoded);
    }

    /**
     * @param list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $freshPairs
     */
    private function runCheck(string $outputPath, array $freshPairs, string $fresh, SymfonyStyle $io): int
    {
        if (! is_file($outputPath)) {
            $io->error("{$outputPath} не существует — запустите `make formats-catalog` и закоммитьте результат.");

            return Command::FAILURE;
        }

        $committedRaw = file_get_contents($outputPath);
        if ($committedRaw === false) {
            $io->error("Не удалось прочитать {$outputPath}");

            return Command::FAILURE;
        }

        if ($committedRaw === $fresh) {
            $io->success(sprintf('%s актуален (%d пар)', $outputPath, count($freshPairs)));

            return Command::SUCCESS;
        }

        $io->error("{$outputPath} РАСХОДИТСЯ со свежей редукцией worker_capabilities.json — запустите `make formats-catalog` и закоммитьте результат.");
        $io->writeln($this->readableDiff($committedRaw, $freshPairs));

        return Command::FAILURE;
    }

    /**
     * Readable, pair-level diff (not a raw line diff — friendlier for a JSON
     * array that re-sorts/re-indents wholesale on any single pair change).
     *
     * @param list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $freshPairs
     */
    private function readableDiff(string $committedRaw, array $freshPairs): string
    {
        try {
            /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $committedPairs */
            $committedPairs = json_decode($committedRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '  (закоммиченный файл — невалидный JSON, сравнение по парам невозможно)';
        }

        $format = static fn (array $p): string => sprintf(
            '%s->%s = %s|isAi:%s|ocr:%s',
            $p['from'],
            $p['to'],
            $p['category'],
            $p['isAi'] ? '1' : '0',
            $p['ocrCapable'] ? '1' : '0',
        );

        $committedByKey = [];
        foreach ($committedPairs as $p) {
            $committedByKey["{$p['from']}->{$p['to']}"] = $format($p);
        }
        $freshByKey = [];
        foreach ($freshPairs as $p) {
            $freshByKey["{$p['from']}->{$p['to']}"] = $format($p);
        }

        $lines = [];
        foreach ($committedByKey as $key => $line) {
            if (! isset($freshByKey[$key])) {
                $lines[] = "  - {$line}";
            } elseif ($freshByKey[$key] !== $line) {
                $lines[] = "  ~ {$line}  =>  {$freshByKey[$key]}";
            }
        }
        foreach ($freshByKey as $key => $line) {
            if (! isset($committedByKey[$key])) {
                $lines[] = "  + {$line}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $pairs
     */
    private static function encodeJson(array $pairs): string
    {
        $json = json_encode($pairs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // PHP's JSON_PRETTY_PRINT hardcodes 4-space indent with no option to
        // configure it — halve every leading-space run to match the 2-space
        // style of the committed worker_capabilities.json (both generated
        // catalogs stay visually consistent). Safe because json_encode's
        // pretty-printer only ever emits indents in multiples of 4.
        $json = (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', (int) (strlen($m[0]) / 2)),
            $json,
        );

        return $json . "\n";
    }
}
