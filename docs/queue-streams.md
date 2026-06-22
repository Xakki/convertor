# Stream Subscription & Distribution — Mechanics

> Cross-references: [queue-contract.md](queue-contract.md) (wire formats §1–§5) ·
> [queue-redesign-design.md](queue-redesign-design.md) (design rationale & phased cards).
>
> Authoritative implementation: `workers/common/stream_consumer.py` (`StreamConsumerBase`).
> PHP routing authority: `App\Service\Conversion\ConversionRegistry` + `ConversionManager::dispatch`.

---

## 1. Topology

### Job streams — PHP produces, Python consumes

| Stream           | Routing key | Worker category |
|------------------|-------------|-----------------|
| `conv.document`  | `document`  | document (LibreOffice/Pandoc); `markup` folds in here |
| `conv.image`     | `image`     | image (Pillow); also owns OCR raster path |
| `conv.audio`     | `audio`     | audio/ffmpeg |
| `conv.video`     | `video`     | video/ffmpeg |
| `conv.data`      | `data`      | structured-data |
| `conv.ai`        | `ai`        | AI (STT / TTS / GPT-OCR) |

**Routing key formula (PHP):** `key = isAi ? 'ai' : category`.
`markup` category is stored in the DB but folded to `document` at routing time —
`ConversionRegistry::streamFor()` returns `'document'` for any markup pair.
OCR override: when `$ocr=true`, `streamFor()` always returns `'image'`, regardless of category.
The Symfony Messenger transport name is `conv_<key>`; the stream name is `conv.<key>`.

### System streams

| Stream       | Direction              | Purpose |
|--------------|------------------------|---------|
| `conv.result`| Python → PHP           | Result events (success & failure); consumed by `app:queue:result-consumer` |
| `conv.dead`  | Python (DLQ)           | Permanently failed entries; not consumed automatically |

### Status hash

`conv:status:{conversionId}` — live Redis HASH, TTL 24 h. Written by workers,
read by the PHP status endpoint. Schema: queue-contract.md §4.
MariaDB remains the durable source for `/history` and `/download` once the hash expires.

---

## 2. Consumer groups & consumers

**One consumer group per stream**, named `convertor`. Workers create the group on startup:

```
XGROUP CREATE conv.<key> convertor 0 MKSTREAM
```

Start-id `0` (not `$`) is mandatory — using `$` would silently skip every message
enqueued before the worker first connects. `MKSTREAM` creates the stream if it doesn't
exist yet (no separate provisioning step needed).

**Consumer name:** `{hostname}-{pid}` — e.g. `worker-document-c7a3f-42`. Constructed
by `_consumer_name()` in `stream_consumer.py` (`socket.gethostname() + "-" + str(os.getpid())`).
Each container process registers as a distinct named consumer in the group.

**Horizontal scaling:** N instances of the same worker type → N consumers in the same
group → each message is delivered to **exactly one** consumer. No duplicate processing.
Scale-out = add instances; no config change required.

---

## 3. Reclaim (XAUTOCLAIM)

Entries that have been delivered but not ACKed stay in the PEL (Pending Entry List).
If the processing worker crashes or hangs, those entries would be stuck forever without
active reclaim.

Every `CONSUMER_IDLE_MS` (default **5 min**) the main loop calls `_reclaim_stuck()`:

```
XAUTOCLAIM conv.<key> convertor <self-consumer> MIN-IDLE-TIME=CONSUMER_IDLE_MS START-ID=0-0 COUNT=CONSUMER_RECLAIM_BATCH
```

- **`CONSUMER_IDLE_MS`** — idle threshold in ms; an entry idle ≥ this long is considered stuck (default 300 000 ms = 5 min).
- **`CONSUMER_RECLAIM_BATCH`** — max entries to reclaim per pass (default 10).

Reclaimed entries are passed through `_process_entry()` — the same code path as
freshly consumed entries, including the idempotency guard, delivery-count check, and
retry/DLQ gate.

