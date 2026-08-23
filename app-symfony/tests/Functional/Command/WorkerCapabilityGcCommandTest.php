<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\WorkerCapabilityGcCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Проверяет ручной CLI-путь на изолированной test-БД: отрицательные/нулевые
 * TTL не доходят до GC, а положительный TTL передаётся в тот же сервис.
 */
final class WorkerCapabilityGcCommandTest extends KernelTestCase
{
    private const TEST_WORKER_TYPE = 'gc-command-test-fixture';

    private Connection $conn;

    /** @var list<string> */
    private array $instanceIdsToRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->instanceIdsToRemove as $instanceId) {
            $this->conn->executeStatement(
                'DELETE FROM worker_capabilities WHERE worker_type = :workerType AND instance_id = :instanceId',
                ['workerType' => self::TEST_WORKER_TYPE, 'instanceId' => $instanceId],
            );
        }
        $this->instanceIdsToRemove = [];

        parent::tearDown();
    }

    public function testRejectsNonPositiveTtl(): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--ttl-hours' => '0']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('положительным целым числом', $tester->getDisplay());
    }

    public function testRejectsNonNumericTtl(): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--ttl-hours' => 'one']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('положительным целым числом', $tester->getDisplay());
    }

    public function testUsesConfiguredTtlWhenOptionIsOmitted(): void
    {
        $this->insertRow('command-default-survivor', (new \DateTimeImmutable())->modify('-2 hours'));

        $tester = new CommandTester($this->command());
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($this->rowExists('command-default-survivor'));
        self::assertStringContainsString('TTL из окружения', $tester->getDisplay());
    }

    public function testForwardsTtlToGc(): void
    {
        $this->insertRow('command-stale', (new \DateTimeImmutable())->modify('-2 hours'));
        $this->insertRow('command-fresh', (new \DateTimeImmutable())->modify('-30 minutes'));

        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--ttl-hours' => '1']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($this->rowExists('command-stale'));
        self::assertTrue($this->rowExists('command-fresh'));
        self::assertStringContainsString('TTL: 1 ч', $tester->getDisplay());
    }

    private function command(): WorkerCapabilityGcCommand
    {
        /** @var WorkerCapabilityGcCommand $command */
        $command = static::getContainer()->get(WorkerCapabilityGcCommand::class);

        return $command;
    }

    private function insertRow(string $instanceId, \DateTimeImmutable $lastSeen): void
    {
        $this->conn->executeStatement(
            'INSERT INTO worker_capabilities (worker_type, instance_id, capabilities, last_seen, status) '
            . 'VALUES (:workerType, :instanceId, :capabilities, :lastSeen, :status)',
            [
                'workerType'   => self::TEST_WORKER_TYPE,
                'instanceId'   => $instanceId,
                'capabilities' => json_encode([
                    'workerType'  => self::TEST_WORKER_TYPE,
                    'instanceId'  => $instanceId,
                    'isAi'        => false,
                    'streams'     => [],
                    'routingKeys' => [],
                    'matrix'      => [],
                ], JSON_THROW_ON_ERROR),
                'lastSeen' => $lastSeen->format('Y-m-d H:i:s'),
                'status'   => 'alive',
            ],
        );
        $this->instanceIdsToRemove[] = $instanceId;
    }

    private function rowExists(string $instanceId): bool
    {
        return $this->conn->fetchOne(
            'SELECT id FROM worker_capabilities WHERE worker_type = :workerType AND instance_id = :instanceId',
            ['workerType' => self::TEST_WORKER_TYPE, 'instanceId' => $instanceId],
        ) !== false;
    }
}
