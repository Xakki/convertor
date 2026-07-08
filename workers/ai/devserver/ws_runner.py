"""Управляемый in-process WS-runner для dev-сервера (s1-09).

Запускает WsClient с handle_job AI-воркера в фоновой задаче. Поддерживает
start()/stop() — тот же интерфейс, что был у PullRunner, чтобы routes_settings.py
мог горячо рестартить runner при необходимости.

update_cfg() сохраняет новый Config; он подхватывается на следующей задаче
(handle_job строится с актуальным self._cfg на каждый вызов — hot-swap LLM-параметров).
"""

from __future__ import annotations

import asyncio
import logging

from workers.ai.config import Config
from workers.ai.devserver.stats import Stats

logger = logging.getLogger(__name__)


class WsRunner:
    def __init__(self, cfg: Config, stats: Stats) -> None:
        self._cfg = cfg
        self._stats = stats
        self._task: asyncio.Task | None = None

    @property
    def active(self) -> bool:
        return self._task is not None and not self._task.done()

    def update_cfg(self, cfg: Config) -> None:
        """Сохранить новый Config; подхватывается при следующей задаче."""
        self._cfg = cfg

    async def start(self) -> None:
        if self.active:
            return
        self._task = asyncio.create_task(self._run(), name="ws-runner")

    async def stop(self) -> None:
        task = self._task
        self._task = None
        if task is not None:
            task.cancel()
            try:
                await task
            except Exception:  # CancelledError + любой ValueError от bad-env before try/finally
                pass
            # on_disconnected() вызывается в _run().finally; лишний вызов здесь убран

    async def _run(self) -> None:
        stats = self._stats
        try:
            from workers.ai.worker import build_handle_job
            from workers.common.ws_client import WsClient, WsClientConfig

            ws_cfg = WsClientConfig.from_env(work_dir=self._cfg.work_dir)
            runner = self  # ссылка для чтения актуального cfg на каждую задачу

            async def tracked_handle_job(job, progress):
                # Строить handle_job с текущим cfg — hot-ключи (LLM_MAX_TOKENS etc.) работают.
                stats.on_job_start()
                try:
                    handler = build_handle_job(runner._cfg)
                    return await handler(job, progress)
                finally:
                    stats.on_job_done()

            def on_pong():
                # connected=True только после первого pong (реальный live-traffic, #1).
                stats.on_connected()
                stats.on_pong()

            client = WsClient(
                ws_cfg,
                tracked_handle_job,
                on_pong=on_pong,
                on_reconnect_start=stats.on_disconnected,
            )
            await client.run()
        except asyncio.CancelledError:
            raise
        except Exception:
            logger.exception("ws-runner crashed")
        finally:
            stats.on_disconnected()
