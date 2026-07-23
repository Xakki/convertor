<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Канонический (единственный источник истины на PHP-стороне) набор типов
 * воркеров / категорий стримов `conv.<type>`. Порядок кейсов = порядок
 * отображения каналов в admin-панели (`/admin/queues`), унаследован от
 * прежнего `QueueStatsProvider::STREAM_TYPES`.
 *
 * Python-сторона (`workers/common/ws_client.py`, `workers/gateway/keydb.py`)
 * и транспорты `config/packages/messenger.yaml` — намеренно НЕЗАВИСИМЫЕ
 * статические whitelist'ы, синхронизацию между ними и этим enum'ом держит
 * drift-guard тест `workers/tests/test_worker_type_drift.py`.
 */
enum WorkerType: string
{
    case Document = 'document';
    case Image    = 'image';
    case Audio    = 'audio';
    case Video    = 'video';
    case Data     = 'data';
    case Ai       = 'ai';
}
