"""StreamConsumerBase — Redis Streams (XREADGROUP) based worker base class.

Replaces BaseWorker (BRPOPLPUSH) with the Phase-1 stream-per-routing-key
architecture. Subclasses declare CAPABILITIES and implement convert().

Contract refs: docs/queue-contract.md §§2-5, docs/queue-redesign-design.md.
"""

from __future__ import annotations

import json
import logging
import os
import signal
import socket
import tempfile
import time
import uuid
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any

import redis

from workers.common.logging_config import configure_logging
from workers.common.s3 import get_file, put_file

logger = logging.getLogger(__name__)

# --- Connection -----------------------------------------------------------
REDIS_HOST = os.getenv("REDIS_HOST", "keydb")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "2"))
# Empty password → no AUTH; never inject "default:@" with empty pw.
_REDIS_PASSWORD: str | None = os.getenv("REDIS_PASSWORD", "") or None

# --- Writable work dir (inputs/outputs live here as tmp files) ------------
# Defaults to the system tmp dir; the non-root Dockerfile provides a writable
# WORK_DIR. Inputs no longer come from a shared volume — they are pulled from
# S3 by the base class before convert() runs.
WORK_DIR = Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve()

# --- S3 / output ----------------------------------------------------------
S3_BUCKET_PREFIX = os.getenv("S3_BUCKET_PREFIX", "convertor")

# --- Consumer loop knobs (overridable via env) ----------------------------
_BLOCK_MS = int(os.getenv("CONSUMER_BLOCK_MS", "5000"))
_READ_COUNT = int(os.getenv("CONSUMER_READ_COUNT", "1"))
_IDLE_MS = int(os.getenv("CONSUMER_IDLE_MS", str(5 * 60 * 1000)))  # 5 min
_MAX_RETRIES = int(os.getenv("CONSUMER_MAX_RETRIES", "3"))
_RECLAIM_BATCH = int(os.getenv("CONSUMER_RECLAIM_BATCH", "10"))

_GROUP = "convertor"
_STATUS_TTL = 24 * 3600  # seconds


# --------------------------------------------------------------------------
# Wire-format helpers (§2)
# --------------------------------------------------------------------------

def _parse_entry(fields: dict) -> dict:
    """Decode a Symfony Messenger Redis-Streams entry (double-encoded body §2).

    Handles both bytes and str keys/values from redis-py.
    """
    if b"message" in fields:
        raw = fields[b"message"]
    else:
        raw = fields["message"]
    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")
    envelope = json.loads(raw)          # outer: {body, headers}
    body = envelope["body"]
    if isinstance(body, bytes):
        body = body.decode("utf-8")
    return json.loads(body)             # inner: job (§3)


def _now_ms() -> int:
    return int(time.time() * 1000)


def _consumer_name() -> str:
    return f"{socket.gethostname()}-{os.getpid()}"


# --------------------------------------------------------------------------
# Base class
# --------------------------------------------------------------------------

