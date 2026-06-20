# Queue Contract — PHP ⇄ Python Workers

Canonical wire contract that **both sides pin**. Source of truth for the
Symfony Messenger Redis-Streams entry shape, the job body, the Redis status
hash, and the result event. Companion to `docs/queue-redesign-design.md`
(design rationale). If you change a field name here, update the producer
(`App\Message\ConversionMessage` + `ConversionManager::dispatch`), the
`messenger.yaml` transport, and every Python worker decoder in the same commit.

> Phase status: **Phase 0** ships a single stream `conversions` with the JSON
> serializer. Per-routing-key streams/transports (`conv.<key>` / `conv_<key>`)
> are Phase 1 (card C). The naming convention is documented here so both sides
> target it from the start.

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
- **⚠ Serializer (connection) — MANDATORY `serializer: 0`:** every Redis
  Messenger transport MUST set the **connection** option `serializer: 0`
  (`\Redis::SERIALIZER_NONE`) in `messenger.yaml` `options`. This is a different
  knob from the message serializer above. `symfony/redis-messenger`'s
  `Connection` DEFAULT_OPTIONS sets it to `1` (`\Redis::SERIALIZER_PHP`), which
  makes phpredis **PHP-serialize-wrap** the value before `XADD` — the stream
  field `message` is then stored as `s:NNN:"{json}";` instead of raw JSON, and
  the worker's `json.loads(fields["message"])` throws on every message. `0`
  stores the raw symfony_serializer JSON string. Verified at the wire level via
  `XRANGE conv.image`. **Any new per-key transport must repeat `serializer: 0`.**

### Stream / transport naming (Phase 1 — current)

There are **two distinct wire shapes** on **two sets of streams** — do not
conflate them (see the producer/consumer/field/decode table below).

**Job streams `conv.<key>`** — PHP produces, Python consumes:
- **Routing key:** `key = isAi ? 'ai' : category`. `markup` is folded into
  `document`. Keys: `document`, `image`, `audio`, `video`, `data`, `ai`.
- **Stream name:** `conv.<key>` (e.g. `conv.document`, `conv.ai`).
- **Transport name (messenger.yaml):** `conv_<key>` (e.g. `conv_document`),
  routed via `TransportNamesStamp(['conv_'.$key])`.
- **Consumer group:** `convertor` (one per stream). PHP only **produces** (XADD)
  and never creates these groups — so the **worker** creates the group, and it
  MUST use start-id **`0`**, not `$`:
  `XGROUP CREATE conv.<key> convertor 0 MKSTREAM`. Using `$` (only-new) would
  silently drop every job XADDed before the worker first connects.

**Result stream `conv.result`** — Python produces, PHP consumes:
- Single stream `conv.result`, group `convertor`, consumed by the PHP command
  `app:queue:result-consumer` (a **raw** stream consumer, NOT Messenger).
- Workers also write the live status hash `conv:status:{id}` (§4).

**Failure transport** `conversions_failed` (Messenger failure transport;
PHP-side only).

| Stream         | Producer | Consumer            | Stream field | Decode depth |
|----------------|----------|---------------------|--------------|--------------|
| `conv.<key>`   | PHP (Messenger) | Python workers | `message`    | **twice** (`message`→`{body,headers}`; `body` is a JSON string) — §2 |
| `conv.result`  | Python workers  | PHP command   | `data`       | **once** (`data` is the raw §5 JSON body) — §5 |

> ⚠ The two shapes differ: `conv.<key>` is a Messenger envelope (field
> `message`, double-encoded); `conv.result` is a plain JSON payload (field
> `data`, single-encoded). A worker must NOT wrap result events in an envelope,
> and PHP must NOT double-decode `conv.result`.

> Phase 0 used a single stream `conversions`; Phase 1 replaces it with the
> per-key `conv.<key>` streams above.

---

## 2. Job stream entry shape — `conv.<key>` (PHP produces, Python consumes)

Applies to the job streams `conv.<key>` only. **Decode twice.**

> Requires the transport connection option `serializer: 0` (§1) — otherwise the
> `message` value is PHP-serialized (`s:NNN:"…";`), not the JSON shown below.

The Redis transport writes each message with **`XADD <stream> * message <value>`** —
i.e. a **single stream field literally named `message`**. Its value is a JSON
**envelope** with two keys:

