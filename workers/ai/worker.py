"""AI worker — production pull-loop only.

Polls the universal worker pull-API for jobs, downloads the input through the API,
runs the format-derived conversion (see convert.py), and uploads the result. No
direct KeyDB or S3 access — the API is the gateway. No provider/external logic lives
here: STT/TTS/embedding are local-only providers reached through convert().

PULL_ENABLED gate (default false): when false the worker stays idle and logs a clear
warning instead of claiming — this prevents accidentally draining the real queue
during local development. Set PULL_ENABLED=true to run the real poll loop.

All configuration comes from config.load_config() (the single config source).
"""

from __future__ import annotations

import asyncio
import logging
import os
import signal
import socket
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Protocol
from urllib.parse import urlparse

import httpx

from workers.ai.config import Config, load_config
from workers.ai.convert import convert
from workers.ai.pull_api import PullApiClient

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# CAPABILITIES — read by the routing-drift test.
# The local AI worker consumes the single PHP `ai` stream (streamFor() only ever
# emits 'ai' for AI pairs). embedding / streaming-STT are sub-behaviours derived
# inside this worker from the format pair, NOT separate routing streams — so they
# are NOT advertised as routing_keys. The matrix is empty: AI (from→to) pairs live
# in the PHP registry only as virtual *_stt / *_tts keys, which the drift test
# skips; advertising concrete pairs here would falsely fail the subset assertion.
# ---------------------------------------------------------------------------

CAPABILITIES: dict[str, Any] = {
    "routing_keys": ["ai"],
    "matrix": {},
}


def _safe_err(exc: Exception, limit: int = 200) -> str:
    """Bounded error string safe to log and store (provider errors can echo user input)."""
    return f"{type(exc).__name__}: {str(exc)[:limit]}"


@dataclass
class JobOutcome:
    """Result of one job pass — fed to the stats sink by the poll cycle."""

    ok: bool
    error: str | None = None


class StatsSink(Protocol):
    """Optional instrumentation hook the dev-server's PullRunner plugs in.

    Prod `run()` passes no sink (None); the dev-server passes its in-memory Stats.
    """

    def job_started(self, job_meta: dict) -> None: ...

    def job_finished(self, job_meta: dict, ok: bool, error: str | None, elapsed_ms: float) -> None: ...


# ---------------------------------------------------------------------------
# Per-job processing
# ---------------------------------------------------------------------------


async def _process_job(client: httpx.AsyncClient, cfg: Config, job_meta: dict) -> JobOutcome:
    """Process one claimed job: download input → convert → upload result or fail.

    Returns a JobOutcome (ok + bounded error) so the poll cycle can update stats.
    The fail-API notification still happens here on every failure path.
    """
    api = PullApiClient(client, cfg.api_base)
    job_id = str(job_meta["jobId"])
    conv_id = job_meta["conversionId"]
    src_fmt = str(job_meta["sourceFormat"]).lower().lstrip(".")
    tgt_fmt = str(job_meta["targetFormat"]).lower().lstrip(".")

    async def _notify_fail(error: str) -> None:
        try:
            await api.fail(job_id, error)
        except Exception as exc:
            logger.warning("fail notification itself failed for job %s: %s", job_id, exc)

    # Download input (streamed to avoid OOM on large files)
    cfg.work_dir.mkdir(parents=True, exist_ok=True)
    input_path = cfg.work_dir / f"in-{conv_id}-{uuid.uuid4().hex}.{src_fmt}"
    try:
        async with api.stream_input(job_id) as resp:
            with input_path.open("wb") as f:
                async for chunk in resp.aiter_bytes(65536):
                    f.write(chunk)
    except Exception as exc:
        logger.error("input download failed for job %s: %s", job_id, _safe_err(exc))
        await _notify_fail(_safe_err(exc))
        input_path.unlink(missing_ok=True)
        return JobOutcome(ok=False, error=_safe_err(exc))

    # Convert
    output_path: Path | None = None
    try:
        job_payload: dict[str, Any] = {
            "_localInput": str(input_path),
            "conversionId": conv_id,
            "sourceFormat": src_fmt,
            "targetFormat": tgt_fmt,
            "model": job_meta.get("model"),
        }
        out_str, mime, _ = await convert(job_payload, cfg)
        output_path = Path(out_str)
    except Exception as exc:
        logger.error("conversion failed for job %s: %s", job_id, _safe_err(exc))
        await _notify_fail(_safe_err(exc))
        return JobOutcome(ok=False, error=_safe_err(exc))
    finally:
        input_path.unlink(missing_ok=True)

    # Upload result
    try:
        with output_path.open("rb") as f:
            await api.upload_result(job_id, output_path.name, f, mime)
        logger.info("job %s completed (%s → %s)", job_id, src_fmt, tgt_fmt)
        return JobOutcome(ok=True)
    except Exception as exc:
        logger.error("result upload failed for job %s: %s", job_id, _safe_err(exc))
        await _notify_fail(_safe_err(exc))
        return JobOutcome(ok=False, error=_safe_err(exc))
    finally:
        if output_path:
            output_path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# Poll loop
