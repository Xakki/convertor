"""AI worker: HTTP pull-API client for Speech-to-Text / Text-to-Speech.

Polls the universal worker pull-API for jobs (~10 s interval), downloads the
input file through the API, runs STT or TTS conversion, and uploads the result.

No direct KeyDB or S3 access — the API acts as a gateway.

Config env vars:
  API_BASE_URL         base URL of the convertor pull-API (e.g. https://convertor.xakki.pro)
  WORKER_API_TOKEN     bearer token for all API requests
  WORKER_TYPE          worker type to claim (default "ai")
  POLL_INTERVAL        seconds to sleep between polls when queue is empty (default 10)
  WHISPER_MODEL        faster-whisper model name (default "base")
  WHISPER_DEVICE       faster-whisper device: "cpu" or "cuda" (default "cpu")
  WHISPER_COMPUTE_TYPE faster-whisper compute type (default "int8")
  TTS_ENGINE           local TTS engine: "espeak" or "pyttsx3" (default "espeak")
  AI_STT_PROVIDER      STT provider: local|openai|gemini|claude (default "local")
  AI_TTS_PROVIDER      TTS provider: local|openai (default "local")
  OPENAI_API_KEY / GEMINI_API_KEY / CLAUDE_API_KEY  provider credentials
  WORK_DIR             writable directory for intermediate temp files
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import signal
import socket
import subprocess
import tempfile
import uuid
from pathlib import Path
from typing import Any
import json
import httpx
from workers.ai.providers.embedding import generate_embedding
from workers.ai.providers.streaming_stt import StreamingWhisper
from workers.ai.providers.stt import SpeechToTextProvider
from workers.ai.providers.tts import TextToSpeechProvider

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

API_BASE_URL = os.getenv("API_BASE_URL", "http://localhost:8080")
WORKER_API_TOKEN = os.getenv("WORKER_API_TOKEN", "")
WORKER_TYPE = os.getenv("WORKER_TYPE", "ai")
POLL_INTERVAL = int(os.getenv("POLL_INTERVAL", "10"))

# Computed once at startup: all API requests use f"{_api_base}/api/v1/..."
# API_BASE_URL must be the application root (scheme+host only, no /api path suffix).
# httpx drops path prefixes when request paths are absolute — building full URLs avoids this.
_api_base: str = API_BASE_URL.rstrip("/")

WORK_DIR = Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve()

EMBEDDING_MODEL = os.getenv(
    "EMBEDDING_MODEL",
    "BAAI/bge-m3",
)

EMBEDDING_DEVICE = os.getenv(
    "EMBEDDING_DEVICE",
    WHISPER_DEVICE,
)

STREAM_WINDOW_SEC = int(
    os.getenv(
        "STREAM_WINDOW_SEC",
        "20",
    )
)

STREAM_OVERLAP_SEC = int(
    os.getenv(
        "STREAM_OVERLAP_SEC",
        "2",
    )
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _safe_err(exc: Exception, limit: int = 200) -> str:
    """Bounded error string safe to log and store.

    Provider error responses can echo back user input (content-policy, text-too-long).
    Capping at `limit` chars keeps those payloads out of logs and the DB error column.
    """
    return f"{type(exc).__name__}: {str(exc)[:limit]}"


# ---------------------------------------------------------------------------
# CAPABILITIES — read by the routing-drift test (keeps ai stream registered)
# ---------------------------------------------------------------------------

CAPABILITIES: dict[str, Any] = {
    "routing_keys": [
        "ai",
        "embedding",
        "speech_to_text_stream",
    ],
    "matrix": {
        "speech_to_text": True,
        "speech_to_text_stream": True,
        "text_to_speech": True,
        "embedding": True,
    },
}

# ---------------------------------------------------------------------------
# Format sets
# ---------------------------------------------------------------------------

_STT_INPUTS: set[str] = {"mp3", "wav", "ogg", "m4a", "opus", "flac"}
_STT_OUTPUTS: set[str] = {"txt", "srt", "vtt"}
_TTS_INPUTS: set[str] = {"txt", "md"}
_TTS_OUTPUTS: set[str] = {"mp3", "wav", "ogg"}

_MIME: dict[str, str] = {
    "txt": "text/plain",
    "srt": "application/x-subrip",
    "vtt": "text/vtt",
    "mp3": "audio/mpeg",
    "wav": "audio/wav",
    "ogg": "audio/ogg",
}

# ---------------------------------------------------------------------------
# Mode derivation (flag-agnostic)
# ---------------------------------------------------------------------------


def _derive_mode(src_fmt: str, tgt_fmt: str) -> str:
    """Derive STT/TTS conversion mode from format pair only. Raises ValueError if underivable."""
    if src_fmt in _STT_INPUTS and tgt_fmt in _STT_OUTPUTS:
        return "stt"
    if src_fmt in _TTS_INPUTS and tgt_fmt in _TTS_OUTPUTS:
        return "tts"
    raise ValueError(
        f"cannot derive conversion mode for {src_fmt!r} → {tgt_fmt!r}: "
        f"not a valid STT pair ({_STT_INPUTS} → {_STT_OUTPUTS}) "
        f"nor TTS pair ({_TTS_INPUTS} → {_TTS_OUTPUTS})"
    )


# ---------------------------------------------------------------------------
# EMBEDDING
# ---------------------------------------------------------------------------


async def _embedding(
    src: Path,
    out_path: Path,
    model_name: str,
) -> None:

    await asyncio.to_thread(
        generate_embedding,
        src,
        out_path,
        model_name,
        EMBEDDING_DEVICE,
    )


# ---------------------------------------------------------------------------
# STREAMING STT
# ---------------------------------------------------------------------------


async def _speech_to_text_stream(
    src: Path,
    out_path: Path,
) -> None:

    def _run():

        model = StreamingWhisper(
            model_name=WHISPER_MODEL,
            device=WHISPER_DEVICE,
            compute_type=WHISPER_COMPUTE_TYPE,
            window_sec=STREAM_WINDOW_SEC,
            overlap_sec=STREAM_OVERLAP_SEC,
        )

        return model.process_file(src)

    result = await asyncio.to_thread(
        _run,
    )

    out_path.write_text(
        json.dumps(
            result,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

# ---------------------------------------------------------------------------
# Core convert (async; no subType — mode derived from format pair only)
# ---------------------------------------------------------------------------

async def convert(
    job: dict[str, Any],
) -> tuple[str, str, str]:

    src = Path(job["_localInput"])

    src_fmt = str(
        job["sourceFormat"]
    ).lower().lstrip(".")

    tgt_fmt = str(
        job["targetFormat"]
    ).lower().lstrip(".")

    conv_id = job["conversionId"]

    task_type = job.get(
        "taskType",
    )

    if not src.exists():
        raise FileNotFoundError(
            src,
        )

    if task_type is None:
        mode = _derive_mode(
            src_fmt,
            tgt_fmt,
        )
    else:
        mode = task_type

    WORK_DIR.mkdir(
        parents=True,
        exist_ok=True,
    )

    if mode == "embedding":
        tgt_fmt = "json"

    if mode == "speech_to_text_stream":
        tgt_fmt = "json"

    out_path = (
        WORK_DIR
        / f"out-{conv_id}-{uuid.uuid4().hex}.{tgt_fmt}"
    )

    if mode == "speech_to_text":
        await _speech_to_text(
            src,
            tgt_fmt,
            out_path,
        )

    elif mode == "speech_to_text_stream":
        await _speech_to_text_stream(
            src,
            out_path,
        )

    elif mode == "text_to_speech":
        await _text_to_speech(
            src,
            tgt_fmt,
            out_path,
        )

    elif mode == "embedding":

        model_name = (
            job.get("model")
            or EMBEDDING_MODEL
        )

        await _embedding(
            src,
            out_path,
            model_name,
        )

    elif mode == "stt":
        await _speech_to_text(
            src,
            tgt_fmt,
            out_path,
        )

    elif mode == "tts":
        await _text_to_speech(
            src,
            tgt_fmt,
            out_path,
        )

    else:
        raise ValueError(
            f"unsupported taskType={mode}"
        )

    if not out_path.exists():
        raise RuntimeError(
            "conversion produced no output"
        )

    mime = {
        "json": "application/json",
        **_MIME,
    }.get(
        tgt_fmt,
        "application/octet-stream",
    )

    return (
        str(out_path),
        mime,
        tgt_fmt,
    )


# ---------------------------------------------------------------------------
# HTTP poll client
# ---------------------------------------------------------------------------


async def _fail_job(client: httpx.AsyncClient, job_id: str, error: str) -> None:
    """POST /fail for a job. Swallows exceptions (best-effort notification)."""
    try:
        resp = await client.post(
            f"{_api_base}/api/v1/worker/jobs/{job_id}/fail",
            json={"error": error},
        )
        resp.raise_for_status()
    except Exception as exc:
        logger.warning("fail notification itself failed for job %s: %s", job_id, exc)


async def _process_job(client: httpx.AsyncClient, job_meta: dict) -> None:
    """Process one claimed job: download input → convert → upload result or fail."""
    job_id = str(job_meta["jobId"])
    conv_id = job_meta["conversionId"]
    src_fmt = str(job_meta["sourceFormat"]).lower().lstrip(".")
    tgt_fmt = str(job_meta["targetFormat"]).lower().lstrip(".")

    # Download input (streamed to avoid OOM on large files)
    WORK_DIR.mkdir(parents=True, exist_ok=True)
    input_path = WORK_DIR / f"in-{conv_id}-{uuid.uuid4().hex}.{src_fmt}"
    try:
        async with client.stream("GET", f"{_api_base}/api/v1/worker/jobs/{job_id}/input") as resp:
            resp.raise_for_status()
            with input_path.open("wb") as f:
                async for chunk in resp.aiter_bytes(65536):
                    f.write(chunk)
    except Exception as exc:
        logger.error("input download failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
        input_path.unlink(missing_ok=True)
        return

    # Convert
    output_path: Path | None = None
    try:
        job_payload: dict[str, Any] = {
            "_localInput": str(input_path),
            "conversionId": conv_id,
            "sourceFormat": src_fmt,
            "targetFormat": tgt_fmt,
            "taskType": job_meta.get(
                "taskType"
            ),
            "model": job_meta.get(
                "model"
            ),
            "options": job_meta.get(
                "options",
                {},
            ),
        }
        out_str, mime, _ = await convert(job_payload)
        output_path = Path(out_str)
    except Exception as exc:
        logger.error("conversion failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
        return
    finally:
        input_path.unlink(missing_ok=True)

    # Upload result (separate longer timeout: large files + slow home connections)
    try:
        with output_path.open("rb") as f:
            resp = await client.post(
                f"{_api_base}/api/v1/worker/jobs/{job_id}/result",
                files={"file": (output_path.name, f, mime)},
                timeout=httpx.Timeout(30.0, read=300.0, write=None),
            )
        resp.raise_for_status()
        logger.info("job %s completed (%s → %s)", job_id, src_fmt, tgt_fmt)
    except Exception as exc:
        logger.error("result upload failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
    finally:
        if output_path:
            output_path.unlink(missing_ok=True)


_running = True


def _handle_shutdown(signum: int, frame: Any) -> None:
    global _running
    logger.info("shutdown signal received (signal %d) — draining", signum)
    _running = False


async def _poll_loop() -> None:
    consumer = f"{socket.gethostname()}-{os.getpid()}"
    auth_headers = {"Authorization": f"Bearer {WORKER_API_TOKEN}"}
    logger.info(
        "poll loop started (consumer=%s, type=%s, api=%s, interval=%ds)",
        consumer, WORKER_TYPE, _api_base, POLL_INTERVAL,
    )

    # No base_url — all request paths are absolute strings built with _api_base so that
    # any path component in API_BASE_URL is preserved (httpx drops base_url path prefixes
    # when the request path starts with "/").
    async with httpx.AsyncClient(
        headers=auth_headers,
        timeout=httpx.Timeout(30.0),
    ) as client:
        while _running:
            # Claim a job
            try:
                resp = await client.post(
                    f"{_api_base}/api/v1/worker/claim",
                    json={"type": WORKER_TYPE, "consumer": consumer},
                )
                if resp.status_code == 204:
                    await asyncio.sleep(POLL_INTERVAL)
                    continue
                resp.raise_for_status()
                job_meta = resp.json()
            except Exception as exc:
                logger.warning("claim request failed: %s", exc)
                await asyncio.sleep(POLL_INTERVAL)
                continue

            # Validate required fields — a malformed response must never crash the loop
            job_id: str | None = None
            try:
                job_id = str(job_meta["jobId"])
                _ = job_meta["conversionId"]
                _ = job_meta["sourceFormat"]
                _ = job_meta["targetFormat"]
            except (KeyError, TypeError) as exc:
                logger.error(
                    "malformed claim response (missing %s) — skipping; raw: %s",
                    exc, job_meta,
                )
                if job_id:
                    await _fail_job(client, job_id, f"malformed job claim: {exc}")
                continue

            # Per-job guard: an unexpected bug in _process_job must not kill the loop
            try:
                await _process_job(client, job_meta)
            except Exception:
                logger.exception("unexpected error processing job %s — skipping", job_id)
                await _fail_job(client, job_id, "internal worker error")


def _check_api_base_url() -> None:
    """Warn if API_BASE_URL contains a path component that would produce double-path URLs."""
    from urllib.parse import urlparse
    parsed = urlparse(API_BASE_URL)
    path = parsed.path.strip("/")
    if path:
        logger.warning(
            "API_BASE_URL %r contains a path component %r — all worker API paths start with "
            "/api/v1/..., so any prefix in API_BASE_URL will be doubled. "
            "Set API_BASE_URL to the application root (scheme+host only, e.g. https://convertor.xakki.pro).",
            API_BASE_URL, path,
        )


def run() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    signal.signal(signal.SIGTERM, _handle_shutdown)
    signal.signal(signal.SIGINT, _handle_shutdown)
    _check_api_base_url()
    logger.info(
        "AI worker starting — API: %s, type: %s, whisper: %s/%s/%s",
        API_BASE_URL, WORKER_TYPE, WHISPER_MODEL, WHISPER_DEVICE, WHISPER_COMPUTE_TYPE,
    )
    asyncio.run(_poll_loop())


if __name__ == "__main__":
    run()
