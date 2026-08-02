<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\TelegramSetCommandsCommand;
use App\Service\Auth\TelegramBotClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Юнит-тест меню BotCommands: balance/topup должны попадать в setMyCommands (CNV-58).
 */
final class TelegramSetCommandsCommandTest extends TestCase
{
    public function testSetMyCommandsIncludesBalanceAndTopup(): void
    {
        $bot = $this->createMock(TelegramBotClient::class);
        $bot->expects(self::once())
            ->method('setMyCommands')
            ->with(self::callback(static function (array $commands): bool {
                $names = array_column($commands, 'command');

                return in_array('balance', $names, true)
                    && in_array('topup', $names, true)
                    && in_array('start', $names, true)
                    && in_array('help', $names, true)
                    && in_array('convert', $names, true);
            }))
            ->willReturn(['ok' => true]);

        $tester = new CommandTester(new TelegramSetCommandsCommand($bot));
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Меню команд установлено', $tester->getDisplay());
    }
}
