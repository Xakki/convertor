"""Base worker class for all convertor queue workers."""

import asyncio
import json
import logging
import os
import signal
import threading
from abc import ABC, abstractmethod
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

from workers.common.keydb_client import QueueClient

logger = logging.getLogger(__name__)

REDIS_HOST = os.getenv("REDIS_HOST", "localhost")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "0"))

CALLBACK_TIMEOUT = int(os.getenv("CALLBACK_TIMEOUT", "10"))
HEALTH_PORT = int(os.getenv("HEALTH_PORT", "6001"))

SHARE_DIR = Path(os.getenv("SHARE_DIR", "/shared-files")).resolve()

_CALLBACK_RETRY_TOTAL = 3
_CALLBACK_BACKOFF = 1.0  # seconds, exponential


class BaseWorker(ABC):
    """Abstract base for all queue workers.

    Subclasses must implement :meth:`process_task`.
    """

    queue_name: str  # override in subclass

    def __init__(self, queue_name: str | None = None) -> None:
        self._queue_name: str = queue_name or self.__class__.queue_name
        self._client = QueueClient(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB)
        self._running = True
        self._loop: asyncio.AbstractEventLoop | None = None
        self._http_session = self._build_http_session()
        logger.info(
            "worker init: queue=%s redis=%s:%s/%s",
            self._queue_name, REDIS_HOST, REDIS_PORT, REDIS_DB,
        )

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def run(self) -> None:
        """Start the worker: health server + main async loop."""
        self._setup_signals()
        health_thread = self._start_health_server()
        try:
            asyncio.run(self._main_loop())
        finally:
            health_thread.daemon = True  # let it die with the process

    # ------------------------------------------------------------------
    # Abstract
    # ------------------------------------------------------------------

    @abstractmethod
    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        """Convert the file described by *task*.

        Must return a dict with at least ``{"status": "ok", "output_path": str}``.
        On failure raise an exception — base class handles error reporting.
        """

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    async def _main_loop(self) -> None:
        self._loop = asyncio.get_running_loop()
        logger.info("worker started, listening on queue '%s'", self._queue_name)
        while self._running:
            task = await asyncio.to_thread(
                self._client.pop, self._queue_name, 5
            )
            if task is None:
                continue
            task_id = task.get("id", "<unknown>")
            logger.info("task received: id=%s", task_id)
            result: dict[str, Any]
            try:
                result = await self.process_task(task)
                result.setdefault("status", "ok")
            except Exception as exc:
                logger.exception("task failed: id=%s error=%s", task_id, exc)
                result = {"status": "error", "error": str(exc)}
            await asyncio.to_thread(self._callback, task, result)
            await asyncio.to_thread(self._ack, task)

    def _callback(self, task: dict[str, Any], result: dict[str, Any]) -> None:
        """Send HTTP POST/PATCH to callback_url with the conversion result."""
        callback_url: str | None = task.get("callback_url")
        if not callback_url:
            logger.debug("no callback_url for task id=%s", task.get("id"))
            return

        payload: dict[str, Any] = {
            "id": task.get("id"),
            "status": result.get("status", "error"),
            "output_path": result.get("output_path"),
            "error": result.get("error"),
        }

        for attempt in range(1, _CALLBACK_RETRY_TOTAL + 1):
            try:
                resp = self._http_session.patch(
                    callback_url,
                    json=payload,
                    timeout=CALLBACK_TIMEOUT,
                )
                resp.raise_for_status()
                logger.info(
                    "callback ok: id=%s status=%s", task.get("id"), resp.status_code
                )
                return
            except Exception as exc:
                wait = _CALLBACK_BACKOFF * (2 ** (attempt - 1))
                logger.warning(
                    "callback attempt %d/%d failed: id=%s error=%s, retry in %.1fs",
                    attempt, _CALLBACK_RETRY_TOTAL, task.get("id"), exc, wait,
                )
                if attempt < _CALLBACK_RETRY_TOTAL:
                    import time
                    time.sleep(wait)
        logger.error(
            "callback gave up after %d attempts: id=%s url=%s",
            _CALLBACK_RETRY_TOTAL, task.get("id"), callback_url,
        )

    def _ack(self, task: dict[str, Any]) -> None:
        """Remove task from the processing queue."""
        self._client.ack(self._queue_name, task)
        logger.debug("ack task id=%s", task.get("id"))

    # ------------------------------------------------------------------
    # Signal handling
    # ------------------------------------------------------------------

    def _setup_signals(self) -> None:
        signal.signal(signal.SIGTERM, self._handle_shutdown)
        signal.signal(signal.SIGINT, self._handle_shutdown)

    def _handle_shutdown(self, signum: int, frame: Any) -> None:
        logger.info("received signal %s, shutting down gracefully", signum)
        self._running = False

    # ------------------------------------------------------------------
    # Health check HTTP server
    # ------------------------------------------------------------------

    def _start_health_server(self) -> threading.Thread:
        worker_ref = self

        class HealthHandler(BaseHTTPRequestHandler):
            def do_GET(self) -> None:  # noqa: N802
                if self.path == "/health":
                    body = json.dumps(
                        {
                            "status": "ok",
                            "queue": worker_ref._queue_name,
                            "pending": worker_ref._client.queue_length(
                                worker_ref._queue_name
                            ),
                        }
                    ).encode()
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                else:
                    self.send_response(404)
                    self.end_headers()

            def log_message(self, fmt: str, *args: Any) -> None:  # type: ignore[override]
                pass  # suppress default access log noise

        server = HTTPServer(("0.0.0.0", HEALTH_PORT), HealthHandler)
        t = threading.Thread(target=server.serve_forever, daemon=True)
        t.start()
        logger.info("health endpoint listening on :%d/health", HEALTH_PORT)
        return t

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _build_http_session() -> requests.Session:
        session = requests.Session()
        retry = Retry(
            total=0,  # we do our own retry loop in _callback
            raise_on_status=False,
        )
        adapter = HTTPAdapter(max_retries=retry)
        session.mount("http://", adapter)
        session.mount("https://", adapter)
        return session
