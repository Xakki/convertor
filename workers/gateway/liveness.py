"""Liveness aggregation + periodic push to PHP (registry-06, gateway half).

Gateway aggregates incoming `ping{cpu,mem,load}` per `(workerType, instanceId)` —
`instanceId` now arrives in the `ready` handshake frame (see
`workers/common/ws_client.py::_send_ready`, registry-06 — reuses the exact same
value already sent to PHP's `register()`, registry-02; no separate derivation) —
and pushes a BATCH on an interval to PHP's internal endpoint
`POST /api/v1/internal/worker/liveness`, never per-ping. Matches the epic's
"liveness is monitoring, not routing" decision: PHP's `lastSeen`/`status` are
UPDATE-ONLY — liveness NEVER creates a `worker_capabilities` row, only
`register()` does (see `InternalWorkerController::liveness()`).

Request/response contract (fixed by the team-lead, confirmed against the PHP
implementation — see registry-06 card):
    POST /api/v1/internal/worker/liveness
    {"instances": [{"workerType": "...", "instanceId": "...", "status": "alive"
                     | "disconnected", "lastSeenAt": "<ISO-8601 UTC>",
                     "metrics": {"cpu": ..., "mem": ..., "load": ...} | omitted}]}
    → {"updated": <int>, "unknown": [{"workerType": "...", "instanceId": "..."}]}

RESILIENCE (the part that matters most): this push must NEVER stall or crash the
gateway — liveness is telemetry, moving conversion jobs is the gateway's real job.
- Runs as its OWN periodic `asyncio.Task` (see `run_liveness_push_loop`, wired in
  `__main__.py` alongside reclaim/dlq-consumer) — a slow/hung PHP response only
  delays the NEXT liveness cycle; it never blocks WS message handling for any
  connection (asyncio yields on `await`, cooperative scheduling).
- Any failure (network error, timeout, non-2xx, non-JSON body) is caught inside
  `RelayClient.post_liveness` / `_push_once` and resolves to a no-op retry next
  cycle — never raises into the loop, never crashes it.
- Bounded memory even under instance churn (see `LivenessAggregator` docstring):
  alive entries are capped by a real system resource (concurrently open WS
  connections); disconnect markers are capped by `_MAX_PENDING_DISCONNECTS` and
  cleared on every successful push (one-shot signal, not resent forever).
"""

from __future__ import annotations

import asyncio
import logging
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone

from workers.gateway.config import Config
from workers.gateway.relay import RelayClient

logger = logging.getLogger(__name__)

# Mirrors the register() `instanceId` contract (workers/common/ws_client.py:
# _sanitize_instance_id / registry-02): non-empty, <=128 chars, [A-Za-z0-9._:-].
# Re-validated HERE (not just trusted off the WS wire) because PHP's liveness
# endpoint rejects the WHOLE BATCH on any single malformed record (its own
# documented malformed-batch policy, registry-06 PHP-зона) — one buggy/stale
# worker sending a garbage instanceId must not poison liveness reporting for
# every OTHER worker in the same push cycle.
_INSTANCE_ID_RE = re.compile(r"^[A-Za-z0-9._:-]{1,128}$")

# Hard cap on pending (not-yet-successfully-pushed) disconnect markers — bounds
# memory even if PHP is unreachable for a long stretch while workers churn.
# Beyond this, the OLDEST marker is dropped (logged) — this is telemetry, not
# correctness-critical data: routing is entirely unaffected (see module + epic
# docstring — liveness never gates the matrix), only the admin "when did this
# instance last disconnect" view loses a data point for the dropped entry.
_MAX_PENDING_DISCONNECTS = 2000


def _valid_key(worker_type: str | None, instance_id: str | None) -> bool:
    return (
        isinstance(worker_type, str) and bool(worker_type)
        and isinstance(instance_id, str) and bool(_INSTANCE_ID_RE.match(instance_id))
    )


def _now_iso() -> str:
    """UTC ISO-8601 `lastSeenAt`, produced by the gateway (per contract)."""
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


