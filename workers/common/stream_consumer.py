"""StreamConsumerBase — WS-транспорт для on-server-воркеров (s1-10).

Подклассы объявляют CAPABILITIES и реализуют convert(). Транспорт (WsClient)
подключается в run() — по образцу workers/ai/worker.py.

Инварианты (grep-ассертируемы):
- Не импортирует и не использует KeyDB (redis/keydb) или S3 (boto3/minio).
- process_job не делает XACK, XADD, HSET — только вызывает convert() и
  возвращает ResultSignal. Никакой self-XACK.
"""

from __future__ import annotations

import asyncio
import logging
import os
import signal
import tempfile
import time
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any

from workers.common.envelope import parse_message
from workers.common.logging_config import configure_logging
from workers.common.ws_client import (
    ProgressReporter,
    ResultSignal,
    WsClient,
    WsClientConfig,
)

logger = logging.getLogger(__name__)

WORK_DIR = Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve()


def _parse_entry(fields: dict) -> dict:
    """Decode a stream entry into a job dict — clean single-JSON (§2/§4).

    Delegates to parse_message: одна json.loads поля message без обёртки.
    Used by tests; not called from production code paths (WsClient owns decode).
    """
    return parse_message(fields)


def _safe_err(exc: Exception, limit: int = 200) -> str:
    return f"{type(exc).__name__}: {str(exc)[:limit]}"


class StreamConsumerBase(ABC):
    """Base class for on-server WS-transport workers.

    Subclasses must set:
        CAPABILITIES = {"routing_keys": [...], "matrix": {...}}
    and implement:
        convert(job) -> (local_output_path: str, output_mime: str, target_ext: str)

    PermanentError contract: raise ValueError for bad format pairs → gateway
    routes to DLQ (permanent=True). All other exceptions → transient retry.
    """

    CAPABILITIES: dict[str, Any] = {}

    def __init__(self) -> None:
        configure_logging()

    @abstractmethod
    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        """Perform the file conversion.

        Returns: (local_output_path, output_mime, target_ext).
        Raise ValueError for permanent errors (bad pair/format).
        Raise any other exception for transient errors.
        """

    async def process_job(self, job: dict, progress: ProgressReporter) -> ResultSignal:
        """Transport-agnostic seam — called by WsClient after input download.

        job["_localInput"] is already populated by WsClient.
        No KeyDB / S3 / XACK here — transport is handled by WsClient.
        """
        job_id = job.get("jobId", "?")
        src_fmt = str(job.get("sourceFormat", "")).lower().lstrip(".")
        tgt_fmt = str(job.get("targetFormat", "")).lower().lstrip(".")

        progress.report(5, "starting")
        started = time.monotonic()
        try:
            local_output, mime, target_ext = await asyncio.to_thread(self.convert, job)
        except ValueError as exc:
            err = _safe_err(exc)
            logger.error("permanent error for job %s: %s", job_id, err)
            return ResultSignal.failed(
                error=err, permanent=True,
                processing_ms=int((time.monotonic() - started) * 1000),
            )
        except FileNotFoundError as exc:
            err = _safe_err(exc)
            logger.error("resource error for job %s: %s", job_id, err)
            return ResultSignal.failed(
                error=err, permanent=False,
                processing_ms=int((time.monotonic() - started) * 1000),
            )
        except Exception as exc:
            err = _safe_err(exc)
            logger.error("conversion failed for job %s: %s", job_id, err)
            return ResultSignal.failed(
                error=err, permanent=False,
                processing_ms=int((time.monotonic() - started) * 1000),
            )

        processing_ms = int((time.monotonic() - started) * 1000)
        progress.report(95, "done")
        logger.info("job %s converted (%s → %s)", job_id, src_fmt, tgt_fmt)
        return ResultSignal.completed(
            path=local_output, mime=mime, ext=target_ext, processing_ms=processing_ms
        )

    def run(self) -> None:
        """WS-транспорт entry point — mirrors workers/ai/worker.py."""
        asyncio.run(self._run_with_signals())

    async def _run_with_signals(self) -> None:
        ws_cfg = WsClientConfig.from_env()
        ws_cfg.validate()
        client = WsClient(ws_cfg, self.process_job, capabilities=self.CAPABILITIES)
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(sig, client.stop)
        logger.info(
            "worker starting — gateway: %s, type: %s",
            ws_cfg.gateway_ws_url,
            ws_cfg.worker_type,
        )
        try:
            await client.run()
        finally:
            for sig in (signal.SIGTERM, signal.SIGINT):
                loop.remove_signal_handler(sig)
