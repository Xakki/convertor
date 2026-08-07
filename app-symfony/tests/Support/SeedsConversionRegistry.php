<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Conversion\ConversionRegistry;
use Psr\Log\LoggerInterface;

/**
 * Trait for `PHPUnit\Framework\TestCase` subclasses that need a
 * `ConversionRegistry` with a full, real, non-empty routing matrix.
 *
 * CNV-71-02: the routing matrix no longer comes from `WorkerCapabilityRepository`
 * (DB) — it comes from the committed static catalog
 * `config/catalog/conversion_pairs.json`. `new ConversionRegistry()` with no
 * `$catalogPath` already resolves to that real file by default (see
 * `ConversionRegistry::defaultCatalogPath()`), so this helper no longer needs
 * to stub a repository at all — it's kept as a trait (not a bare
 * `new ConversionRegistry()` at each call site) purely for a single, named,
 * greppable seam if the construction ever needs to change again, and for
 * symmetry with tests that still want a custom `$logger`.
 *
 * The catalog is proven byte-for-byte equivalent in content to the old
 * registry-03 seed fixture this trait used to stub (0 added/removed/mismatched
 * pairs — see `.claude/kanban/progress/CNV-71-02-formats-seo.md`), so every
 * test written against `newSeedRegistry()` keeps seeing the exact same pairs
 * it did before this switch.
 */
trait SeedsConversionRegistry
{
    protected function newSeedRegistry(?LoggerInterface $logger = null): ConversionRegistry
    {
        return new ConversionRegistry(logger: $logger);
    }
}