> Redis 6.2+ required for `XAUTOCLAIM`. The code handles a graceful fallback warning
> if the command is unsupported (Redis < 6.2).

---

## 4. Retries & DLQ

**Delivery count** is read from the PEL via `XPENDING_RANGE` at the start of each
`_process_entry()` call. A message that fails processing is left **unacked** (no XACK);
after `CONSUMER_IDLE_MS` it is reclaimed and redelivered, incrementing the PEL
delivery count.

If `delivery_count > CONSUMER_MAX_RETRIES` (default **3**), i.e. the entry has been
attempted **at least 4 times**, the entry is routed to the DLQ:

1. `XADD conv.dead` — dead-letter record.
2. `HSET conv:status:{id}` — state = `failed`.
3. `XADD conv.result` — failed result event (notifies PHP; triggers quota refund).
4. `XACK` original stream — removes entry from PEL.

A processing failure (S3 download error, `convert()` exception, S3 upload error)
sets the error field on the status hash but issues **no XACK** — the entry stays in
the PEL and retries after the idle timeout.

**Dead-letter entry shapes:**

Max-retries exceeded:
```json
{
  "conversionId": 123,
  "state": "failed",
  "reason": "max_retries (3) exceeded",
  "originalStream": "conv.document",
  "originalEntryId": "1719792000000-0"
}
```

Parse error (malformed message — sent to DLQ immediately, no retry):
```json
{
  "entryId": "1719792000000-0",
  "reason": "parse_error",
  "stream": "conv.document"
}
```

Both shapes use the single field `data` (raw JSON string), matching the `conv.result`
wire convention (queue-contract.md §5, "plain payload, not a Messenger envelope").

---

## 5. Idempotency & ordered commit

### Idempotency guard

At the start of `_process_entry()`, before any work:

```python
if redis.hget(f"conv:status:{conv_id}", "state") == "completed":
    # Re-emit the result event in case the original XADD was lost after HSET.
    # PHP deduplicates by conversionId; a duplicate event is harmless.
    _re_emit_completed_result(...)
    redis.xack(stream, _GROUP, entry_id)
    return
```

This covers the crash-between-HSET-and-XACK gap: on redelivery the worker detects
`completed`, re-emits the result event (safe because PHP deduplicates on `conversionId`),
and ACKs.

### Ordered commit sequence (success path)

```
(1) S3 PUT     results/{Y}/{M}-{D}/{convId}.{ext}
               Deterministic key → safe to overwrite on retry; idempotent.
(2) HSET       conv:status:{id}  state=completed + output metadata
(3) XADD       conv.result       (§5 result event — see queue-contract.md)
(4) XACK       removes entry from PEL
```

**Why this order:**

- **S3 first:** a crash after PUT but before HSET causes a retry that re-PUTs the same
  deterministic key (clean overwrite, no orphan objects).
- **XACK last:** a crash between XADD and XACK triggers a redelivery; the idempotency
  guard at step 0 catches it, re-emits the result event, and ACKs — no double conversion.

Tmp files (local input + output) are cleaned up in a `finally` block on every exit path
(success or failure); retries re-download from S3 and re-run the conversion.

---

## 6. Backpressure & ordering

`CONSUMER_READ_COUNT` defaults to **1**. Each `XREADGROUP` call fetches at most one
entry; the worker processes it completely (download → convert → ordered commit) before
reading the next.

Implications:
- **Per-instance serial processing** — a single instance processes tasks one at a time.
- **Concurrency = instance count** — add worker containers to increase parallel
  capacity for a category.
- **Cross-category independence** — different category streams are consumed by separate
  worker processes; a slow video conversion does not block document conversions.

To increase throughput for a specific category: scale out that worker type.
`CONSUMER_READ_COUNT` can be raised via env for bulk scenarios where conversion latency
is low and you want to pipeline reads, but the default ensures safe, ordered processing.

---

## 7. Metrics & observability

A sidecar container **`convertor-metrics-exporter`** polls KeyDB streams and groups,
exposing Prometheus metrics. Dockprom Prometheus scrapes the exporter; dashboards and
alerts live in Grafana at `mon.xakki.ru`.