@dataclass
class _Instance:
    worker_type: str
    instance_id: str
    status: str  # "alive" | "disconnected"
    cpu: float | None = None
    mem: float | None = None
    load: float | None = None
    last_seen_at: str = field(default_factory=_now_iso)

    def to_payload(self) -> dict:
        payload: dict = {
            "workerType": self.worker_type,
            "instanceId": self.instance_id,
            "status": self.status,
            "lastSeenAt": self.last_seen_at,
        }
        if self.cpu is not None or self.mem is not None or self.load is not None:
            payload["metrics"] = {"cpu": self.cpu, "mem": self.mem, "load": self.load}
        return payload


class LivenessAggregator:
    """In-memory per-`(workerType, instanceId)` latest-state aggregator.

    Bounded by construction, NOT by an ad-hoc cap alone:
    - `_alive` holds at most as many entries as there are concurrently open WS
      connections that sent a valid `instanceId` — an already-bounded system
      resource. An entry is created on connect, refreshed on every `ping`, and
      MOVED OUT (never merely accumulated) to a one-shot disconnect marker on
      disconnect — it cannot grow across reconnect churn.
    - `_pending_disconnects` (one-shot "this instance just went away" markers)
      IS an ad-hoc structure that could otherwise grow unboundedly if PHP stays
      unreachable through heavy churn — capped at `_MAX_PENDING_DISCONNECTS`
      and drained on every successful push (see `mark_pushed`).
    """

    def __init__(self) -> None:
        self._alive: dict[tuple[str, str], _Instance] = {}
        self._pending_disconnects: dict[tuple[str, str], _Instance] = {}

    def record_connect(self, worker_type: str | None, instance_id: str | None) -> None:
        """Call right after a successful handshake. Missing/invalid instanceId
        (older worker build, or a malformed value) is logged ONCE here and the
        connection simply isn't tracked for liveness — job dispatch is entirely
        unaffected (instanceId plays no role in routing)."""
        if not _valid_key(worker_type, instance_id):
            logger.warning(
                "liveness: connection has no usable instanceId — not tracked "
                "(older worker build, or a malformed value)",
                extra={"workerType": worker_type, "instanceId": instance_id},
            )
            return
        key = (worker_type, instance_id)
        self._pending_disconnects.pop(key, None)  # reconnect cancels a pending disconnect
        self._alive[key] = _Instance(worker_type=worker_type, instance_id=instance_id, status="alive")

    def record_ping(
        self,
        worker_type: str | None,
        instance_id: str | None,
        cpu: float | None,
        mem: float | None,
        load: float | None,
    ) -> None:
        """Update latest metrics + lastSeenAt for an already-connected instance.
        Silently ignored (no per-ping log spam — already warned at connect) if
        the key is invalid/missing."""
        if not _valid_key(worker_type, instance_id):
            return
        key = (worker_type, instance_id)
        inst = self._alive.get(key)
        if inst is None:
            # A ping arrived without a prior recorded connect (e.g. aggregator
            # was replaced/restarted mid-connection) — recreate rather than
            # silently drop telemetry for a genuinely-alive worker.
            inst = _Instance(worker_type=worker_type, instance_id=instance_id, status="alive")
            self._alive[key] = inst
        inst.status = "alive"
        inst.cpu, inst.mem, inst.load = cpu, mem, load
        inst.last_seen_at = _now_iso()

    def record_disconnect(self, worker_type: str | None, instance_id: str | None) -> None:
        """Call when the WS socket closes. Marks the instance disconnected for
        the NEXT push batch — admin-view signal only, per the epic's Decisions
        this must NEVER remove the instance's pairs from the routing matrix
        (PHP enforces that independently; the gateway doesn't touch the matrix
        at all)."""
        if not _valid_key(worker_type, instance_id):
            return
        key = (worker_type, instance_id)
        inst = self._alive.pop(key, None)
        if inst is None:
            inst = _Instance(worker_type=worker_type, instance_id=instance_id, status="disconnected")
        inst.status = "disconnected"
        inst.last_seen_at = _now_iso()
        if key not in self._pending_disconnects and len(self._pending_disconnects) >= _MAX_PENDING_DISCONNECTS:
            oldest_key = next(iter(self._pending_disconnects))
            dropped = self._pending_disconnects.pop(oldest_key)
            logger.warning(
                "liveness: pending-disconnect backlog full (PHP unreachable for a "
                "while?) — dropping oldest marker; telemetry only, routing unaffected",
                extra={
                    "workerType": dropped.worker_type, "instanceId": dropped.instance_id,
                    "backlogCap": _MAX_PENDING_DISCONNECTS,
                },
            )
        self._pending_disconnects[key] = inst

    def snapshot_batch(self) -> list[_Instance]:
        """Every currently-alive instance (refreshed every cycle so PHP's
        `lastSeen` keeps advancing while the worker stays connected) + every
        not-yet-confirmed-pushed disconnect marker."""
        return list(self._alive.values()) + list(self._pending_disconnects.values())

    def mark_pushed(self, batch: list[_Instance]) -> None:
        """Call ONLY after a successful push covering exactly `batch`: drop the
        disconnect markers it contained (one-shot, PHP now durably has the
        status) — alive entries are intentionally left alone, they get
        refreshed again next cycle regardless (see `snapshot_batch`)."""
        for inst in batch:
            if inst.status == "disconnected":
                self._pending_disconnects.pop((inst.worker_type, inst.instance_id), None)

    def __len__(self) -> int:
        return len(self._alive) + len(self._pending_disconnects)