```json
{
  "body": "<json-string of the job body — see §3>",
  "headers": {
    "type": "App\\Message\\ConversionMessage",
    "Content-Type": "application/json"
  }
}
```

- `headers.type` = the message class **FQCN** (`App\Message\ConversionMessage`).
  Additional Messenger **stamp** headers may also be present — treat `headers`
  as an open map; only `type` is contractually required.
- `body` is **itself a JSON-encoded string** (double-encoded), not a nested
  object. Decode twice.

### Python decode (canonical)

```python
import json

# entry is one (id, fields) pair from XREADGROUP; fields is a dict.
def parse_entry(fields: dict) -> dict:
    envelope = json.loads(fields["message"])   # 1) outer: {body, headers}
    job = json.loads(envelope["body"])         # 2) inner: the job body (§3)
    return job
```

> Note on byte vs str keys: `redis-py` returns bytes unless
> `decode_responses=True`. Normalize the field name (`b"message"` /
> `"message"`) accordingly.

---

## 3. Job body schema (camelCase, end-to-end)

The inner `body`. **camelCase on both sides** — the PHP DTO uses camelCase
properties and the default `ObjectNormalizer` (no snake_case name converter is
configured), so the JSON keys are the property names verbatim.

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
  "subType": null,
  "options": []
}
```

| Field          | Type            | Notes |
|----------------|-----------------|-------|
| `conversionId`     | int             | Conversion entity PK; deterministic S3 key + status key. |
| `inputBucket`      | string          | S3 bucket holding the input object (`${S3_BUCKET_PREFIX}-inputs`). |
| `inputKey`         | string          | S3 object key of the input (`inputs/Y/m/d/<hex>.<ext>`; random basename, never the user filename). |
| `originalFilename` | string          | The user's original upload filename (display/metadata only — NOT used for the S3 key). |
| `sourceFormat`     | string          | Source format (lowercased extension / registry virtual key). |
| `targetFormat` | string          | Target format. **Renamed from `outputFormat`** for contract parity. |
| `category`     | string          | `FileCategory` value: document/image/audio/video/data/markup/archive. |
| `isAi`         | bool            | AI job flag. Routing key = `isAi ? 'ai' : category`. |
| `subType`      | string \| null  | AI sub-type: `ocr` / `stt` / `tts`; `null` for non-AI. |
| `options`      | object/array    | Per-job options bag. **Empty = `[]`** (PHP empty array serializes to JSON `[]`, not `{}`) — workers must not assume a map when empty. |

---

## 4. Redis status hash — `conv:status:{conversionId}`

Live status, written by workers, read by the PHP status endpoint. **TTL 24h.**
MariaDB remains authoritative for `/history` + `/download`; Redis is live state.

**`state` vocabulary — MUST match the `ConversionStatus` enum `.value`** (PHP
stores these in DB rows + returns them as the public API value, so workers HSET
the SAME strings to avoid live-vs-history drift):

| `state` HSET by worker | meaning |
|------------------------|---------|
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

## 5. Result event — stream `conv.result` (Python produces, PHP consumes)

**Producer:** Python worker. **Consumer:** PHP command `app:queue:result-consumer`
(group `convertor`, raw stream — NOT Messenger).

**Wire shape (pinned):** the worker emits with

```
XADD conv.result * data <json>
```

i.e. a **single stream field literally named `data`** whose value is the JSON of
the result body below. This is a **plain payload, NOT a Messenger envelope** —
there is no `message`/`body`/`headers` wrapping. PHP decodes it **once**:

```python
import json, time
r.xadd("conv.result", {"data": json.dumps(result_body)})
```
```php
// PHP consumer side:
$body = json_decode($fields['data'], true);   // single decode
```

Result body:

```json
{
  "conversionId": 123,
  "state": "completed",
  "outputBucket": "convertor-results",
  "outputKey": "results/2026/06-19/123.docx",
  "outputMime": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "outputSize": 48213,
  "error": null,
  "processingMs": 1840
}
```

| Field          | Type           | Notes |
|----------------|----------------|-------|
| `conversionId` | int            | |
| `state`        | string         | `completed` / `failed`. |
| `outputBucket` | string \| null | S3 bucket (null on failure). |
| `outputKey`    | string \| null | S3 key (null on failure). |
| `outputMime`   | string \| null | |
| `outputSize`   | int \| null    | bytes. |
| `error`        | string \| null | message on failure. |
| `processingMs` | int            | wall-clock processing time. |