class StreamConsumerBase(ABC):
    """Base class for per-routing-key XREADGROUP stream workers.

    Subclasses must set:
        CAPABILITIES = {"routing_keys": [...], "matrix": {...}}
    and implement:
        convert(job) -> (local_output_path: str, output_mime: str, target_ext: str)
    """

    CAPABILITIES: dict[str, Any] = {}

    def __init__(self) -> None:
        configure_logging()
        self._consumer = _consumer_name()
        self._running = True
        self._redis = self._connect()
        self._redis_blocking = self._connect_blocking()
        self._setup_streams()
        self._setup_signals()
        logger.info(
            "stream consumer started",
            extra={
                "consumer": self._consumer,
                "streams": [f"conv.{k}" for k in self.CAPABILITIES.get("routing_keys", [])],
            },
        )

    # ------------------------------------------------------------------
    # Public
    # ------------------------------------------------------------------

    def run(self) -> None:
        """Start the main loop (blocking)."""
        try:
            self._main_loop()
        except Exception:
            logger.exception("fatal error in main loop")
            raise

    # ------------------------------------------------------------------
    # Abstract
    # ------------------------------------------------------------------

    @abstractmethod
    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        """Perform the file conversion.

        Returns: (local_output_path, output_mime, target_ext).
        Raise on any failure — the base class handles retry / DLQ.
        """

    # ------------------------------------------------------------------
    # Redis setup
    # ------------------------------------------------------------------

    def _connect(self) -> redis.Redis:
        return redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            db=REDIS_DB,
            password=_REDIS_PASSWORD,
            decode_responses=True,
        )

    def _connect_blocking(self) -> redis.Redis:
        # socket_timeout=None is mandatory: a finite value shorter than
        # CONSUMER_BLOCK_MS causes TimeoutError before BLOCK returns empty.
        return redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            db=REDIS_DB,
            password=_REDIS_PASSWORD,
            decode_responses=True,
            socket_timeout=None,
        )

    def _setup_streams(self) -> None:
        for key in self.CAPABILITIES.get("routing_keys", []):
            stream = f"conv.{key}"
            try:
                self._redis.xgroup_create(stream, _GROUP, id="0", mkstream=True)
                logger.info("created consumer group on stream", extra={"stream": stream})
            except redis.ResponseError as exc:
                if "BUSYGROUP" in str(exc):
                    logger.debug("group already exists", extra={"stream": stream})
                else:
                    raise

    # ------------------------------------------------------------------
    # Signal handling
    # ------------------------------------------------------------------

    def _setup_signals(self) -> None:
        signal.signal(signal.SIGTERM, self._handle_shutdown)
        signal.signal(signal.SIGINT, self._handle_shutdown)

    def _handle_shutdown(self, signum: int, frame: Any) -> None:
        logger.info("shutdown signal received", extra={"signal": signum})
        self._running = False

    # ------------------------------------------------------------------
    # Main loop
    # ------------------------------------------------------------------

    def _main_loop(self) -> None:
        keys = self.CAPABILITIES.get("routing_keys", [])
        streams = {f"conv.{k}": ">" for k in keys}
        last_reclaim_ms = 0

        while self._running:
            # Periodic PEL reclaim pass
            now = _now_ms()
            if now - last_reclaim_ms >= _IDLE_MS:
                self._reclaim_stuck()
                last_reclaim_ms = now

            try:
                results = self._redis_blocking.xreadgroup(
                    groupname=_GROUP,
                    consumername=self._consumer,
                    streams=streams,
                    count=_READ_COUNT,
                    block=_BLOCK_MS,
                )
            except (redis.exceptions.TimeoutError, redis.exceptions.ConnectionError) as exc:
                logger.info(
                    "transient error on blocking xreadgroup — continuing",
                    extra={"error": str(exc)},
                )
                continue
            if not results:
                continue

            for stream_name, entries in results:
                for entry_id, fields in entries:
                    self._process_entry(stream_name, entry_id, fields)

    # ------------------------------------------------------------------
    # Entry processing (ordered commit per design doc §fault-tolerance)
    # ------------------------------------------------------------------

    def _process_entry(self, stream: str, entry_id: str, fields: dict) -> None:
        try:
            job = _parse_entry(fields)
        except Exception:
            logger.exception("parse error — routing to conv.dead", extra={"entry_id": entry_id})
            self._redis.xadd("conv.dead", {
                "data": json.dumps({"entryId": entry_id, "reason": "parse_error", "stream": stream})
            })
            self._redis.xack(stream, _GROUP, entry_id)
            return

        conv_id = job.get("conversionId")
        status_key = f"conv:status:{conv_id}"
        start_ms = _now_ms()

        # --- Idempotency guard (§fault-tolerance) -------------------------
        current_state = self._redis.hget(status_key, "state")
        if current_state == "completed":
            # Re-emit the result event in case the original XADD conv.result
            # was lost (crash between HSET completed and XADD).  PHP dedupes
            # by conversionId, so a duplicate event is harmless.
            self._re_emit_completed_result(conv_id, status_key)
            self._redis.xack(stream, _GROUP, entry_id)
            return

        # --- Delivery count check — DLQ after max_retries -----------------
        delivery_count = self._get_delivery_count(stream, entry_id)
        if delivery_count > _MAX_RETRIES:
            self._send_to_dlq(stream, entry_id, job, status_key)
            return

        # --- Mark processing ----------------------------------------------
        self._redis.hset(status_key, mapping={
            "state": "processing",
            "startedAt": start_ms,
            "sourceFormat": job.get("sourceFormat", ""),
            "targetFormat": job.get("targetFormat", ""),
            "category": job.get("category", ""),
            "isAi": "1" if job.get("isAi") else "0",
            "worker": self._consumer,
            "attempts": delivery_count,
            "updatedAt": start_ms,
        })
        self._redis.expire(status_key, _STATUS_TTL)

        # --- Download input from S3, convert, commit; always clean tmp ----
        local_input: str | None = None
        local_output: str | None = None
        try:
            # (0) Download S3 input → unique tmp file (preserve source ext)
            try:
                local_input = self._download_input(job)
            except Exception as exc:
                logger.exception(
                    "input download failed — leaving unacked for retry",
                    extra={"conversionId": conv_id, "error": str(exc)},
                )
                self._redis.hset(status_key, mapping={
                    "error": str(exc),
                    "updatedAt": _now_ms(),
                })
                self._redis.expire(status_key, _STATUS_TTL)
                # Leave entry unacked; XAUTOCLAIM will reclaim after _IDLE_MS.
                return

            job["_localInput"] = local_input

            # --- Convert --------------------------------------------------
            try:
                local_output, output_mime, target_ext = self.convert(job)
            except Exception as exc:
                logger.exception(
                    "convert failed — leaving unacked for retry",
                    extra={"conversionId": conv_id, "error": str(exc)},
                )
                self._redis.hset(status_key, mapping={
                    "error": str(exc),
                    "updatedAt": _now_ms(),
                })
                self._redis.expire(status_key, _STATUS_TTL)
                # Leave entry unacked; XAUTOCLAIM will reclaim after _IDLE_MS.
                return

            # --- Ordered commit (§S3-sink): S3 → HSET → XADD → XACK ------
            bucket = f"{S3_BUCKET_PREFIX}-results"
            ts = time.gmtime()
            s3_key = (
                f"results/{ts.tm_year}/{ts.tm_mon:02d}-{ts.tm_mday:02d}"
                f"/{conv_id}.{target_ext}"
            )
            finish_ms = _now_ms()

            # (1) S3 PUT — deterministic key → safe to retry/overwrite
            try:
                s3_meta = put_file(local_output, bucket, s3_key, output_mime)
            except Exception as exc:
                logger.exception(
                    "S3 upload failed — leaving unacked for retry",
                    extra={"conversionId": conv_id, "error": str(exc)},
                )
                self._redis.hset(status_key, mapping={"error": str(exc), "updatedAt": _now_ms()})
                self._redis.expire(status_key, _STATUS_TTL)
                return

            # (2) HSET completed
            self._redis.hset(status_key, mapping={
                "state": "completed",
                "outputBucket": bucket,
                "outputKey": s3_key,
                "outputMime": output_mime,
                "outputSize": s3_meta["size"],
                "finishedAt": finish_ms,
                "updatedAt": finish_ms,
            })
            self._redis.expire(status_key, _STATUS_TTL)

            # (3) XADD result event (§5 pinned shape; field=data, raw JSON)
            result_body = {
                "conversionId": conv_id,
                "state": "completed",
                "outputBucket": bucket,
                "outputKey": s3_key,
                "outputMime": output_mime,
                "outputSize": s3_meta["size"],
                "error": None,
                "processingMs": finish_ms - start_ms,
            }
            self._redis.xadd("conv.result", {"data": json.dumps(result_body)})

            # (4) XACK
            self._redis.xack(stream, _GROUP, entry_id)
            logger.info(
                "conversion completed",
                extra={
                    "conversionId": conv_id,
                    "s3Bucket": bucket,
                    "s3Key": s3_key,
                    "processingMs": finish_ms - start_ms,
                },
            )
        finally:
            # Clean tmp input + output on every exit (success or failure).
            # Retries regenerate both (re-download + re-convert).
            self._cleanup_tmp(local_input)
            self._cleanup_tmp(local_output)

    # ------------------------------------------------------------------
    # Input download / tmp cleanup
    # ------------------------------------------------------------------

    def _download_input(self, job: dict) -> str:
        """Download s3://{inputBucket}/{inputKey} to a unique WORK_DIR tmp file.

        Preserves the source extension so suffix-based logic keeps working.
        """
        bucket = job["inputBucket"]
        key = job["inputKey"]
        ext = Path(key).suffix  # includes leading dot, or "" if none
        WORK_DIR.mkdir(parents=True, exist_ok=True)
        dest = WORK_DIR / f"in-{uuid.uuid4().hex}{ext}"
        return get_file(bucket, key, str(dest))

    @staticmethod
    def _cleanup_tmp(path: str | None) -> None:
        if not path:
            return
        try:
            Path(path).unlink(missing_ok=True)
        except Exception:
            logger.warning("failed to remove tmp file", extra={"path": path}, exc_info=True)

    # ------------------------------------------------------------------
    # PEL / XAUTOCLAIM
    # ------------------------------------------------------------------

    def _get_delivery_count(self, stream: str, entry_id: str) -> int:
        """Return how many times this entry has been delivered (from PEL)."""
        try:
            pending = self._redis.xpending_range(
                stream, _GROUP, min=entry_id, max=entry_id, count=1
            )
            if pending:
                return int(pending[0].get("times_delivered", 1))
        except Exception:
            logger.debug("xpending_range failed; defaulting delivery_count=1", exc_info=True)
        return 1

    def _reclaim_stuck(self) -> None:
        """XAUTOCLAIM entries idle ≥ _IDLE_MS back to this consumer."""
        for key in self.CAPABILITIES.get("routing_keys", []):
            stream = f"conv.{key}"
            try:
                result = self._redis.xautoclaim(
                    stream,
                    _GROUP,
                    self._consumer,
                    min_idle_time=_IDLE_MS,
                    start_id="0-0",
                    count=_RECLAIM_BATCH,
                )
                # Redis 6.2: [next_id, entries]; Redis 7.0: [next_id, entries, deleted]
                entries = result[1] if len(result) > 1 else []
                for entry_id, fields in entries:
                    logger.info(
                        "reclaimed stuck entry",
                        extra={"stream": stream, "entryId": entry_id},
                    )
                    self._process_entry(stream, entry_id, fields)
            except redis.ResponseError as exc:
                if "ERR" in str(exc) and "XAUTOCLAIM" in str(exc).upper():
                    logger.warning(
                        "XAUTOCLAIM unsupported (Redis <6.2?), skipping reclaim",
                        extra={"stream": stream},
                    )
                else:
                    logger.warning("XAUTOCLAIM error", extra={"stream": stream, "error": str(exc)})

    # ------------------------------------------------------------------
    # Idempotency helper
    # ------------------------------------------------------------------

    def _re_emit_completed_result(self, conv_id: Any, status_key: str) -> None:
        """Re-emit a completed result event built from the status hash.

        Called when the idempotency guard sees state==completed on redelivery.
        Covers the crash-between-HSET-and-XADD gap; PHP dedupes by conversionId.
        """
        h = self._redis.hgetall(status_key)
        output_size = h.get("outputSize")
        result_body = {
            "conversionId": conv_id,
            "state": "completed",
            "outputBucket": h.get("outputBucket"),
            "outputKey": h.get("outputKey"),
            "outputMime": h.get("outputMime"),
            "outputSize": int(output_size) if output_size is not None else None,
            "error": None,
            "processingMs": 0,
        }
        self._redis.xadd("conv.result", {"data": json.dumps(result_body)})
        logger.info(
            "idempotency: re-emitted completed result event",
            extra={"conversionId": conv_id},
        )

    # ------------------------------------------------------------------
    # DLQ
    # ------------------------------------------------------------------

    def _send_to_dlq(
        self, stream: str, entry_id: str, job: dict, status_key: str
    ) -> None:
        conv_id = job.get("conversionId")
        reason = f"max_retries ({_MAX_RETRIES}) exceeded"
        finish_ms = _now_ms()
        logger.error(
            "DLQ: sending failed job",
            extra={"conversionId": conv_id, "reason": reason},
        )

        # Emit to dead-letter stream
        self._redis.xadd("conv.dead", {
            "data": json.dumps({
                "conversionId": conv_id,
                "state": "failed",
                "reason": reason,
                "originalStream": stream,
                "originalEntryId": entry_id,
            })
        })

        # Update status hash
        self._redis.hset(status_key, mapping={
            "state": "failed",
            "error": reason,
            "finishedAt": finish_ms,
            "updatedAt": finish_ms,
        })
        self._redis.expire(status_key, _STATUS_TTL)

        # Notify PHP via result stream (§5 failed shape)
        result_body = {
            "conversionId": conv_id,
            "state": "failed",
            "outputBucket": None,
            "outputKey": None,
            "outputMime": None,
            "outputSize": None,
            "error": reason,
            "processingMs": 0,
        }
        self._redis.xadd("conv.result", {"data": json.dumps(result_body)})

        # Acknowledge so it leaves the PEL
        self._redis.xack(stream, _GROUP, entry_id)
