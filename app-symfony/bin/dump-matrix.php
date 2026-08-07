<?php

declare(strict_types=1);

/**
 * Prints the routing snapshot consumed by
 * {@see App\Tests\Unit\Service\Conversion\ConversionRegistryGoldenTest} and
 * the Python drift test (`workers/tests/test_routing_drift.py`).
 *
 * Restored 2026-07-22 (registry-04) after accidental deletion by commit
 * `2105d70` (2026-07-10, unrelated auth feature) — the Python drift test has
 * no fallback for a missing tool other than `pytest.skip()`, so its two
 * assertions silently stopped running for ~12 days instead of failing. That
 * "must never skip silently" contract is why this tool still hard-fails
 * (exit 1) rather than printing an empty document on any error below.
 *
 * REWRITTEN (CNV-71-02): no more Kernel boot, no more DI container, no more
 * `WorkerCapabilityRepository`/DB dependency AT ALL. `ConversionRegistry`'s
 * routing matrix (`isSupported()`/`getCategory()`/`streamFor()`) now comes
 * from the committed static catalog `config/catalog/conversion_pairs.json`
 * (see the class docblock) — `new ConversionRegistry()` with no arguments
 * already resolves to that real file (`ConversionRegistry::defaultCatalogPath()`),
 * so this script just needs the Composer autoloader, nothing else. This is a
 * strict simplification: the tool used to require a migrated, seeded
 * `convertor-test`/dev DB just to dump what is now a committed, versioned
 * file — `workers/tests/test_routing_drift.py` (which shells this tool out as
 * a subprocess) now gets a faster, DB-free dependency for the exact same
 * `--json` contract, unchanged below.
 *
 * `ConversionRegistry` no longer needs `public: true` in `config/services.yaml`
 * for THIS script's sake (that historical reason from registry-04 is gone),
 * but the flag is left in place — see the comment there.
 *
 * Usage (from app-symfony/, inside the php container — no DB/env needed):
 *     php bin/dump-matrix.php           # print snapshot to stdout (text)
 *     php bin/dump-matrix.php --json    # print snapshot as JSON (for drift tests)
 *     php bin/dump-matrix.php --write   # overwrite tests/Fixtures/conversion_matrix.golden.txt
 *
 * Only run with --write after reviewing the diff and confirming the routing
 * change is intentional (i.e. after regenerating and reviewing
 * `config/catalog/conversion_pairs.json` itself via `make formats-catalog`).
 *
 * --json output contract (UNCHANGED from the DB-backed version):
 *     {
 *       "routingKeys": ["ai", "audio", "data", "document", "image", "video"],
 *       "matrix": [
 *         {"from": "csv", "to": "json", "category": "data", "stream": "data", "isAi": false},
 *         ...
 *       ]
 *     }
 *   - `routingKeys` — the distinct set of `streamFor()` outputs across every
 *     pair (the suffix that becomes `conv_<stream>`), sorted by first
 *     appearance (matrix is pre-sorted by from→to, so this is effectively
 *     alphabetical by the first from→to pair that uses each stream).
 *   - `matrix` — one entry per supported (from, to) pair, sorted by
 *     `"{from}->{to}"` ascending. `category` is the RAW stored FileCategory
 *     (e.g. pdf→txt is `"document"`; OCR routing is a separate flag-gated
 *     path — see `ConversionRegistry::isOcrSupported()` — and is NOT reflected
 *     here). `stream` is the actual `streamFor($from, $to)` routing target,
 *     which can differ from `category` (e.g. `markup` folds into `document`).
 *
 * Exit codes:
 *   0 — snapshot printed/written successfully.
 *   1 — the catalog file is missing/malformed (`ConversionRegistry` throws
 *       loudly — see its `loadCatalogMatrix()`) or produced zero pairs.
 *       Refuses to print an empty-but-valid document either way: a drift test
 *       diffing against "nothing" would silently pass, exactly the failure
 *       mode that cost 12 days of dead CI coverage when this file itself went
 *       missing.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Conversion\ConversionRegistry;

// PHPStan (level 8) не знает CLI-суперглобал `$argv`; берём из $_SERVER.
/** @var list<string> $cliArgv */
$cliArgv = array_values(array_map(strval(...), \is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));

$registry = new ConversionRegistry();

try {
    $formats = $registry->getSupportedFormats();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "dump-matrix: не удалось прочитать каталог: {$e->getMessage()}\n");
    exit(1);
}

if ($formats === []) {
    fwrite(
        STDERR,
        "dump-matrix: каталог пуст (0 пар) — отказываюсь печатать пустой документ. Если это "
        . "ожидаемо, проверьте config/catalog/conversion_pairs.json вручную.\n",
    );
    exit(1);
}

$map = [];
foreach ($formats as $f) {
    $stream                          = $registry->streamFor($f['from'], $f['to']);
    $map["{$f['from']}->{$f['to']}"] = "{$f['category']}|{$stream}|" . (int) $f['isAi'];
}
ksort($map);

if (in_array('--json', $cliArgv, true)) {
    $routingKeySet = [];
    $jsonMatrix    = [];
    foreach ($map as $key => $value) {
        [$from, $to]                   = explode('->', $key);
        [$category, $stream, $isAiRaw] = explode('|', $value);
        $routingKeySet[$stream]        = true;
        $jsonMatrix[]                  = [
            'from'     => $from,
            'to'       => $to,
            'category' => $category,
            'stream'   => $stream,
            'isAi'     => (bool) (int) $isAiRaw,
        ];
    }
    echo json_encode([
        'routingKeys' => array_keys($routingKeySet),
        'matrix'      => $jsonMatrix,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$lines = '';
foreach ($map as $key => $value) {
    $lines .= "{$key} = {$value}\n";
}

$fixture = dirname(__DIR__) . '/tests/Fixtures/conversion_matrix.golden.txt';

if (in_array('--write', $cliArgv, true)) {
    file_put_contents($fixture, $lines);
    fwrite(STDERR, 'Wrote ' . count($map) . " entries to {$fixture}\n");
} else {
    echo $lines;
}
