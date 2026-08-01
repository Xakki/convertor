# Stream Subscription & Distribution — Mechanics

> Cross-references: [queue-contract.md](queue-contract.md) (wire formats §1–§5) ·
> [queue-redesign-design.md](queue-redesign-design.md) (design rationale & phased cards).
>
> Authoritative implementation: `workers/gateway/keydb.py` (`KeyDbGateway` — `XGROUP CREATE` /
> `XREADGROUP` / `XACK` / `XAUTOCLAIM`; the gateway is the **sole** reader/writer of KeyDB
> Streams), `workers/gateway/reclaim.py` (idle-reclaim loop), `workers/gateway/ws_server.py`
> (credit-based dispatch). Worker-side transport: `workers/common/ws_client.py` (`WsClient`) —
> a WS client only, it never touches KeyDB or S3 directly.
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
В seed __seed__ нет пар с `category=markup` (0 строк); live md/html/htm хранятся как `document`.
Если пара когда-либо получит `markup`, `ConversionRegistry::streamFor()` сворачивает её в `'document'`.
OCR override: when `$ocr=true`, `streamFor()` always returns `'image'`, regardless of category.
The Symfony Messenger transport name is `conv_<key>`; the stream name is `conv.<key>`.

### System streams

| Stream       | Direction              | Purpose |
|--------------|------------------------|---------|
| `conv.dead`  | gateway (DLQ)          | Permanently failed entries; consumed by the gateway `dlq-consumer` → relay `POST /api/v1/internal/worker/dlq-fail` → `Conversion.status=failed` (idempotent). Operator-requeue: `POST /api/v1/admin/dead-letter/requeue` |

### Status hash

`conv:status:{conversionId}` — live Redis HASH, TTL 24 h. Written by **gateway**
(not workers), read by the PHP status endpoint. Schema: queue-contract.md §4.
MariaDB remains the durable source for `/history` and `/download` once the hash expires.

---

## 2. Consumer groups & consumers

**One consumer group per stream**, named `convertor`. The **gateway** — the sole
reader/writer of KeyDB Streams — ensures the group exists, lazily on first read of each
stream (memoized per-process, `KeyDbGateway.ensure_group()` in `workers/gateway/keydb.py`):

```
XGROUP CREATE conv.<key> convertor 0 MKSTREAM
```

Start-id `0` (not `$`) is mandatory — using `$` would silently skip every message
enqueued before the gateway first reads that stream. `MKSTREAM` creates the stream if it
doesn't exist yet (no separate provisioning step needed). `BUSYGROUP` (group already
exists) is swallowed; any other error is fatal.

**Consumer name:** the worker's stable `worker_id` (`WsClientConfig.worker_id`,
WS-transport model) — supplied verbatim via the WS handshake `workerId` field and used
as-is by the gateway (`consumer = session.worker_id` in `workers/gateway/ws_server.py`,
`_dispatch`) for `XREADGROUP`/`XACK`/`XAUTOCLAIM`. The worker itself never issues these
commands — it only sends/receives WS frames. No PID component: the name MUST stay stable
across reconnects, or the consumer group leaks entries and in-flight resume stalls.
`WORKER_ID` defaults to the container hostname when unset (`workers/common/ws_client.py`,
`_default_worker_id()`) — pin the hostname (or set `WORKER_ID` explicitly) if it needs to
survive container recreation. Each WS connection registers as a distinct named consumer
in the group (on the gateway side).

**Horizontal scaling:** N worker connections of the same worker type → N consumers in the
same group → each message is delivered to **exactly one** consumer. No duplicate
processing. Scale-out = add worker instances; no config change required.

---

## 3. Reclaim (XAUTOCLAIM)

Entries that have been delivered but not ACKed stay in the PEL (Pending Entry List).
If the worker holding an entry crashes, disconnects, or hangs, those entries would be
stuck forever without active reclaim. Reclaim is entirely gateway-owned — workers never
issue `XAUTOCLAIM` (or any other KeyDB command).

**Global idle-reclaim loop** (`workers/gateway/reclaim.py`, `run_reclaim_loop` /
`_sweep_all_types`) runs every `RECLAIM_INTERVAL_S` (default **60 s**) and, for each
`conv.<type>` stream, reclaims entries idle longer than a **per-type** threshold
`RECLAIM_IDLE_MS_<TYPE>`:

```
XAUTOCLAIM conv.<key> convertor gw-reclaim MIN-IDLE-TIME=<RECLAIM_IDLE_MS_type> START-ID=0-0 COUNT=RECLAIM_BATCH
```

