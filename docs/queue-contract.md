# Queue Contract — PHP ⇄ Python Workers

Canonical wire contract that **both sides pin**. Source of truth for the
Symfony Messenger Redis-Streams entry shape, the job body, the Redis status
hash, and the result event. If you change a field name here, update the producer
(`App\Message\ConversionMessage` + `ConversionManager::dispatch`), the
`messenger.yaml` transport, and every Python worker decoder in the same commit.

> **See also:**
> - [`docs/queue-streams.md`](queue-streams.md) — stream topology, consumer groups,
>   reclaim, retries/DLQ, idempotency, backpressure, metrics, drift protection.
> - [`docs/queue-redesign-design.md`](queue-redesign-design.md) — design rationale
>   and phased implementation cards.

> Единственный канонический контракт — per-routing-key стримы/транспорты
> `conv.<key>` / `conv_<key>` (ключи `document/image/audio/video/data/ai/api`,
> включая активный `conv.api` для `worker-api`; + `browser` с CNV-88 —
> транспорт/стрим существуют, но пока БЕЗ консьюмера:
> ни один worker его не потребляет и ни одна реальная пара каталога в него не
> маршрутизируется, см. `queue-streams.md`).
> Соглашение об именовании задокументировано ниже; обе стороны таргетят его.

---

## 1. Transport & Redis connection

- **Broker:** KeyDB (Redis-compatible), service `keydb:6379` on the compose
  `default` + `backend` networks.
- **DB index:** `2` (convention: `0`=cache, `1`=sessions, `2`=queues). Both
  sides MUST use db 2.
  - **PHP (Messenger):** db is set via the **`dbindex` query param**, NOT the
    DSN path. For this transport the DSN path means `stream[/group[/consumer]]`,
    so `redis://keydb:6379/2` would mis-set the *stream*, not the db. Canonical:
    ```
    REDIS_DSN=redis://keydb:6379?dbindex=2
    ```
    (Stream/group are set in `messenger.yaml` `options`, not the DSN.)
  - **Workers:** read `REDIS_DB` (compose passes `REDIS_DB=2`). The legacy
    `REDIS_QUEUE_DB` worker env was renamed to `REDIS_DB` to match what
    `workers/common/base_worker.py` actually reads.
- **Auth:** KeyDB runs `protected-mode no` with **no `requirepass`** → **no
  password** in the DSN. `REDIS_PASSWORD` is empty; it must never be injected as
  a bare `default:@` prefix (that produces a broken AUTH). If `requirepass` is
  ever enabled, the canonical authed form is:
  `redis://default:${REDIS_PASSWORD}@keydb:6379?dbindex=2`.
- **Serializer (message):** `messenger.transport.symfony_serializer` (Symfony
  Serializer, JSON) — so Python can parse the envelope. (Default PHP-native
  serializer is unreadable from Python.)
- **⚠ Serializer (connection) — MANDATORY `\Redis::SERIALIZER_NONE`:** the write
  connection for `conv_*` MUST use `\Redis::SERIALIZER_NONE`. Otherwise phpredis
  **PHP-serialize-wraps** the value before `XADD` — the stream field `message`
  is stored as `s:NNN:"{json}";` instead of raw JSON, and the worker's
  `json.loads(fields["message"])` throws on every message. Under the custom
  `CleanRedisTransport` (§2) this is set **in code** (`setOption(OPT_SERIALIZER,
  SERIALIZER_NONE)` before `XADD`), not via a `messenger.yaml` `options.serializer`
  knob — the old `serializer: 0` option is gone because the stock `Connection` is
  no longer built. Verified at the wire level via `XRANGE conv.image` and the
  golden test (§2).

### Stream / transport naming

There are **two distinct wire shapes** on **two sets of streams** — do not
conflate them (see the producer/consumer/field/decode table below).

