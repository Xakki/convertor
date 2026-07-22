<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Тик планировщика long-TTL GC воркер-capability строк (registry-06). Без
 * полезной нагрузки — сигнал «запусти проход GC»; порог хранения сервис
 * берёт из конфига (env WORKER_CAPABILITY_GC_TTL_HOURS). Без routing-map
 * обрабатывается синхронно в воркере, читающем `scheduler_default` (тот же
 * транспорт, что {@see FileCleanupMessage}).
 */
final class WorkerCapabilityGcMessage
{
}
