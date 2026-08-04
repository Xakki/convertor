"""Liveness aggregation + periodic push to PHP (registry-06/09, gateway half).

registry-09 (gateway = SOURCE OF TRUTH for "who is connected right now"):
the push is no longer a pure delta. Every cycle carries the FULL current
alive-set plus an envelope flag `snapshot: true` telling PHP it may RECONCILE
its `worker_capabilities` rows against it (rows that no gateway has reported
for a whole silence window flip to `disconnected`) — see
`App\\Service\\Worker\\WorkerLivenessReconciler` for the exact invariant, which
is owned by PHP, not by this file. Additionally, an instance PHP reports back
as `unknown` (no capability row: never registered, or GC'd) is now ACTIONABLE:
the gateway sends it a `re-register` control frame over its live WS connection
(rate-limited per instance), instead of only logging an ERROR forever.

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
implementation — see registry-06 card; envelope keys added by registry-09):
    POST /api/v1/internal/worker/liveness
    {"instances": [{"workerType": "...", "instanceId": "...", "status": "alive"
                     | "disconnected", "lastSeenAt": "<ISO-8601 UTC>",
                     "metrics": {"cpu": ..., "mem": ..., "load": ...} | omitted,
                     "inflight": <int> | omitted}],  # CNV-61: omitted = unknown,
                     # never negative; PHP treats missing/None as "unknown"
     "snapshot": true,            # `instances` holds the FULL alive-set
     "authoritative": true|false, # false = gateway still warming up, do NOT sweep
     "gatewayId": "..."}          # diagnostic only, never a state key
    → {"updated": <int>, "unknown": [{"workerType": "...", "instanceId": "..."}],
       "offlined": <int>}         # `offlined` absent on an older PHP build

Backward compatibility both ways: an older PHP ignores the extra top-level keys
and keeps applying deltas; an older gateway sends no `snapshot` key and PHP then
skips reconciliation entirely (delta-only, exactly the registry-06 behaviour).

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
import time
from collections.abc import Awaitable, Callable
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

# registry-09: `WsGateway.request_reregister` — (workerType, instanceId) → was
# the `re-register` control frame actually delivered? Typed as a protocol-free
# callable so `liveness.py` keeps zero import-coupling to `ws_server.py`
# (ws_server already imports THIS module — the reverse import would be a cycle).
ReRegisterFn = Callable[[str, str], Awaitable[bool]]


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
    # inflight (CNV-61): len(Credits.inflight) at the moment of the last ping —
    # how many jobs this instance currently holds in-flight. None = unknown
    # (never pinged with credits available yet, or ping arrived off a path
    # with no Credits in scope) — kept distinct from 0 (genuinely idle) so PHP
    # doesn't mistake "we don't know" for "this worker is empty".
    inflight: int | None = None
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
        # Optional on the wire (see module docstring) — omitted entirely rather
        # than sent as null, matching the existing `metrics` convention above.
        if self.inflight is not None:
            payload["inflight"] = self.inflight
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
        # registry-09: monotonic timestamp of the LAST `re-register` control
        # frame requested per key — the rate-limit state for
        # `should_request_reregister`. Bounded by construction: pruned against
        # `_alive` on every call (an instance that went away can never be asked
        # to re-register again, so keeping its timestamp is pure leak).
        self._reregister_at: dict[tuple[str, str], float] = {}

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
        inflight: int | None = None,
    ) -> None:
        """Update latest metrics + lastSeenAt for an already-connected instance.
        Silently ignored (no per-ping log spam — already warned at connect) if
        the key is invalid/missing.

        `inflight` (CNV-61): caller passes `len(Credits.inflight)` from the
        ping path where credits are in scope; left `None` (default) when the
        caller has no Credits to read — kept `None` rather than coerced to 0
        so "unknown" stays distinguishable from "genuinely idle" (never clamp
        a real count, but never fabricate one either)."""
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
        inst.inflight = inflight
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
        # inflight (CNV-61): unlike cpu/mem/load (harmless "what it was doing"
        # history), a stale in-flight count on a DISCONNECTED instance is
        # actively misleading — those jobs get reclaimed elsewhere, so "still
        # holding 2 jobs" on a dead host would read as a ghost/stuck worker.
        # Reset to unknown rather than carrying the last-alive value forward.
        inst.inflight = None
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

    def is_alive(self, worker_type: str, instance_id: str) -> bool:
        """Is this instance CURRENTLY holding an open, tracked WS connection?
        Used before asking it to re-register — there is no point (and no
        channel) to nudge an instance the gateway no longer holds."""
        return (worker_type, instance_id) in self._alive

    def should_request_reregister(
        self, worker_type: str, instance_id: str, cooldown_s: float
    ) -> bool:
        """Rate-limit gate for the `re-register` control frame (registry-09).

        PHP reports the SAME instance as `unknown` on every single push cycle
        until a row exists, so an ungated nudge would fire every
        `liveness_push_interval_s` (30 s) for the whole life of the connection.
        Returns True at most once per `cooldown_s` per instance, and only while
        the instance is actually alive; a True return ALSO records the attempt
        (call it exactly once per intended nudge).
        """
        key = (worker_type, instance_id)
        if key not in self._alive:
            return False
        now = time.monotonic()
        # Prune while we are here: keys whose connection is gone can never be
        # nudged again (guard above), so their timestamps are dead weight.
        for stale_key in [k for k in self._reregister_at if k not in self._alive]:
            del self._reregister_at[stale_key]
        last = self._reregister_at.get(key)
        if last is not None and (now - last) < cooldown_s:
            return False
        self._reregister_at[key] = now
        return True

    def __len__(self) -> int:
        return len(self._alive) + len(self._pending_disconnects)


async def run_liveness_push_loop(
    aggregator: LivenessAggregator,
    relay: RelayClient,
    cfg: Config,
    request_reregister: ReRegisterFn | None = None,
) -> None:
    """Async task: periodic liveness batch push. Started via `asyncio.create_task`
    from `__main__.py` alongside the WS server / reclaim-loop / DLQ-consumer.
    Terminates on `CancelledError` (graceful shutdown) — every other failure mode
    is swallowed (logged, never propagated) so it can never take the gateway
    down with it.

    `request_reregister` (registry-09) — `WsGateway.request_reregister`: asks a
    still-connected worker to POST `register()` again. Optional so the loop
    stays unit-testable standalone; when absent, an `unknown` instance degrades
    to the registry-06 behaviour (log only).

    `authoritative` is False for the first `cfg.liveness_snapshot_warmup_s` of
    this loop's life: right after a gateway restart the alive-set is legitimately
    partial (workers are still reconnecting with backoff), and a sweep off an
    obviously-incomplete view is never worth attempting.
    """
    logger.info(
        "liveness push loop started",
        extra={
            "intervalS": cfg.liveness_push_interval_s,
            "warmupS": cfg.liveness_snapshot_warmup_s,
            "gatewayId": cfg.gateway_id,
        },
    )
    started = time.monotonic()
    while True:
        try:
            await asyncio.sleep(cfg.liveness_push_interval_s)
        except asyncio.CancelledError:
            logger.info("liveness push loop cancelled")
            return
        authoritative = (time.monotonic() - started) >= cfg.liveness_snapshot_warmup_s
        try:
            await _push_once(
                aggregator,
                relay,
                gateway_id=cfg.gateway_id,
                authoritative=authoritative,
                request_reregister=request_reregister,
                reregister_cooldown_s=cfg.liveness_reregister_cooldown_s,
            )
        except asyncio.CancelledError:
            logger.info("liveness push loop cancelled during push")
            return
        except Exception as exc:  # noqa: BLE001 — telemetry-only, never crash the loop
            logger.warning("liveness push cycle failed unexpectedly", extra={"error": str(exc)})


async def _push_once(
    aggregator: LivenessAggregator,
    relay: RelayClient,
    *,
    gateway_id: str = "",
    authoritative: bool = False,
    request_reregister: ReRegisterFn | None = None,
    reregister_cooldown_s: float = 300.0,
) -> None:
    batch = aggregator.snapshot_batch()
    # registry-09: an EMPTY batch is now a meaningful statement ("this gateway
    # holds no connections at all"), not just "nothing to say" — so once the
    # snapshot is authoritative we still POST it, letting PHP reconcile away
    # rows that no gateway has claimed. While NOT authoritative the old
    # behaviour stands (no empty POSTs): an empty warm-up push carries zero
    # information PHP is allowed to act on.
    if not batch and not authoritative:
        return
    payload = [inst.to_payload() for inst in batch]
    meta = {
        "snapshot": True,
        "authoritative": authoritative,
        "gatewayId": gateway_id,
    }
    ok, response = await relay.post_liveness(payload, meta)
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
        await _handle_unknown(
            aggregator, unknown, request_reregister, reregister_cooldown_s
        )


async def _handle_unknown(
    aggregator: LivenessAggregator,
    unknown: list,
    request_reregister: ReRegisterFn | None,
    cooldown_s: float,
) -> None:
    """PHP has no capability row for these instances — liveness NEVER creates
    rows, only `register()` does (see module docstring).

    registry-09 makes this SELF-HEALING instead of a permanent ERROR log: the
    gateway holds the worker's live WS connection, so it asks that worker to
    run its own `register()` again (`re-register` control frame, handled by
    `workers/common/ws_client.py::_reader_loop`). This is the fix for the real
    failure mode observed in production — a worker whose one-shot `_register()`
    lost a race during a deploy (or whose row was GC'd while its connection
    stayed open) previously stayed invisible to the admin page FOREVER, since
    `register()` only ever fires on the worker's own reconnect.

    Deliberately NOT done: dropping the WS connection to provoke a reconnect —
    that would interrupt an in-flight conversion for a housekeeping concern.

    Rate-limited per instance (`should_request_reregister`) because PHP repeats
    the same `unknown` entry on every push cycle until a row exists. An
    instance we cannot nudge (no live connection here, or still in cooldown)
    falls back to the registry-06 behaviour: a log line, no action.
    """
    for entry in unknown:
        if not isinstance(entry, dict):
            continue
        worker_type = entry.get("workerType")
        instance_id = entry.get("instanceId")
        ctx = {"workerType": worker_type, "instanceId": instance_id}
        if (
            request_reregister is None
            or not isinstance(worker_type, str)
            or not isinstance(instance_id, str)
            or not aggregator.is_alive(worker_type, instance_id)
        ):
            # Nothing to nudge: either no channel wired (unit-test/older build)
            # or the instance is no longer connected here. Benign one-off race
            # (a push landing before the worker's own register() HTTP call) also
            # ends up here — hence WARNING, not ERROR.
            logger.warning(
                "liveness push: PHP has no capability row for this instance and "
                "the gateway holds no live connection to nudge — cannot self-heal",
                extra=ctx,
            )
            continue
        if not aggregator.should_request_reregister(worker_type, instance_id, cooldown_s):
            logger.debug("re-register suppressed by cooldown", extra=ctx)
            continue
        try:
            sent = await request_reregister(worker_type, instance_id)
        except Exception as exc:  # noqa: BLE001 — telemetry path, never crash the loop
            logger.warning("re-register request failed", extra={**ctx, "error": str(exc)})
            continue
        logger.info(
            "liveness push: PHP has no capability row for this instance — "
            "asked the worker to re-register" if sent else
            "liveness push: re-register frame could not be delivered "
            "(connection closed between snapshot and send)",
            extra=ctx,
        )
