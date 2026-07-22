<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\ConversionRegistrySeedFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards against {@see ConversionRegistrySeedFixture} silently drifting from
 * the registry-03 seed migration it deliberately duplicates (registry-05
 * review finding: the golden/drift tests only catch a REAL DB seed changing —
 * they'd correctly fail and get "fixed" by regenerating the golden fixture,
 * a completely different file, never forcing anyone to look at this one. The
 * 8 test files using `newSeedRegistry()` would then keep passing green
 * against a matrix that no longer reflects production, indefinitely).
 *
 * Reflects directly into `Version20260722150301::seedRows()` (private) and
 * asserts byte-for-byte equality with the fixture's own generated payloads.
 * Reflection into the migration is appropriate HERE (unlike in application
 * code) — the fixture's original reason to duplicate rather than reuse via
 * reflection was to keep the MIGRATION self-contained at runtime (migrations
 * aren't a library API for production code to depend on); a test reaching in
 * to compare data does not touch that runtime contract at all.
 *
 * Migrations are not autoloaded via Composer PSR-4 (doctrine-migrations-bundle
 * resolves them from `migrations_paths` at runtime), so the class file is
 * `require_once`'d explicitly before reflecting into it.
 */
final class ConversionRegistrySeedFixtureSyncTest extends TestCase
{
    private const MIGRATION_CLASS = 'DoctrineMigrations\Version20260722150301';

    private const MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260722150301.php';

    public function testFixtureMatchesMigrationSeedRowsExactly(): void
    {
        if (! class_exists(self::MIGRATION_CLASS, false)) {
            require_once self::MIGRATION_FILE;
        }

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn($this->createStub(AbstractPlatform::class));

        $migrationClass = self::MIGRATION_CLASS;
        /** @var object $migration */
        $migration = new $migrationClass($connection, $this->createStub(LoggerInterface::class));

        $seedRowsMethod = new \ReflectionMethod($migration, 'seedRows');
        /** @var array<string, array<string, mixed>> $migrationRows */
        $migrationRows = $seedRowsMethod->invoke($migration);

        $fixtureRows = [];
        foreach (ConversionRegistrySeedFixture::capabilities() as $capability) {
            $fixtureRows[$capability->getWorkerType()] = $capability->getCapabilities();
        }

        self::assertSame(
            $migrationRows,
            $fixtureRows,
            'ConversionRegistrySeedFixture has drifted from Version20260722150301::seedRows() — '
            . 'update the fixture to match the migration (or vice versa if the fixture change was '
            . 'intentional and the migration needs a NEW version, since migrations are immutable '
            . 'once applied).',
        );
    }
}
