"""KeyDB Streams metrics exporter — Prometheus sidecar for the convertor project.

Discovers conv.* stream keys dynamically via SCAN, then emits Prometheus
gauges/counters for lag, PEL, consumer count, and DLQ size.

Environment variables (all optional):
    REDIS_HOST            KeyDB hostname          (default: keydb)
    REDIS_PORT            KeyDB port              (default: 6379)
    REDIS_DB              KeyDB database index    (default: 2)
    REDIS_PASSWORD        KeyDB password          (default: empty = no AUTH)
    CONSUMER_GROUP        Stream consumer group   (default: convertor)
    METRICS_PORT          HTTP exposition port    (default: 9472)
    METRICS_POLL_INTERVAL Poll interval seconds   (default: 15)
"""

from __future__ import annotations

import logging
import os
import threading
import time

import redis
from prometheus_client import Counter, Gauge, start_http_server

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

REDIS_HOST = os.getenv("REDIS_HOST", "keydb")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "2"))
_REDIS_PASSWORD: str | None = os.getenv("REDIS_PASSWORD", "") or None

CONSUMER_GROUP = os.getenv("CONSUMER_GROUP", "convertor")
METRICS_PORT = int(os.getenv("METRICS_PORT", "9472"))
POLL_INTERVAL = float(os.getenv("METRICS_POLL_INTERVAL", "15"))

# Redis socket timeout: short so a dead KeyDB doesn't hang the scrape.
_SOCKET_TIMEOUT = 5.0

# Maximum entries to scan when computing lag via XRANGE fallback (bounded cost).
_LAG_XRANGE_CAP = 50_000

# ---------------------------------------------------------------------------
# Prometheus metrics
# ---------------------------------------------------------------------------

_stream_length = Gauge(
    "convertor_stream_length",
    "Total number of entries in a conv.* stream (XLEN).",
    ["stream"],
)
_group_pending = Gauge(
    "convertor_stream_group_pending",
    "Pending entries list (PEL) size — delivered but not yet ACKed.",
    ["stream", "group"],
)
_group_lag = Gauge(
    "convertor_stream_group_lag",
    "Undelivered backlog — entries not yet read by the consumer group.",
    ["stream", "group"],
)
_group_consumers = Gauge(
    "convertor_stream_group_consumers",
    "Number of consumers registered in the group.",
    ["stream", "group"],
)
_pending_max_idle = Gauge(
    "convertor_stream_pending_max_idle_ms",
    "Idle time (ms) of the oldest pending entry (XPENDING summary).",
    ["stream", "group"],
)
_dead_letter = Gauge(
    "convertor_dead_letter_messages",
    "Number of messages in the dead-letter stream (conv.dead).",
)
_scrape_errors = Counter(
    "convertor_exporter_scrape_errors_total",
    "Total number of failed scrape cycles.",
)
_exporter_up = Gauge(
    "convertor_exporter_up",
    "1 if the last scrape succeeded; 0 on error.",
)


# ---------------------------------------------------------------------------
# Redis connection
# ---------------------------------------------------------------------------

def _connect() -> redis.Redis:
    return redis.Redis(
        host=REDIS_HOST,
        port=REDIS_PORT,
        db=REDIS_DB,
        password=_REDIS_PASSWORD,
        decode_responses=True,
        socket_timeout=_SOCKET_TIMEOUT,
        socket_connect_timeout=_SOCKET_TIMEOUT,
    )


# ---------------------------------------------------------------------------
# Metric collection helpers
# ---------------------------------------------------------------------------

def _discover_streams(r: redis.Redis) -> list[str]:
    """Return keys matching conv.* whose TYPE is stream."""
    streams = []
    cursor = 0
    while True:
        cursor, keys = r.scan(cursor=cursor, match="conv.*", count=100)
        for key in keys:
            try:
                if r.type(key) == "stream":
                    streams.append(key)
            except redis.RedisError:
                pass
        if cursor == 0:
            break
    return streams


