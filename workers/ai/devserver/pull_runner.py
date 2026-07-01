"""Controllable in-process pull task for the dev-server.

Wraps the shared `_poll_cycle` (the same body prod `worker.run()` uses) in a
start/stop-able asyncio task that updates the in-memory `Stats`. Toggling
`PULL_ENABLED` at runtime starts/stops this task — no process restart.

This NEVER changes prod behaviour: prod calls `worker.run()`/`_poll_loop()` with
no stats sink; this runner is only constructed by the dev-server app.
"""

from __future__ import annotations

import asyncio
import logging
import os
import socket

import httpx

from workers.ai.config import Config
from workers.ai.devserver.stats import Stats
from workers.ai.worker import _poll_cycle

logger = logging.getLogger(__name__)


class PullRunner:
    def __init__(self, cfg: Config, stats: Stats) -> None:
        self._cfg = cfg
        self._stats = stats
        self._task: asyncio.Task | None = None

    @property
    def active(self) -> bool:
        return self._task is not None and not self._task.done()

    def update_cfg(self, cfg: Config) -> None:
        """Swap in a freshly-derived config; picked up on the next claim/job.

        Hot keys (poll interval, LLM inference params) take effect without a
        restart because each cycle reads `self._cfg`. A token change would need
        a restart (token is bound into the client headers) — but the token is a
        secret and not editable from the UI.
        """
        self._cfg = cfg

    async def start(self) -> None:
        if self.active:
            return
        self._stats.on_runner_start()
        self._task = asyncio.create_task(self._loop(), name="pull-runner")

    async def stop(self) -> None:
        task = self._task
        self._task = None
        if task is not None:
            task.cancel()
            try:
                await task
            except asyncio.CancelledError:
                pass
        self._stats.on_runner_stop()

    async def _loop(self) -> None:
        consumer = f"{socket.gethostname()}-{os.getpid()}-devserver"
        auth_headers = {"Authorization": f"Bearer {self._cfg.worker_api_token}"}
        logger.info("dev-server pull runner started (consumer=%s, api=%s)", consumer, self._cfg.api_base)
        try:
            async with httpx.AsyncClient(headers=auth_headers, timeout=httpx.Timeout(30.0)) as client:
                while True:
                    try:
                        handled = await _poll_cycle(client, self._cfg, consumer, self._stats)
                    except asyncio.CancelledError:
                        raise
                    except Exception:
                        logger.exception("pull cycle crashed — continuing")
                        handled = False
                    if not handled:
                        await asyncio.sleep(self._cfg.poll_interval)
        except asyncio.CancelledError:
            logger.info("dev-server pull runner cancelled")
            raise