**Job streams `conv.<key>`** — PHP produces, Python consumes:
- **Routing key:** после request-scoped OCR/animated overrides применяется
  `key = executionKind ?? (isAi ? 'ai' : category)`. Поэтому CNV-27
  `isAi=true` + `executionKind=api` маршрутизируется в `api`, а не в `ai`;
  `markup` без override сворачивается в `document`. Keys: `document`, `image`,
  `audio`, `video`, `data`, `ai`, `api`, `browser`.
- **Stream name:** `conv.<key>` (e.g. `conv.document`, `conv.ai`, `conv.api`).
- **Transport name (messenger.yaml):** `conv_<key>` (e.g. `conv_document`),
  routed via `TransportNamesStamp(['conv_'.$key])`.
- **Consumer group:** `convertor` (one per stream). PHP only **produces** (XADD)
  and never **consumes**. The group is created at start-id **`0`** (never `$`):
  `CleanRedisTransport` does `XGROUP CREATE conv.<key> convertor 0 MKSTREAM` on
  send (idempotent, swallows BUSYGROUP — same as the old stock `auto_setup`), and
  the worker creates it identically if it connects first. Start-id `0` (not `$`,
  only-new) is mandatory — `$` would silently drop every job XADDed before the
  group exists.

**Result path** — workers → gateway → relay → Symfony:
- On-server workers submit results inline over WS (≤256 KB) or via
  `POST /jobs/{id}/result` (large). The **WS-gateway** does XACK after Symfony
  confirms persist. Workers never write to a result stream directly.
- Live status hash `conv:status:{id}` is **written by gateway** (not workers).

**Failure transport** `conversions_failed` (Messenger failure transport;
PHP-side only).

| Stream       | Producer | Consumer        | Stream field | Decode depth |
|--------------|----------|-----------------|--------------|--------------|
| `conv.<key>` | PHP (custom `CleanRedisTransport`) | gateway (XREADGROUP) → WS → worker | `message` | **once** (§3 job JSON) — §2 |

---

## 2. Job stream entry shape — `conv.<key>` (PHP produces, Python consumes)

Applies to the job streams `conv.<key>` only. **Clean single-JSON — decode once.**

> **Option D — custom Messenger transport.** The stock `symfony/redis-messenger`
> `Connection::add()` unconditionally wraps the payload in
> `json_encode(['body' => …, 'headers' => …])` (that wrap is structural to the
> stock transport, so a custom *serializer* could not remove it — only a custom
> *transport* can). We bind the `conv_*` transports to
> `App\Messenger\Transport\CleanRedisTransport` (DSN scheme `conv+redis://`,
> `messenger.yaml`), whose `send()` writes `Serializer::encode()['body']`
> **directly** into the `message` field. Nothing consumes these streams via
> Messenger (no handlers; `messenger:consume conv_*` forbidden — the transport
> is produce-only), so the producer owns this contract.

> Requires `\Redis::SERIALIZER_NONE` on the write connection — `CleanRedisTransport`
> sets it explicitly before `XADD`; otherwise phpredis PHP-serializes the value
> (`s:NNN:"…";`) and wraps the clean JSON (§1).

The transport writes each message with **`XADD <stream> * message <value>`** —
i.e. a **single stream field literally named `message`**. Its value **is the job
body directly** (the clean camelCase JSON of §3), **not** an envelope:

```json
{ "conversionId": 123, "inputBucket": "convertor-inputs", "...": "see §3" }
```

There is **no** `{body,headers}` wrapper and **no** double-encoding — the Messenger
`headers` (message FQCN, stamps) are intentionally dropped from the wire, because
the raw stream readers (`XREADGROUP`) never needed them. **Decode once.**

The contract is **frozen by a shared golden fixture**
`app-symfony/tests/Fixtures/messenger_envelope.golden.json`, asserted byte-for-byte
on BOTH sides (PHP `CleanRedisTransportTest` + `…KeyDbTest`; Python
`test_envelope_golden.py`), so any drift (Symfony/phpredis upgrade re-introducing
the wrap or PHP-serialization) fails loudly in one isolated place.

### Python decode (canonical)

