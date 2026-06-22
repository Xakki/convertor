<?php

declare(strict_types=1);

/**
 * Regenerates the golden routing snapshot consumed by
 * {@see App\Tests\Unit\Service\Conversion\ConversionRegistryGoldenTest}.
 *
 * Usage (from app-symfony/, inside the php container):
 *     php bin/dump-matrix.php           # print snapshot to stdout (text)
 *     php bin/dump-matrix.php --json    # print snapshot as JSON (for drift tests)
 *     php bin/dump-matrix.php --write   # overwrite tests/Fixtures/conversion_matrix.golden.txt
 *
 * Only run with --write after reviewing the diff and confirming the routing
 * change is intentional.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Conversion\ConversionRegistry;

$registry = new ConversionRegistry();

$map = [];
foreach ($registry->getSupportedFormats() as $f) {
    $stream                            = $registry->streamFor($f['from'], $f['to']);
    $map["{$f['from']}->{$f['to']}"] = "{$f['category']}|{$stream}|" . (int) $f['isAi'];
}
ksort($map);

if (in_array('--json', $argv, true)) {
    $routingKeySet = [];
    $jsonMatrix    = [];
    foreach ($map as $key => $value) {
        [$from, $to]                  = explode('->', $key);
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

$fixture = __DIR__ . '/../tests/Fixtures/conversion_matrix.golden.txt';

if (in_array('--write', $argv, true)) {
    file_put_contents($fixture, $lines);
    fwrite(STDERR, "Wrote " . count($map) . " entries to {$fixture}\n");
} else {
    echo $lines;
}