- **`RECLAIM_IDLE_MS_<TYPE>`** — per-type idle threshold in ms (document/audio/ai: 5 min,
  image: 2 min, video: 10 min, data: 3 min) — MUST exceed that type's max processing
  time, or a slow-but-alive job gets reclaimed and processed twice.
- **`RECLAIM_BATCH`** (default **10**) — max entries reclaimed per stream per sweep.

Reclaimed entries get `conv:status={processing}` written, then are handed off to a
per-type `asyncio.Queue`. The credit dispatcher (`WsGateway._dispatch` in
`workers/gateway/ws_server.py`) drains that queue with priority — before its own
`reclaim_stale`/`read_new` — and pushes the job to whichever worker connection has a
free credit, via the same `_push_job` path as a freshly read entry. The idempotency
guard, delivery-count check, and retry/DLQ gate are **not** applied at reclaim time —
they run later, when the worker's `result`/`fail` WS frame comes back
(`ws_server._handle_result` / `_handle_fail`).

A second, opportunistic reclaim runs **per WS connection**: on every free credit,
`_dispatch` calls `KeyDbGateway.reclaim_stale()` (fixed 5 min idle threshold, using that
connection's own consumer name) before falling back to `read_new()` — so a worker's own
recently-stuck entries are retried by the same connection first (nit #1 in
`workers/gateway/ws_server.py`).

> Redis 6.2+ required for `XAUTOCLAIM`. The gateway handles a graceful fallback warning
> if the command is unsupported (Redis < 6.2) — see `KeyDbGateway.reclaim_stale`/
> `reclaim_idle` in `workers/gateway/keydb.py`.

---

## 4. Retries & DLQ

**Delivery count** is read from the PEL via `XPENDING_RANGE` —
`KeyDbGateway.get_times_delivered()` in `workers/gateway/keydb.py` — called by the
gateway when a worker's `fail` WS frame comes back (`ws_server._handle_fail`), not on
every entry. A job that fails is left **unacked** (no `XACK`) unless it's routed to the
DLQ immediately; the idle-reclaim loop (§3) picks it up after its per-type
`RECLAIM_IDLE_MS_<TYPE>` threshold and redelivers it, incrementing the PEL delivery
count.

If `times_delivered > MAX_RETRIES` (constant `3` in `workers/gateway/keydb.py`, not an
env var), i.e. the entry has been attempted **at least 4 times**, the entry is routed to
the DLQ (`KeyDbGateway.add_to_dlq`, `ws_server._to_dlq_and_release`):

1. `XADD conv.dead` — dead-letter record.
2. `XACK` original stream + `DEL worker:job:{jobId}` — removes entry from PEL, drops job
   meta.
3. `DEL conv:status:{id}` — live status cleared; the MariaDB row becomes the source of
   truth (D5) — the DLQ path does **not** `HSET` a `failed` state on the live hash.
4. Asynchronously, the DLQ-consumer loop (`workers/gateway/dlq_consumer.py`, the sole
   reader of `conv.dead`) picks up the record and calls
   `POST /api/v1/internal/worker/dlq-fail` → `ConversionResultPersister::persist(state=failed)`
   → PHP triggers quota refund. It `XACK`s `conv.dead` only after a confirmed
   (2xx/terminal-4xx) relay response.

Workers return `ResultSignal.failed(permanent=True)` for permanent errors (ValueError);
`workers/common/ws_client.py` turns that into a `fail{permanent:true}` WS frame — the
gateway (`ws_server._handle_fail`) is what decides DLQ routing and performs the `XACK`.
The worker itself has no KeyDB access.

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
(1) worker → result{inline: base64}  (WS frame)
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

The gateway's `XREADGROUP` call fetches **one entry** at a time (`count=1`, hardcoded in
`KeyDbGateway.read_new()`, `workers/gateway/keydb.py`) — one read per free credit. A
worker connection advertises `slots` credits in its `ready` handshake (`WS_SLOTS`,
default **1**); the gateway dispatches a new job over WS only when that connection has a
free credit (`Credits.acquire_slot()` in `workers/gateway/ws_server.py`), and releases
the credit on `result`/`fail`/DLQ. With `slots=1` (the default) a connection processes
one job at a time end-to-end (download → convert → deliver) — the same effective
ordering as the retired direct-KeyDB model.

Implications:
- **Per-connection serial processing** (at `slots=1`, the default) — one job in flight
  per WS connection.
- **Concurrency = connection count × slots** — add worker containers (or raise
  `WS_SLOTS`) to increase parallel capacity for a category.
- **Cross-category independence** — each `conv.<type>` stream has its own consumer group
  and is dispatched by the gateway to its own worker connections; a slow video
  conversion does not block document conversions.