```python
import json

# entry is one (id, fields) pair from XREADGROUP; fields is a dict.
def parse_entry(fields: dict) -> dict:
    return json.loads(fields["message"])   # single decode: message IS the job (§3)
```

Canonical implementation: `workers/common/envelope.py::parse_message` (delegated to
by `stream_consumer._parse_entry`). It handles byte vs str keys/values
(`redis-py` returns bytes unless `decode_responses=True`) and raises on malformed
input; the poison-message XACK+drop lives in the consumer, not the decoder.

---

## 3. Job body schema (camelCase, end-to-end)

The payload of the `message` field (§2). **camelCase on both sides** — the PHP
DTO uses camelCase properties and the default `ObjectNormalizer` (no snake_case
name converter is configured), so the JSON keys are the property names verbatim.

```json
{
  "conversionId": 123,
  "inputBucket": "convertor-inputs",
  "inputKey": "inputs/2026/06/19/ab12cd34.pdf",
  "originalFilename": "invoice.pdf",
  "sourceFormat": "pdf",
  "targetFormat": "docx",
  "category": "document",
  "isAi": false,
  "options": [],
  "attempt": "0"
}
```

> ⚠ **On-wire escaping:** Symfony `JsonEncode` escapes forward slashes (no
> `JSON_UNESCAPED_SLASHES`), so the raw stream bytes carry `inputs\/2026\/…`, not
> `inputs/2026/…`. The golden fixture
> (`app-symfony/tests/Fixtures/messenger_envelope.golden.json`) therefore holds
> `\/` **by design** — it decodes to the same `/` value; the readable form above
> is the decoded shape.

| Field          | Type            | Notes |
|----------------|-----------------|-------|
| `conversionId`     | int             | Conversion entity PK; deterministic S3 key + status key. |
| `inputBucket`      | string          | S3 bucket holding the input object (`${S3_BUCKET_PREFIX}-inputs`). |
| `inputKey`         | string          | S3 object key of the input (`inputs/Y/m/d/<hex>.<ext>`; random basename, never the user filename). |
| `originalFilename` | string          | The user's original upload filename (display/metadata only — NOT used for the S3 key). |
| `sourceFormat`     | string          | Source format (lowercased extension / registry virtual key). |
| `targetFormat` | string          | Target format. **Renamed from `outputFormat`** for contract parity. |
| `category`     | string          | `FileCategory` value: document/image/audio/video/data/markup/archive. |
| `isAi`         | bool            | AI job flag. Routing key = `executionKind ?? (isAi ? 'ai' : category)`. |
| `options`      | object/array    | Per-job options bag. Для image target: `width`/`height` (1–10000 px), `quality` (1–100 для JPEG/WebP), `background` (`#RRGGBB` для JPEG). **Empty = `[]`** (PHP empty array serializes to JSON `[]`, not `{}`) — workers must not assume a map when empty. |
| `attempt`      | string (int)    | Generation/attempt marker of the `Conversion` (JSON string, e.g. `"0"`). Bumped by operator-requeue; echoed into the DLQ payload so a stale `dlq-fail` can be ignored (`ConversionResultPersister` stale-guard). Absent on legacy jobs → treated as `0`. |

---

## 4. Redis status hash — `conv:status:{conversionId}`

Live status, written by WS-Gateway, read by the PHP status endpoint. **TTL 24h.**
MariaDB remains authoritative for `/history` + `/download`; Redis is live state.

**`state` vocabulary — MUST match the `ConversionStatus` enum `.value`** (PHP
stores these in DB rows + returns them as the public API value, so gateway HSETs
the SAME strings to avoid live-vs-history drift):

| `state` HSET by gateway | meaning |
|-------------------------|---------|
| `pending`              | accepted, not yet started |
| `processing`           | worker picked it up |
| `completed`            | success (output in S3) |
| `failed`               | terminal error (`error` set) |

PHP maps `state` → `ConversionStatus::tryFrom($state)`; an unknown/missing
`state` falls back to the MariaDB row status (total, safe default). Do NOT emit
`queued` — use `pending`.

