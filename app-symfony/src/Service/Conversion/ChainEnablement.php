<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Allowlist stub for CNV-5 conversion chaining (Phase 1).
 *
 * Env {@see CHAIN_ENABLED_PAIRS}: comma-separated `from:to` final pairs
 * (e.g. `epub:pdf,odt:html`). Empty default → every chain rejected even when
 * {@see ConversionRegistry::findPath()} finds a path. Curated edges go here
 * before prod enable; do not advertise chains in `/formats`.
 */
final class ChainEnablement
{
    /** @var array<string, true> */
    private array $pairs;

    public function __construct(
        #[Autowire('%env(CHAIN_ENABLED_PAIRS)%')]
        string $enabledPairs = '',
    ) {
        $this->pairs = self::parse($enabledPairs);
    }

    /**
     * Whether the user-facing final pair may be served via a multi-hop chain.
     */
    public function isFinalPairEnabled(string $from, string $to): bool
    {
        return isset($this->pairs[strtolower($from) . ':' . strtolower($to)]);
    }

    /**
     * @return array<string, true>
     */
    private static function parse(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $chunk) {
            $chunk = strtolower(trim($chunk));
            if ($chunk === '' || ! str_contains($chunk, ':')) {
                continue;
            }
            [$from, $to] = array_map(trim(...), explode(':', $chunk, 2));
            if ($from === '' || $to === '') {
                continue;
            }
            $out[$from . ':' . $to] = true;
        }

        return $out;
    }
}
