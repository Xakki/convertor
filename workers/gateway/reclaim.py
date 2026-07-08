"""Глобальный idle-reclaim цикл WS-Gateway (s1-06, §6.3).

Периодически (RECLAIM_INTERVAL_S) обходит все conv.<type>-стримы, вычленяет
записи из PEL, простаивающие дольше per-type idle-порога (RECLAIM_IDLE_MS_<TYPE>),
и передаёт их в per-type handoff-очередь WsGateway — кредитный цикл ближайшего
свободного соединения подхватит и диспетчеризует через обычный путь _push_job.

Триггер — ТОЛЬКО idle-timeout. Никакого reclaim по WS-дисконнекту (§6.3, guard:
test_no_reclaim_triggered_by_ws_disconnect).

KNOWN GAP: silent-crash poison-job, который никогда не шлёт `fail`, ловится
ТОЛЬКО этим idle-reclaim (крутится в PEL до idle-порога → XAUTOCLAIM → re-dispatch).
"""
from __future__ import annotations

import asyncio
import logging

from workers.gateway.config import Config
from workers.gateway.keydb import WORKER_TYPES, KeyDbGateway, stream_for

logger = logging.getLogger(__name__)

# Consumer-имя в PEL для записей, переклеймленных idle-reclaim.
# Не совпадает ни с одним workerId реального воркера.
RECLAIM_CONSUMER = "gw-reclaim"


async def run_reclaim_loop(
    keydb: KeyDbGateway,
    cfg: Config,
    handoff: dict[str, asyncio.Queue],
) -> None:
    """Async-задача: периодический idle-reclaim для всех типов воркеров.

    Запускается через `asyncio.create_task` из `__main__.py` рядом с WS-сервером.
    Завершается по `CancelledError` (graceful shutdown).

    handoff — `dict[workerType, asyncio.Queue[(job_id, job)]]`; предоставляется
    WsGateway и читается в `_dispatch` перед `reclaim_stale`/`read_new`.
    """
    logger.info(
        "reclaim loop started",
        extra={"intervalS": cfg.reclaim_interval_s, "types": list(WORKER_TYPES)},
    )
    while True:
        try:
            await asyncio.sleep(cfg.reclaim_interval_s)
        except asyncio.CancelledError:
            logger.info("reclaim loop cancelled")
            return

        try:
            await _sweep_all_types(keydb, cfg, handoff)
        except asyncio.CancelledError:
            logger.info("reclaim loop cancelled during sweep")
            return
        except Exception as exc:
            # Транзиентная ошибка не роняет цикл — следующий тик исправит.
            logger.warning("reclaim sweep error", extra={"error": str(exc)})


async def _sweep_all_types(
    keydb: KeyDbGateway,
    cfg: Config,
    handoff: dict[str, asyncio.Queue],
) -> None:
    """Один проход: XAUTOCLAIM по всем типам → set_status_processing → handoff."""
    for wtype in WORKER_TYPES:
        stream = stream_for(wtype)
        min_idle_ms = cfg.reclaim_idle_ms_for(wtype)

        entries = await keydb.reclaim_idle(
            stream, RECLAIM_CONSUMER, min_idle_ms, count=cfg.reclaim_batch
        )
        if not entries:
            continue

        queue = handoff.get(wtype)
        if queue is None:
            logger.warning(
                "no handoff queue for worker type — entries deferred, will be re-swept after idle",
                extra={"workerType": wtype, "count": len(entries)},
            )
            continue

        for job_id, job in entries:
            # Записываем conv:status={processing} ДО handoff: воркер может
            # запросить input сразу после получения job-фрейма.
            await keydb.set_status_processing(job_id, job)
            await queue.put((job_id, job))
            logger.info(
                "idle entry reclaimed → handoff",
                extra={
                    "workerType": wtype,
                    "jobId": job_id,
                    "minIdleMs": min_idle_ms,
                },
            )