| Field          | Type   | Notes |
|----------------|--------|-------|
| `state`        | string | one of the four values above. |
| `sourceFormat` | string | mirrors job body. |
| `targetFormat` | string | mirrors job body. |
| `category`     | string | mirrors job body. |
| `isAi`         | bool   | `0`/`1` (Redis hash stores strings). |
| `outputBucket` | string | S3 bucket of the result (on success). |
| `outputKey`    | string | S3 object key (deterministic; see design §S3 sink). |
| `outputUrl`    | string | presigned / proxy URL (optional). |
| `outputSize`   | int    | bytes. |
| `outputMime`   | string | result MIME. |
| `error`        | string | error message (on failure). |
| `attempts`     | int    | delivery/attempt count. |
| `worker`       | string | worker instance id that processed it. |
| `startedAt`    | int    | unix ms. |
| `finishedAt`   | int    | unix ms. |
| `updatedAt`    | int    | unix ms (last HSET). |

---

## 5. Result path — WS relay (gateway → Symfony)

Workers return results via `ResultSignal` to the gateway WS connection.
The gateway handles persist via the internal relay endpoint:

- **Small (≤256 KB):** inline base64 in `result{inline}` WS frame → gateway
  POSTs to Symfony relay → `ConversionResultPersister`.
- **Large:** worker POSTs binary to `POST /api/v1/worker/jobs/{id}/result`
  directly → Symfony stores to S3, then worker sends `result{resultKey}` WS
  frame → gateway ACKs (XACK).

Stream `conv.result` and command `app:queue:result-consumer` are retired.
See `docs/superpowers/specs/2026-07-02-ws-worker-transport-design.md` §3/§5.

### Expiry path — never-claimed jobs (CNV-71-03)

`ConversionManager::createSingleHop()`/`createChain()` use durable admission for
normal queues: any registered capability row keeps its worker type available
until long-TTL GC removes it, regardless of short liveness status. API-backed
jobs are the narrow live-only exception: they require a fresh `alive` row with a
currently validated model. A job can still become never-claimed if no worker is
connected when the gateway tries to deliver the stream entry. Such an entry
never enters a consumer PEL, so idle-reclaim (§6.3 of the design spec)
structurally cannot see it.

The gateway's own expiry-sweep (`workers/gateway/expiry.py`, on its own
`EXPIRY_SWEEP_INTERVAL_S` tick, default 300s) closes this gap: for each
`conv.<type>` it scans the backlog strictly after the consumer group's
last-delivered-id, and for any entry older than `WORKER_CLAIM_TIMEOUT_MINUTES`
(default 60 min) it calls:

```
POST /api/v1/internal/worker/expire
Body: {"conversionId": <int>, "reason": "worker_timeout"}
```

Symfony (`InternalWorkerController::expire()`) relays this straight into
`ConversionResultPersister::persist()` with `state: 'expired'` — the exact
same terminal-write shape as `state: 'failed'` (status, error message, quota/
prepaid refund, `ConversionFailed` chain-propagation), except the target
status is `ConversionStatus::Expired` and the error message is a fixed
Russian string authored server-side (the gateway's `reason` is accepted but
not echoed verbatim). **Idempotent**, same terminal-status guard as
`completed`/`failed`: a conversion already Completed/Failed/Expired is a
200 no-op, so a redelivered/duplicate expire call (or one racing a genuine
`/result`/`/fail` for the same conversionId) never double-refunds — the guard
is evaluated under a `SELECT … FOR UPDATE` lock on the `Conversion` row (see
`ConversionResultPersister`'s class docblock) specifically to make concurrent
callers for the same conversionId safe. On the gateway side, the stream entry
is `XDEL`-ed only AFTER a 2xx response — a non-2xx (incl. PHP being down)
leaves the entry for the next sweep tick, and a re-check of last-delivered-id
right before deleting minimizes (does not eliminate) the race against a worker
that connects and claims the job in the same narrow window; the persister's
lock/guard is what makes either outcome deterministic regardless of which
finalizer — the tardy worker or the sweep — wins.
