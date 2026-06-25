"""In-memory pull-processing stats for the dev-server.

A single `Stats` object is shared between the `PullRunner` (writer) and
`routes_stats` (reader). Single event loop → no locking needed. Not persisted;
reset on restart. Shape produced by `snapshot()` matches GET /api/stats in the
API contract.
"""

from __future__ import annotations

import math
from collections import deque
from datetime import datetime, timezone
from typing import Any


def _utcnow_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Stats:
    """Live counters the PullRunner updates via the StatsSink protocol."""

    def __init__(self, *, latency_window: int = 200, error_window: int = 10) -> None:
        self.processed = 0
        self.success = 0
        self.failed = 0
        self.last_latency_ms: float | None = None
        self.started_at: str | None = None
        self.current_job: dict[str, Any] | None = None
        self._latencies: deque[float] = deque(maxlen=latency_window)
        self.last_errors: deque[dict[str, Any]] = deque(maxlen=error_window)
        self._active = False

    # --- runner lifecycle ---------------------------------------------------

    def on_runner_start(self) -> None:
        self._active = True
        self.started_at = _utcnow_iso()
        self.current_job = None

    def on_runner_stop(self) -> None:
        self._active = False
        self.current_job = None

    # --- StatsSink protocol (called by _poll_cycle) -------------------------

    def job_started(self, job_meta: dict) -> None:
        self.current_job = {
            "conversionId": job_meta.get("conversionId"),
            "sourceFormat": job_meta.get("sourceFormat"),
            "targetFormat": job_meta.get("targetFormat"),
            "startedAt": _utcnow_iso(),
        }

    def job_finished(self, job_meta: dict, ok: bool, error: str | None, elapsed_ms: float) -> None:
        self.processed += 1
        self.last_latency_ms = elapsed_ms
        self._latencies.append(elapsed_ms)
        if ok:
            self.success += 1
        else:
            self.failed += 1
            self.last_errors.appendleft({
                "conversionId": job_meta.get("conversionId"),
                "error": error or "unknown error",
                "at": _utcnow_iso(),
            })
        self.current_job = None

    # --- read side ----------------------------------------------------------

    @property
    def state(self) -> str:
        if not self._active:
            return "stopped"
        return "running" if self.current_job else "idle"

    def _latency_summary(self) -> dict[str, int]:
        if not self._latencies:
            return {"avg": 0, "p95": 0, "last": 0}
        vals = sorted(self._latencies)
        avg = sum(vals) / len(vals)
        idx = max(0, math.ceil(0.95 * len(vals)) - 1)
        return {
            "avg": round(avg),
            "p95": round(vals[idx]),
            "last": round(self.last_latency_ms or 0),
        }

    def snapshot(self) -> dict[str, Any]:
        if not self._active:
            out: dict[str, Any] = {"pullEnabled": False, "state": "stopped"}
            if self.processed:
                out.update({
                    "processed": self.processed,
                    "success": self.success,
                    "failed": self.failed,
                    "latencyMs": self._latency_summary(),
                    "lastErrors": list(self.last_errors),
                })
            return out
        return {
            "pullEnabled": True,
            "state": self.state,
            "processed": self.processed,
            "success": self.success,
            "failed": self.failed,
            "latencyMs": self._latency_summary(),
            "currentJob": self.current_job,
            "lastErrors": list(self.last_errors),
            "startedAt": self.started_at,
        }
