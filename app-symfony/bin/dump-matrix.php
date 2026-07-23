<?php

declare(strict_types=1);

/**
 * Regenerates the golden routing snapshot consumed by
 * {@see App\Tests\Unit\Service\Conversion\ConversionRegistryGoldenTest} and
 * the Python drift test (`workers/tests/test_routing_drift.py`).
 *
 * Restored 2026-07-22 (registry-04) after accidental deletion by commit
 * `2105d70` (2026-07-10, unrelated auth feature) — the Python drift test has
 * no fallback for a missing tool other than `pytest.skip()`, so its two
 * assertions silently stopped running for ~12 days instead of failing.
 *
 * Rewritten (registry-04) to build the matrix through the real DI container
 * (`App\Kernel`), reading `ConversionRegistry` backed by the LIVE
 * `WorkerCapabilityRepository`/DB — deliberately NOT `new ConversionRegistry()`
 * with no constructor args. At the time this reasoning was written, that would
 * have resurrected the hardcoded fallback path (dead at runtime on any migrated
 * environment since the registry-03 seed migration made the capability table
 * non-empty) and made this tool validate nothing while still printing a
 * plausible-looking snapshot. registry-05 later deleted that fallback outright
 * — repository=null now yields an EMPTY matrix — so the same no-args call
 * would instead make every downstream check below (`$capabilities === []`,
 * `$formats === []`) fire immediately; the outcome (refuse to print, exit 1)
 * is the same, but going through the container is still correct: it's the
 * only way this script exercises the REAL DB state instead of a guaranteed-empty
 * stub.
 *
 * `ConversionRegistry`/`WorkerCapabilityRepository` are marked `public: true`
 * in `config/services.yaml` specifically so this CLI script can fetch them —
 * see the comment there; application code never does this, it always uses
 * normal constructor autowiring.
 *
 * Usage (from app-symfony/, inside the php container):
 *     php bin/dump-matrix.php           # print snapshot to stdout (text)
 *     php bin/dump-matrix.php --json    # print snapshot as JSON (for drift tests)
 *     php bin/dump-matrix.php --write   # overwrite tests/Fixtures/conversion_matrix.golden.txt
 *
 * Only run with --write after reviewing the diff and confirming the routing
 * change is intentional.
 *
 * --json output contract:
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
 *   1 — the capability table is unreachable or empty, OR a non-empty
 *       capability set still produced an empty matrix. Either way this
 *       refuses to print an empty-but-valid document: a drift test diffing
 *       against "nothing" would silently pass, exactly the failure mode that
 *       cost 12 days of dead CI coverage when this file itself went missing.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use Symfony\Component\Dotenv\Dotenv;

// Same `.env`/`.env.local`/`.env.<env>[.local]` loading bin/console and
// public/index.php get via symfony/runtime — needed because config like
// DATABASE_URL is resolved from these files, not raw container env vars.
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$appEnv   = $_SERVER['APP_ENV'] ?? 'dev';
$appDebug = (bool) ($_SERVER['APP_DEBUG'] ?? ($appEnv !== 'prod'));

$kernel = new Kernel($appEnv, $appDebug);
$kernel->boot();
$container = $kernel->getContainer();

/** @var WorkerCapabilityRepository $repository */
$repository = $container->get(WorkerCapabilityRepository::class);

try {
    $capabilities = $repository->findAllCapabilities();
} catch (\Throwable $e) {
    fwrite(STDERR, "dump-matrix: worker_capabilities DB unreachable: {$e->getMessage()}\n");
    exit(1);
}

if ($capabilities === []) {
    fwrite(STDERR,
        "dump-matrix: worker_capabilities table is EMPTY — refusing to silently fall back to the "
        . "hardcoded matrix (that would validate nothing; see registry-03/registry-04). Run the "
        . "registry-03 seed migration or register a worker first.\n",
    );
    exit(1);
}

/** @var ConversionRegistry $registry */
$registry = $container->get(ConversionRegistry::class);
// Force a fresh DB read: the container's cache.app pool is shared (Redis)
// across processes, so a warm cache from an earlier run could otherwise mask
// the current DB state — exactly what a diagnostic/drift tool must not do.
$registry->invalidateMatrix();

$formats = $registry->getSupportedFormats();

if ($formats === []) {
    fwrite(STDERR,
        "dump-matrix: registry produced an EMPTY matrix from a non-empty capability set "
        . "(" . count($capabilities) . " row(s) — all unparseable?) — refusing to print an empty document.\n",
    );
    exit(1);
}

$map = [];
foreach ($formats as $f) {
    $stream                          = $registry->streamFor($f['from'], $f['to']);
    $map["{$f['from']}->{$f['to']}"] = "{$f['category']}|{$stream}|" . (int) $f['isAi'];
}
ksort($map);

if (in_array('--json', $argv, true)) {
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

if (in_array('--write', $argv, true)) {
    file_put_contents($fixture, $lines);
    fwrite(STDERR, 'Wrote ' . count($map) . " entries to {$fixture}\n");
} else {
    echo $lines;
}
