<?php

declare(strict_types=1);

namespace App;

use App\Message\FileCleanupMessage;
use App\Message\WorkerCapabilityGcMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Расписание Symfony Scheduler (имя `default` → авто-транспорт `scheduler_default`,
 * который дренит воркер app-cron, см. docker/php/supervisor.app.ini).
 * `stateful` + `processOnlyLastMissedRun` — пропущенные из-за простоя контейнера
 * тики навёрстываются (последний), важно для гарантированной авто-очистки.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Ежечасная авто-очистка устаревших файлов/строк БД (file-cleanup-24h-cron).
            ->add(RecurringMessage::every('1 hour', new FileCleanupMessage()))

            // Ежечасный long-TTL GC мёртвых worker_capabilities строк (registry-06).
            ->add(RecurringMessage::every('1 hour', new WorkerCapabilityGcMessage()))
        ;
    }
}
