<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FileCleanupMessage;
use App\Service\Storage\FileCleanupService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик тика планировщика авто-очистки: делегирует один проход
 * {@see FileCleanupService::run()}. Запускается воркером scheduler_default
 * (см. docker/php/supervisor.app.ini, [program:app-cron]).
 */
#[AsMessageHandler]
final class FileCleanupMessageHandler
{
    public function __construct(
        private readonly FileCleanupService $cleanup,
    ) {
    }

    public function __invoke(FileCleanupMessage $message): void
    {
        $this->cleanup->run();
    }
}
