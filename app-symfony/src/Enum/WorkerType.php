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
 *
 * CNV-88: `Browser` — отдельный вид исполнения browser-задач (screenshot/
 * recording через изолированный Chromium runtime), маршрутизируемый в свой
 * стрим `conv.browser` (см. `messenger.yaml` и
 * {@see \App\Service\Conversion\ConversionRegistry} — поле `executionKind`
 * каталога). НЕ путать с `FileCategory` — тот остаётся источником
 * quota/retention (screenshot хранит категорию `image`, recording — `video`);
 * добавление этого case'а НЕ означает, что какая-либо пара сегодня реально
 * маршрутизируется в `browser` — ни один воркер `browser` ещё не
 * зарегистрирован (CNV-82/CNV-90/CNV-91/CNV-113, вне этой карточки), поэтому
 * `WorkerCapabilityRepository::existsForWorkerType('browser')` пока всегда
 * false и `ConversionManager` отвечает `WorkerUnavailableException` (503)
 * раньше, чем задача попала бы в очередь. drift-guard
 * `workers/tests/test_worker_type_drift.py` требует зеркальных правок на
 * Python-стороне (`ALLOWED_WORKER_TYPES` в `ws_client.py`, `WORKER_TYPES` в
 * `keydb.py`) — вне зоны backend-специалиста, см. Execution Log карточки
 * CNV-88.
 */
enum WorkerType: string
{
    case Document = 'document';
    case Image    = 'image';
    case Audio    = 'audio';
    case Video    = 'video';
    case Data     = 'data';
    case Ai       = 'ai';
    case Browser  = 'browser';
}
