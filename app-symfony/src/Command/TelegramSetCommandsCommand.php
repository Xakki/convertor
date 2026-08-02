<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Auth\TelegramBotClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Регистрирует меню команд Telegram-бота (список «/» в клиенте).
 *
 * Зовётся через make-таргет tg-set-commands (docker-only паттерн, как
 * tg-set-webhook).
 */
#[AsCommand(name: 'app:telegram:set-commands', description: 'Зарегистрировать меню команд Telegram-бота')]
final class TelegramSetCommandsCommand extends Command
{
    /**
     * @var list<array{command: string, description: string}>
     */
    private const COMMANDS = [
        ['command' => 'start', 'description' => 'Начать / войти'],
        ['command' => 'help', 'description' => 'Помощь'],
        ['command' => 'convert', 'description' => 'Конвертировать файл'],
        ['command' => 'balance', 'description' => 'Баланс и ставки pay-per-use'],
        ['command' => 'topup', 'description' => 'Пополнить баланс'],
    ];

    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->botClient->setMyCommands(self::COMMANDS);

        if (($result['ok'] ?? false) === true) {
            $io->success('Меню команд установлено.');

            return Command::SUCCESS;
        }

        $io->error('Не удалось установить меню команд: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

        return Command::FAILURE;
    }
}
