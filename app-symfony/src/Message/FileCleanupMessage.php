<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Тик планировщика авто-очистки устаревших файлов/строк БД (задача
 * file-cleanup-24h-cron). Без полезной нагрузки — сигнал «запусти проход
 * очистки»; порог хранения сервис берёт из конфига (env FILE_RETENTION_HOURS).
 * Без routing-map обрабатывается синхронно в воркере, читающем scheduler_default.
 */
final class FileCleanupMessage
{
}