# ---------------------------------------------------------------------------

_running = True


def _handle_shutdown(signum: int, frame: Any) -> None:
    global _running
    logger.info("shutdown signal received (signal %d) — draining", signum)
    _running = False


async def _poll_cycle(
    client: httpx.AsyncClient,
    cfg: Config,
    consumer: str,
    stats: StatsSink | None = None,
) -> bool:
    """Run ONE claim→process pass. Shared by prod `_poll_loop` and the dev-server PullRunner.

    Returns True when a job was handled (so the caller loops immediately), False when
    the queue is empty or the claim failed (so the caller sleeps `poll_interval`).
    Never raises: an unexpected per-job bug is caught and reported via the fail-API.
    The optional `stats` sink is updated around real job processing only.
    """
    api = PullApiClient(client, cfg.api_base)

    # Claim a job
    try:
        job_meta = await api.claim(cfg.worker_type, consumer)
    except Exception as exc:
        logger.warning("claim request failed: %s", exc)
        return False

    if job_meta is None:
        return False

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
            try:
                await api.fail(job_id, f"malformed job claim: {exc}")
            except Exception:
                logger.warning("fail notification failed for job %s", job_id)
        return True

    if stats is not None:
        stats.job_started(job_meta)
    started = time.monotonic()

    # Per-job guard: an unexpected bug must not kill the loop
    try:
        outcome = await _process_job(client, cfg, job_meta)
    except Exception:
        logger.exception("unexpected error processing job %s — skipping", job_id)
        try:
            await api.fail(job_id, "internal worker error")
        except Exception:
            logger.warning("fail notification failed for job %s", job_id)
        outcome = JobOutcome(ok=False, error="internal worker error")

    if stats is not None:
        elapsed_ms = (time.monotonic() - started) * 1000.0
        stats.job_finished(job_meta, outcome.ok, outcome.error, elapsed_ms)
    return True


async def _poll_loop(cfg: Config) -> None:
    consumer = f"{socket.gethostname()}-{os.getpid()}"
    auth_headers = {"Authorization": f"Bearer {cfg.worker_api_token}"}
    logger.info(
        "poll loop started (consumer=%s, type=%s, api=%s, interval=%ds)",
        consumer, cfg.worker_type, cfg.api_base, cfg.poll_interval,
    )

    async with httpx.AsyncClient(
        headers=auth_headers,
        timeout=httpx.Timeout(30.0),
    ) as client:
        while _running:
            handled = await _poll_cycle(client, cfg, consumer)
            if not handled:
                await asyncio.sleep(cfg.poll_interval)


def _check_api_base_url(cfg: Config) -> None:
    """Warn if API_BASE_URL contains a path component that would produce double-path URLs."""
    parsed = urlparse(cfg.api_base_url)
    path = parsed.path.strip("/")
    if path:
        logger.warning(
            "API_BASE_URL %r contains a path component %r — all worker API paths start with "
            "/api/v1/..., so any prefix in API_BASE_URL will be doubled. "
            "Set API_BASE_URL to the application root (scheme+host only).",
            cfg.api_base_url, path,
        )


def run(cfg: Config | None = None) -> None:
    """Worker-mode entry point. Gated by PULL_ENABLED: idle+warn when false."""
    if cfg is None:
        cfg = load_config()
    signal.signal(signal.SIGTERM, _handle_shutdown)
    signal.signal(signal.SIGINT, _handle_shutdown)
    _check_api_base_url(cfg)

    if not cfg.pull_enabled:
        logger.warning(
            "PULL_ENABLED is false — AI worker will NOT claim tasks (idle). "
            "Set PULL_ENABLED=true to process the real queue."
        )
        return

    cfg.validate()
    logger.info(
        "AI worker starting — API: %s, type: %s, whisper: %s/%s/%s",
        cfg.api_base_url, cfg.worker_type,
        cfg.whisper_model, cfg.whisper_device, cfg.whisper_compute_type,
    )
    asyncio.run(_poll_loop(cfg))


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    run()
