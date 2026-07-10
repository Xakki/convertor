<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Auth\TelegramBotClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Регистрирует webhook Telegram-бота (URL + secret_token).
 *
 * URL передаётся аргументом (base-URL приложения не живёт в Symfony-env — его
 * знает Makefile из корневого .env). Секрет берётся из TELEGRAM_WEBHOOK_SECRET.
 * Зовётся через make-таргет tg-set-webhook (docker-only паттерн).
 */
#[AsCommand(name: 'app:telegram:set-webhook', description: 'Зарегистрировать webhook Telegram-бота')]
final class TelegramSetWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramBotClient $botClient,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'base-url',
            InputArgument::REQUIRED,
            'Публичный base-URL приложения, напр. https://convertor.xakki.pro',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (trim($this->webhookSecret) === '') {
            $io->error('TELEGRAM_WEBHOOK_SECRET пуст — задайте его в .env.local.');

            return Command::FAILURE;
        }

        $baseUrl = rtrim((string) $input->getArgument('base-url'), '/');
        $url     = $baseUrl . '/api/v1/telegram/webhook';

        $result = $this->botClient->setWebhook($url, $this->webhookSecret);

        if (($result['ok'] ?? false) === true) {
            $io->success('Webhook установлен: ' . $url);

            return Command::SUCCESS;
        }

        $io->error('Не удалось установить webhook: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

        return Command::FAILURE;
    }
}