To increase throughput for a specific category: scale out that worker type (more WS
connections) or raise `WS_SLOTS` for it. There is no `CONSUMER_READ_COUNT` knob anymore —
the gateway always reads one entry per credit; batching lives in credits, not in the
`XREADGROUP` count.

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

**Матрица `/formats` (ConversionRegistry):** строится по **всем** строкам
`worker_capabilities` без фильтра по свежести, `status` или `lastSeen` (см.
`ConversionRegistry::buildMatrixFromCapabilities()`, registry-06). Soft-filter
матрицы сознательно отвергнут (CNV-6): liveness — сигнал мониторинга, не вход
маршрутизации. Очистка устаревших и junk-строк — long-TTL GC / CNV-36, не
soft-filter маршрутизации.

This prevents two silent failure modes:
- A stream exists in PHP routing but no worker listens → jobs accumulate forever.
- A worker claims pairs the registry doesn't know about → those conversions are
  never dispatched to that worker (dead capability).

A drift-test failure blocks the deployment.

---

## Environment variables reference

Split across the two containers by the WS-transport model: the **gateway** is the only
process holding KeyDB credentials; **workers** hold none — they only need the WS/HTTP
coordinates to reach the gateway and Symfony.

**Gateway** (`workers/gateway/config.py`, `load_config()`):

| Variable | Default | Meaning |
|----------|---------|---------|
| `REDIS_HOST` | `keydb` | KeyDB hostname |
| `REDIS_PORT` | `6379` | KeyDB port |
| `REDIS_DB` | `2` | DB index (0=cache, 1=sessions, 2=queues) |
| `REDIS_PASSWORD` | _(empty)_ | AUTH password; empty = no AUTH |
| `WS_BLOCK_MS` | `5000` | Blocking timeout on `XREADGROUP`; if no message arrives within this window the call returns empty and the credit loop continues |
| `RECLAIM_INTERVAL_S` | `60.0` | Sweep interval of the global idle-reclaim loop (§3) |
| `RECLAIM_BATCH` | `10` | Max entries reclaimed per stream per sweep |
| `RECLAIM_IDLE_MS_DOCUMENT` / `_IMAGE` / `_AUDIO` / `_VIDEO` / `_DATA` / `_AI` | `300000` / `120000` / `300000` / `600000` / `180000` / `300000` | Per-type idle-reclaim threshold (ms) — see §3 |
| `DLQ_CONSUMER_BLOCK_MS` | `5000` | Blocking timeout on `conv.dead`'s `XREADGROUP` |
| `DLQ_CONSUMER_RETRY_IDLE_MS` | `30000` | Idle threshold for retry-reclaiming unacked `conv.dead` entries |
| `DLQ_CONSUMER_RECLAIM_BATCH` | `10` | Max `conv.dead` entries reclaimed per retry pass |

`MAX_RETRIES` (delivery-count threshold before DLQ, §4) is a hardcoded constant (`3`) in
`workers/gateway/keydb.py`, not an env var.

**Worker** (`workers/common/ws_client.py`, `WsClientConfig.from_env()`):

| Variable | Default | Meaning |
|----------|---------|---------|
| `WORKER_ID` | container hostname | Stable KeyDB-consumer name (§2); must never include a PID |
| `WORKER_TYPE` | _(required)_ | `ai`\|`document`\|`image`\|`audio`\|`video`\|`data` |
| `GATEWAY_WS_URL` | _(required)_ | Gateway WS endpoint, e.g. `wss://…/ws/worker/` |
| `API_BASE_URL` | `http://localhost:8080` | Symfony base URL — input download / large-result upload; scheme+host only, no path |
| `WORKER_API_TOKEN` | _(required)_ | Bearer for the WS-upgrade handshake and for direct HTTP calls to Symfony |
| `WS_SLOTS` | `1` | In-flight credits per connection (§6) |
| `WS_RESULT_INLINE_MAX` | `262144` | Inline-result threshold (bytes); overridden by the gateway's `ready-ack.inlineMax` once connected |
| `WORK_DIR` | system tmp | Local directory for tmp input/output files during conversion |
| `WS_PING_INTERVAL_S` / `WS_LIVENESS_MISSED_PINGS` | `20.0` / `3` | Liveness ping cadence and missed-pong reconnect threshold |
| `WS_RECONNECT_BACKOFF_BASE_S` / `_MAX_S` / `_FACTOR` | `1.0` / `30.0` / `2.0` | Exponential reconnect backoff |

Workers never read `REDIS_*` or `S3_*` variables — they have no S3 or KeyDB access;
`S3_BUCKET_PREFIX`/`S3_PREFIX` now only appear in the e2e test harness
(`workers/tests/test_workers_e2e.py`, `workers/Makefile`), not in production worker or
gateway code.
