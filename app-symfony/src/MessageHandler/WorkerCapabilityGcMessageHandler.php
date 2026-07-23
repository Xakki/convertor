<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\WorkerCapabilityGcMessage;
use App\Service\Worker\WorkerCapabilityGcService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик тика планировщика long-TTL GC: делегирует один проход
 * {@see WorkerCapabilityGcService::run()}. Запускается воркером
 * scheduler_default (см. docker/php/supervisor.app.ini, [program:app-cron]).
 */
#[AsMessageHandler]
final class WorkerCapabilityGcMessageHandler
{
    public function __construct(
        private readonly WorkerCapabilityGcService $gc,
    ) {
    }

    public function __invoke(WorkerCapabilityGcMessage $message): void
    {
        $this->gc->run();
    }
}
