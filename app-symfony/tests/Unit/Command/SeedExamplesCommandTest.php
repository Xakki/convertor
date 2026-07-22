<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SeedExamplesCommand;
use App\Repository\ConversionRepository;
use App\Repository\ExampleRepository;
use App\Repository\UserRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Examples\ExampleCatalog;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Юнит-тесты валидации аргументов seed-команды (home-04). Инвалидные ветки
 * (--timeout, --only) отсекаются ДО обращения к пайплайну/БД/S3, поэтому все
 * тяжёлые зависимости — моки, реально не вызываемые.
 */
final class SeedExamplesCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $command = new SeedExamplesCommand(
            new ExampleCatalog(),
            $this->createStub(ConversionManager::class),
            $this->createStub(ConversionRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(ExampleRepository::class),
            $this->createStub(EntityManagerInterface::class),
            // S3Storage — final (не мокается), но инвалидные ветки его не
            // трогают: даём реальный инстанс с dummy-клиентом (сети нет).
            new S3Storage(new S3Client(['region' => 'eu-central-1']), 'test'),
            '/app',
        );

        return new CommandTester($command);
    }

    public function testRejectsNonPositiveTimeout(): void
    {
        $tester = $this->tester();
        $tester->execute(['--timeout' => '0']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--timeout', $tester->getDisplay());
    }

    public function testRejectsUnknownCategory(): void
    {
        $tester = $this->tester();
        $tester->execute(['--only' => 'bogus,image']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('bogus', $tester->getDisplay());
    }
}
