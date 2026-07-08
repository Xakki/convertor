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
| `conv.dead`  | gateway (DLQ)          | Permanently failed entries; not consumed automatically |

### Status hash

`conv:status:{conversionId}` — live Redis HASH, TTL 24 h. Written by **gateway**
(not workers), read by the PHP status endpoint. Schema: queue-contract.md §4.
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
2. `HSET conv:status:{id}` — state = `failed` (gateway writes this).
3. `XACK` original stream — removes entry from PEL.
4. Gateway sends `fail{permanent:true}` relay → PHP triggers quota refund.

Workers return `ResultSignal.failed(permanent=True)` for permanent errors
(ValueError); gateway handles DLQ routing and XACK.

**Dead-letter entry shapes (written by gateway):**

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

---

## 5. Result commit sequence (gateway-owned)

Workers deliver results via WS (`ResultSignal`) to the gateway. The gateway owns
all KeyDB writes and the PHP relay call.

**Success path (inline ≤256 KB):**
```
(1) worker → completion{data: base64}  (WS frame)
(2) gateway → POST /internal/relay     (Symfony ConversionResultPersister: S3 + MariaDB)
(3) gateway → HSET conv:status:{id}    state=completed
(4) gateway → XACK                     removes entry from PEL
```

**Success path (large, >256 KB):**
```
(1) worker → POST /api/v1/worker/jobs/{id}/result  (multipart, Symfony writes S3 + MariaDB)
(2) worker → result{resultKey}  (WS frame)
(3) gateway → HSET conv:status:{id}  state=completed
(4) gateway → XACK
```

Stream `conv.result` and `app:queue:result-consumer` are retired (s1-10).
See `docs/superpowers/specs/2026-07-02-ws-worker-transport-design.md`.

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