async def run_liveness_push_loop(
    aggregator: LivenessAggregator, relay: RelayClient, cfg: Config
) -> None:
    """Async task: periodic liveness batch push. Started via `asyncio.create_task`
    from `__main__.py` alongside the WS server / reclaim-loop / DLQ-consumer.
    Terminates on `CancelledError` (graceful shutdown) — every other failure mode
    is swallowed (logged, never propagated) so it can never take the gateway
    down with it."""
    logger.info(
        "liveness push loop started",
        extra={"intervalS": cfg.liveness_push_interval_s},
    )
    while True:
        try:
            await asyncio.sleep(cfg.liveness_push_interval_s)
        except asyncio.CancelledError:
            logger.info("liveness push loop cancelled")
            return
        try:
            await _push_once(aggregator, relay)
        except asyncio.CancelledError:
            logger.info("liveness push loop cancelled during push")
            return
        except Exception as exc:  # noqa: BLE001 — telemetry-only, never crash the loop
            logger.warning("liveness push cycle failed unexpectedly", extra={"error": str(exc)})


async def _push_once(aggregator: LivenessAggregator, relay: RelayClient) -> None:
    batch = aggregator.snapshot_batch()
    if not batch:
        return  # nothing to report this cycle — no empty POSTs
    payload = [inst.to_payload() for inst in batch]
    ok, response = await relay.post_liveness(payload)
    if not ok:
        # Non-fatal by design: alive entries are rebuilt fresh from current
        # state every cycle regardless (nothing to "retry" for them); pending
        # disconnect markers stay queued (bounded, see aggregator) and are
        # retried on the next cycle.
        logger.warning(
            "liveness push failed (non-fatal, retrying next cycle)",
            extra={"batchSize": len(payload)},
        )
        return
    aggregator.mark_pushed(batch)
    unknown = response.get("unknown") if isinstance(response, dict) else None
    if unknown:
        # PHP has no capability row for these — liveness NEVER creates rows,
        # only register() does (see module docstring). The gateway has no
        # channel to force a worker to re-register — that call is initiated by
        # the worker itself on ITS OWN connect (workers/common/ws_client.py
        # ::_register()); forcibly disconnecting the worker to provoke a
        # reconnect would interrupt any in-flight job it's processing purely
        # for a telemetry housekeeping concern, which is a worse trade-off than
        # letting this persist. NOT bounded to one push cycle: if PHP GCs a
        # worker's row while its WS connection stays open, NOTHING re-creates
        # that row until the connection eventually drops and reconnects for an
        # unrelated reason — `unknown` can keep firing for the rest of that
        # connection's life, not just once. Loud ERROR log is the deliberate
        # minimum bar here (real fix — escalate after N consecutive cycles, or
        # a periodic self-register — is a separate grooming card, not this
        # one). Note: the identical log also fires for a benign one-off race
        # (a ping lands before this worker's own register() HTTP call has
        # landed) — the log alone can't tell the two apart; see registry-06
        # Execution Log for both cases.
        for entry in unknown:
            logger.error(
                "liveness push: PHP has no capability row for this instance "
                "(never registered, or its row was already GC'd) — gateway "
                "cannot force a remote re-register; surfacing for ops",
                extra={
                    "workerType": entry.get("workerType") if isinstance(entry, dict) else None,
                    "instanceId": entry.get("instanceId") if isinstance(entry, dict) else None,
                },
            )
