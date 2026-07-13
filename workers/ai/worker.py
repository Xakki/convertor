"""AI worker — WS-транспорт (s1-09).

Транспорт: общий WS-клиент `workers/common/ws_client.py`.
Обработка задачи: seam `handle_job` — принимает job-словарь (с уже заполненным
ws_client'ом `job["_localInput"]`) + ProgressReporter, запускает `convert()`,
возвращает ResultSignal. Вход и выход здесь НЕ удаляются — за это отвечает ws_client.

Инварианты (grep-ассертируемы):
- Не импортирует S3 (boto3/botocore/minio) или KeyDB (redis/keydb).
- Не делает HTTP-запросов к pull-API (нет PullApiClient, нет claim/upload_result/fail).
"""

from __future__ import annotations

import asyncio
import logging
import signal
import time
from typing import Any

from workers.ai.config import Config, load_config
from workers.ai.convert import convert
from workers.common.ws_client import (
    ProgressReporter,
    ResultSignal,
    WsClient,
    WsClientConfig,
)

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# CAPABILITIES — читается тестом routing-drift и PHP register-эндпоинтом.
# AI-воркер потребляет единственный PHP-stream `ai`. Embedding / STT / TTS —
# субповедения, выводимые из пары форматов ВНУТРИ этого воркера.
# matrix_categories: from-формат → "audio"|"document" (используется PHP-реестром
# для выбора FileCategory при DB-пути buildMatrixFromCapabilities).
# ---------------------------------------------------------------------------

CAPABILITIES: dict[str, Any] = {
    "routing_keys": ["ai"],
    "matrix": {
        # STT: audio → text
        "mp3":  ["txt", "srt", "vtt"],
        "wav":  ["txt", "srt", "vtt"],
        "ogg":  ["txt", "srt", "vtt"],
        "m4a":  ["txt", "srt", "vtt"],
        "opus": ["txt", "srt", "vtt"],
        "flac": ["txt", "srt", "vtt"],
        # TTS: text → audio; txt также → json (embedding)
        "txt":  ["mp3", "wav", "ogg", "json"],
        "md":   ["mp3", "wav", "ogg"],
    },
    "matrix_categories": {
        "mp3":  "audio",
        "wav":  "audio",
        "ogg":  "audio",
        "m4a":  "audio",
        "opus": "audio",
        "flac": "audio",
        "txt":  "document",
        "md":   "document",
    },
}


def _safe_err(exc: Exception, limit: int = 200) -> str:
    """Ограниченная строка ошибки, безопасная для лога и хранения."""
    return f"{type(exc).__name__}: {str(exc)[:limit]}"


# ---------------------------------------------------------------------------
# handle_job seam
# ---------------------------------------------------------------------------

def build_handle_job(cfg: Config):
    """Собрать корутину handle_job, замкнутую на AI-конфиг.

    Вызывать ПЕРЕД каждой задачей, чтобы подхватить свежий cfg после update_cfg().
    """

    async def handle_job(job: dict, progress: ProgressReporter) -> ResultSignal:
        """ws_client уже скачал вход и положил путь в job["_localInput"].

        Только конвертация: не удалять input или output — это делает ws_client.
        """
        job_id = job.get("jobId", "?")
        conv_id = job.get("conversionId")
        src_fmt = str(job.get("sourceFormat", "")).lower().lstrip(".")
        tgt_fmt = str(job.get("targetFormat", "")).lower().lstrip(".")
        local_input = job.get("_localInput", "")

        payload: dict[str, Any] = {
            "_localInput": local_input,
            "_jobDir": job.get("_jobDir"),
            "conversionId": conv_id,
            "sourceFormat": src_fmt,
            "targetFormat": tgt_fmt,
            "model": job.get("model"),
        }

        progress.report(5, "starting")
        started = time.monotonic()
        try:
            out_str, mime, target_ext = await convert(payload, cfg)
        except ValueError as exc:
            # Неверная пара форматов — постоянная ошибка (не зависит от ресурсов воркера)
            err = _safe_err(exc)
            logger.error("conversion permanent error for job %s: %s", job_id, err)
            return ResultSignal.failed(error=err, permanent=True)
        except FileNotFoundError as exc:
            # Пропавший бинарник/модель — ресурсная проблема воркера, не дефект задачи
            err = _safe_err(exc)
            logger.error("conversion resource error for job %s: %s", job_id, err)
            return ResultSignal.failed(error=err, permanent=False)
        except Exception as exc:
            err = _safe_err(exc)
            logger.error("conversion failed for job %s: %s", job_id, err)
            return ResultSignal.failed(error=err, permanent=False)

        processing_ms = int((time.monotonic() - started) * 1000)
        progress.report(95, "done")
        logger.info("job %s converted (%s → %s)", job_id, src_fmt, tgt_fmt)
        return ResultSignal.completed(
            path=out_str, mime=mime, ext=target_ext, processing_ms=processing_ms
        )

    return handle_job


# ---------------------------------------------------------------------------
# Worker entry point
# ---------------------------------------------------------------------------

async def _run_with_signals(cfg: Config, *, http_client=None) -> None:
    """Async body: строит WsClient, регистрирует SIGTERM/SIGINT → stop(), запускает цикл."""
    ws_cfg = WsClientConfig.from_env(work_dir=cfg.work_dir)
    client = WsClient(ws_cfg, build_handle_job(cfg), http_client=http_client, capabilities=CAPABILITIES)
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, client.stop)
    logger.info(
        "AI worker starting — gateway: %s, type: %s, whisper: %s/%s/%s",
        ws_cfg.gateway_ws_url, ws_cfg.worker_type,
        cfg.whisper_model, cfg.whisper_device, cfg.whisper_compute_type,
    )
    try:
        await client.run()
    finally:
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.remove_signal_handler(sig)


def run(cfg: Config | None = None) -> None:
    """Worker-mode entry point. Строит WsClient из окружения и запускает reconnect-цикл."""
    if cfg is None:
        cfg = load_config()
    cfg.validate()
    asyncio.run(_run_with_signals(cfg))


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    run()
