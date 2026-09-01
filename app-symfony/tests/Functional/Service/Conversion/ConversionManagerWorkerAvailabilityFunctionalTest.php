<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\Entity\User;
use App\Exception\WorkerUnavailableException;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\ConversionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * CNV-71-03 review fix (Fix 3): proves the "no worker row → 503" refusal
 * against a REAL empty SQL result, not a stubbed repository.
 *
 * {@see \App\Tests\Unit\Service\Conversion\ConversionManagerWorkerAvailabilityTest}
 * covers this gate only at unit level with a stub/empty
 * `WorkerCapabilityRepository`. Before CNV-71-04 this functional test had to
 * work around a `__seed__` row that existed for EVERY workerType
 * (registry-03) by deleting real rows inside a rolled-back DBAL transaction
 * to reach a genuinely empty table. CNV-71-04 removed the seed rows and all
 * their special-casing, so the table is genuinely empty by default now — the
 * gate is reachable directly, no workaround needed.
 *
 * Still asserts against a REAL `EntityManagerInterface`-backed connection
 * (DELETE + rollback in `finally`) rather than trusting the ambient empty
 * state, so the test remains self-contained and safe even if some other test
 * or a real worker registration left rows behind.
 *
 * Deliberately asserted at the SERVICE level, not via a `WebTestCase` HTTP
 * request: the gate fires in `ConversionManager::createSingleHop()` BEFORE
 * any size/quota/S3 side-effect, so no additional mocking is needed at this
 * layer, and the `WorkerUnavailableException` → HTTP 503 `worker_unavailable`
 * mapping itself is a plain one-line catch/json in
 * {@see \App\Controller\Api\ConversionController::convert()} (~line 199) —
 * trivial enough that it doesn't need its own functional test here.
 */
final class ConversionManagerWorkerAvailabilityFunctionalTest extends KernelTestCase
{
    private const string WORKER_TYPE = 'image';

    public function testNoWorkerRowAgainstRealEmptyTableRejectsWithWorkerUnavailable(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em   = $container->get(EntityManagerInterface::class);
        $repo = $container->get(WorkerCapabilityRepository::class);
        $conn = $em->getConnection();

        $conn->beginTransaction();

        try {
            // Belt-and-braces DELETE (rolled back below) — makes the test
            // self-contained regardless of what other rows might already be
            // in the table, without relying on the ambient "table starts
            // empty" default.
            $conn->executeStatement(
                'DELETE FROM worker_capabilities WHERE worker_type = :workerType',
                ['workerType' => self::WORKER_TYPE],
            );

            // The repository issues a fresh, uncached `SELECT ... LIMIT 1` per
            // call (raw DBAL fetchOne, no result cache) — the DELETE above is
            // visible to it inside the SAME transaction/connection.
            self::assertFalse(
                $repo->existsForWorkerType(self::WORKER_TYPE),
                'existsForWorkerType() must observe the real empty result, not a stale/cached true',
            );

            $manager = $container->get(ConversionManager::class);

            $this->expectException(WorkerUnavailableException::class);
            $this->expectExceptionMessage('Конвертация временно недоступна');

            $manager->createConversion(new ConversionRequestDTO(
                new User(),
                $this->makeJpgUpload(),
                'png',
                false,
                true,
            ));
        } finally {
            // Roll back regardless of outcome — the deleted worker_capabilities
            // rows (and anything createConversion() might otherwise have
            // written, though the gate here fires before any write) must never
            // reach the test DB permanently.
            $conn->rollBack();
        }
    }

    public function testStaleApiCapabilityRejectsApiJob(): void
    {
        $this->assertApiJobRejected([
            'executionKind' => 'api',
            'routingKeys'   => ['api'],
            'streams'       => ['api'],
            'settings'      => ['model' => [
                'default' => 'fast',
                'choices' => [['value' => 'fast', 'label' => 'Fast']],
            ]],
        ], stale: true);
    }

    public function testMissingApiCapabilityRejectsApiJob(): void
    {
        $this->assertApiJobRejected(null);
    }

    /** @param array<string, mixed> $capabilities */
    #[DataProvider('invalidApiCapabilityProvider')]
    public function testFreshApiCapabilityViolatingCompleteContractRejectsApiJob(array $capabilities): void
    {
        $this->assertApiJobRejected($capabilities);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidApiCapabilityProvider(): iterable
    {
        $valid = self::completeApiCapabilities();

        yield 'executionKind' => [array_diff_key($valid, ['executionKind' => true])];
        yield 'routingKeys' => [array_replace($valid, ['routingKeys' => ['api', 'ai']])];
        yield 'streams' => [array_replace($valid, ['streams' => ['ai']])];
        yield 'settings model' => [array_replace($valid, ['settings' => []])];
    }

    /** @param array<string, mixed>|null $capabilities */
    private function assertApiJobRejected(?array $capabilities, bool $stale = false): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $repo      = $container->get(WorkerCapabilityRepository::class);
        $conn      = $em->getConnection();
        $conn->beginTransaction();

        try {
            $conn->executeStatement("DELETE FROM worker_capabilities WHERE worker_type = 'api'");
            $capability = $capabilities !== null
                ? $repo->upsert('api', 'cnv-27-functional', $capabilities)
                : null;
            if ($stale && $capability !== null) {
                $conn->executeStatement(
                    'UPDATE worker_capabilities SET last_seen = :lastSeen WHERE id = :id',
                    ['lastSeen' => '2000-01-01 00:00:00', 'id' => $capability->getId()],
                );
            }

            $this->expectException(WorkerUnavailableException::class);
            $this->expectExceptionMessage('Конвертация временно недоступна');

            $container->get(ConversionManager::class)->createConversion(new ConversionRequestDTO(
                new User(),
                $this->makeTxtUpload(),
                'json',
                false,
                true,
            ));
        } finally {
            $conn->rollBack();
        }
    }

    private function makeJpgUpload(): UploadedFile
    {
        $bytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $path  = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'sample.jpg', null, null, true);
    }

    private function makeTxtUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, 'hello');

        return new UploadedFile($path, 'prompt.txt', 'text/plain', null, true);
    }

    /** @return array<string, mixed> */
    private static function completeApiCapabilities(): array
    {
        return [
            'executionKind' => 'api',
            'routingKeys'   => ['api'],
            'streams'       => ['api'],
            'settings'      => ['model' => [
                'default' => 'fast',
                'choices' => [['value' => 'fast', 'label' => 'Fast']],
            ]],
        ];
    }
}