def _compute_lag(r: redis.Redis, stream: str, group_info: dict, xlen: int) -> float:
    """Compute the group lag (undelivered entries).

    Strategy (in priority order):
    1. XINFO GROUPS 'lag' field — present in Redis ≥7.0. KeyDB (eqalpha/keydb,
       based on Redis 6.x) does NOT return this field, so the fallback below
       is the active path for this project (lag-field support not live-verified;
       XRANGE fallback is the expected active path).
    2. XINFO GROUPS 'entries-read' field — added in Redis 7.0 as part of XINFO
       GROUPS (NOT XINFO STREAM); also absent on KeyDB 6.x so this branch is
       skipped in practice.
    3. XRANGE count after last-delivered-id — bounded at _LAG_XRANGE_CAP entries.
       Accurate up to cap. This is the active code path for KeyDB 6.x.
    """
    lag = group_info.get("lag")
    if lag is not None:
        return float(lag)

    # Redis 7.0+: XINFO GROUPS includes 'entries-read' (total entries delivered).
    # 'entries-added' lives on XINFO STREAM, not XINFO GROUPS — so we use
    # xlen as a proxy for entries-added here (over-counts if trim is active,
    # but acceptable as an approximation).
    entries_read = group_info.get("entries-read")
    if entries_read is not None:
        return float(max(0, xlen - int(entries_read)))

    # Fallback: count entries in the stream after last-delivered-id.
    # Cost is O(min(backlog, _LAG_XRANGE_CAP)) — bounded.
    last_id = group_info.get("last-delivered-id", "0-0")
    if not last_id or last_id == "0-0":
        # No entry delivered yet → full stream is unread.
        return float(xlen)

    try:
        # Exclusive lower bound via "(id" syntax (Redis 6.2+).
        entries = r.xrange(stream, f"({last_id}", "+", count=_LAG_XRANGE_CAP)
        return float(len(entries))
    except redis.RedisError:
        # Graceful degradation: return xlen as an upper-bound estimate.
        return float(xlen)


def _collect_stream(r: redis.Redis, stream: str) -> None:
    """Collect all metrics for a single stream + its consumer group."""
    xlen = r.xlen(stream)
    _stream_length.labels(stream=stream).set(xlen)

    try:
        groups = r.xinfo_groups(stream)
    except redis.RedisError as exc:
        logger.debug("xinfo_groups failed for %s: %s", stream, exc)
        return

    for g in groups:
        name = g.get("name", "")
        if name != CONSUMER_GROUP:
            continue

        pending = int(g.get("pending", 0))
        consumers = int(g.get("consumers", 0))

        _group_pending.labels(stream=stream, group=name).set(pending)
        _group_consumers.labels(stream=stream, group=name).set(consumers)

        lag = _compute_lag(r, stream, g, xlen)
        _group_lag.labels(stream=stream, group=name).set(lag)

        # Idle time of the oldest PEL entry via XPENDING range.
        # xpending() summary returns min/max as ID strings (no idle time).
        # xpending_range(..., count=1) returns [{'time_since_delivered': ms, ...}].
        # Always set the gauge (0 when PEL empty) so stale high values cannot linger.
        idle_ms = 0.0
        if pending > 0:
            try:
                oldest = r.xpending_range(stream, name, min="-", max="+", count=1)
                if oldest:
                    raw = oldest[0].get("time_since_delivered")
                    if raw is not None:
                        idle_ms = float(raw)
            except redis.RedisError:
                pass  # optional metric — leave idle_ms=0
        _pending_max_idle.labels(stream=stream, group=name).set(idle_ms)


def _scrape(r: redis.Redis) -> None:
    """Single scrape cycle: discover streams, collect metrics, update DLQ."""
    streams = _discover_streams(r)

    for stream in streams:
        try:
            _collect_stream(r, stream)
        except redis.RedisError as exc:
            # Transient single-stream error (e.g. key deleted between SCAN and XLEN).
            # Log and continue — do not fail the whole scrape cycle.
            logger.warning("skipping stream %s due to transient error: %s", stream, exc)

    # Explicit dead-letter gauge (conv.dead may or may not be in SCAN results;
    # force-set to 0 when absent so the gauge is always present).
    dead_len = 0
    if "conv.dead" in streams:
        try:
            dead_len = r.xlen("conv.dead")
        except redis.RedisError:
            pass
    else:
        # Ensure the stream exists before trying XLEN
        try:
            t = r.type("conv.dead")
            if t == "stream":
                dead_len = r.xlen("conv.dead")
        except redis.RedisError:
            pass

    _dead_letter.set(dead_len)


# ---------------------------------------------------------------------------
# Background polling loop
# ---------------------------------------------------------------------------

def _poll_loop() -> None:
    while True:
        try:
            r = _connect()
            _scrape(r)
            _exporter_up.set(1)
        except Exception as exc:
            logger.warning("scrape failed: %s", exc)
            _scrape_errors.inc()
            _exporter_up.set(0)

        time.sleep(POLL_INTERVAL)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    logger.info(
        "starting metrics exporter",
        extra={
            "port": METRICS_PORT,
            "poll_interval": POLL_INTERVAL,
            "redis": f"{REDIS_HOST}:{REDIS_PORT}/{REDIS_DB}",
            "group": CONSUMER_GROUP,
        },
    )

    start_http_server(METRICS_PORT)

    t = threading.Thread(target=_poll_loop, daemon=True, name="poll-loop")
    t.start()

    # Block main thread; daemon thread stops when process exits.
    t.join()


if __name__ == "__main__":
    main()
