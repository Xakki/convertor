<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Trait for `PHPUnit\Framework\TestCase` subclasses that need a
 * `ConversionRegistry` backed by the registry-03 seed data (registry-05:
 * `new ConversionRegistry()` with no repository now yields an EMPTY matrix,
 * not the old hardcoded fallback — see {@see ConversionRegistrySeedFixture}).
 *
 * Uses `$this->createStub()` (protected on TestCase), so this only works as a
 * trait mixed into a TestCase — a plain helper class/static method cannot
 * call it from outside.
 */
trait SeedsConversionRegistry
{
    protected function newSeedRegistry(
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ): ConversionRegistry {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn(ConversionRegistrySeedFixture::capabilities());

        return new ConversionRegistry($repo, $cache, $logger);
    }
}