| Metric | Labels | Meaning |
|--------|--------|---------|
| `convertor_stream_length` | `stream` | Total entries currently in the stream (XLEN). Includes delivered-but-not-yet-ACKed entries. |
| `convertor_stream_group_pending` | `stream`, `group` | PEL size: entries delivered to the group but not yet ACKed. Non-zero at steady state = work is in-flight or stuck. |
| `convertor_stream_group_lag` | `stream`, `group` | Undelivered backlog: entries in the stream not yet delivered to any consumer. A rising value means consumers cannot keep up. |
| `convertor_stream_group_consumers` | `stream`, `group` | Number of registered consumers in the group. Drops to 0 if all workers for a stream are down. |
| `convertor_dead_letter_messages` | — | XLEN of `conv.dead`. Any growth indicates permanently failing jobs. |
| `convertor_stream_pending_max_idle_ms` | `stream`, `group` | Idle time (ms) of the oldest pending entry. A large value indicates a stalled consumer holding entries in the PEL without ACKing. |
| `convertor_exporter_scrape_errors_total` | — | Counter; increments each time a full poll cycle fails (KeyDB unreachable). Complements `convertor_exporter_up`. |
| `convertor_exporter_up` | — | 1 if the exporter reached KeyDB successfully, 0 otherwise. |

**"Queue is backing up":** `convertor_stream_group_lag` rises while
`convertor_stream_group_consumers` stays flat or falls. Action: scale out the
affected worker type.

**"Tasks dying":** `convertor_dead_letter_messages` grows. Investigate with
`XRANGE conv.dead - + COUNT 20`, correlate `conv:status:{id}` hashes for error
messages, and check container logs in Graylog / Portainer.

---

## 8. Drift protection

`make test-drift` runs the drift test as part of CI. It verifies:

1. **Stream coverage:** every routing-key (`document`, `image`, `audio`, `video`,
   `data`, `ai`) has at least one worker declaring it in `CAPABILITIES["routing_keys"]`.
2. **Matrix subset:** every `(from, to)` pair in a worker's `CAPABILITIES["matrix"]`
   is present in `ConversionRegistry` (worker matrix ⊆ registry).

This prevents two silent failure modes:
- A stream exists in PHP routing but no worker listens → jobs accumulate forever.
- A worker claims pairs the registry doesn't know about → those conversions are
  never dispatched to that worker (dead capability).

A drift-test failure blocks the deployment.

---

## Environment variables reference

All knobs are read at startup from the worker container's environment.

| Variable | Default | Meaning |
|----------|---------|---------|
| `REDIS_HOST` | `keydb` | KeyDB hostname |
| `REDIS_PORT` | `6379` | KeyDB port |
| `REDIS_DB` | `2` | DB index (0=cache, 1=sessions, 2=queues) |
| `REDIS_PASSWORD` | _(empty)_ | AUTH password; empty = no AUTH |
| `CONSUMER_BLOCK_MS` | `5000` | Blocking timeout on `XREADGROUP`; if no message arrives within this window the call returns empty and the loop continues |
| `CONSUMER_READ_COUNT` | `1` | Max entries per `XREADGROUP` call |
| `CONSUMER_IDLE_MS` | `300000` | PEL reclaim threshold (ms); also the reclaim pass interval |
| `CONSUMER_MAX_RETRIES` | `3` | Max delivery attempts before routing to `conv.dead` |
| `CONSUMER_RECLAIM_BATCH` | `10` | Max entries reclaimed per `XAUTOCLAIM` pass |
| `S3_BUCKET_PREFIX` | `convertor` | Bucket name prefix (`convertor-inputs`, `convertor-results`) |
| `S3_PREFIX` | _(empty)_ | Key prefix for worker-generated S3 objects; set to e.g. `test_` for e2e test isolation |
| `WORK_DIR` | system tmp | Local directory for tmp input/output files during conversion |
