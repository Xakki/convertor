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
- **Serializer:** `messenger.transport.symfony_serializer` (Symfony Serializer,
  JSON) — so Python can parse the envelope. (Default PHP-native serializer is
  unreadable from Python.)

### Stream / transport naming (target — Phase 1)

- **Routing key:** `key = isAi ? 'ai' : category`. `markup` is folded into
  `document`. Keys: `document`, `image`, `audio`, `video`, `data`, `ai`.
- **Stream name:** `conv.<key>` (e.g. `conv.document`, `conv.ai`).
- **Transport name (messenger.yaml):** `conv_<key>` (e.g. `conv_document`),
  routed via `TransportNamesStamp(['conv_'.$key])`.
- **Consumer group:** `convertor` (one per stream).
- **Phase 0 (current):** single stream `conversions`, group `convertor`,
  failure stream `conversions_failed`.

---

## 2. Stream entry shape (Messenger Redis transport + JSON serializer)

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
  "inputPath": "input/2026/06/19/ab12cd34.pdf",
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
| `conversionId` | int             | Conversion entity PK; deterministic S3 key + status key. |
| `inputPath`    | string          | Path under `/shared-files` (e.g. `input/Y/m/d/<hex>.<ext>`). |
| `sourceFormat` | string          | Source format (lowercased extension / registry virtual key). |
| `targetFormat` | string          | Target format. **Renamed from `outputFormat`** for contract parity. |
| `category`     | string          | `FileCategory` value: document/image/audio/video/data/markup/archive. |
| `isAi`         | bool            | AI job flag. Routing key = `isAi ? 'ai' : category`. |
| `subType`      | string \| null  | AI sub-type: `ocr` / `stt` / `tts`; `null` for non-AI. |
| `options`      | object/array    | Per-job options bag. **Empty = `[]`** (PHP empty array serializes to JSON `[]`, not `{}`) — workers must not assume a map when empty. |

---

## 4. Redis status hash — `conv:status:{conversionId}`

Live status, written by workers, read by the PHP status endpoint. **TTL 24h.**
MariaDB remains authoritative for `/history` + `/download`; Redis is live state.

| Field          | Type   | Notes |
|----------------|--------|-------|
| `state`        | string | `queued` / `processing` / `completed` / `failed`. |
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

## 5. Result event — stream `conversions_result`

Emitted by workers (worker → `conversions_result`), consumed by a PHP handler
that persists `FileStorage` (S3 key) + `Conversion.status` to MariaDB (DB writes
stay in PHP). Same JSON-envelope shape as §2 when produced by Messenger; when
produced directly by a worker, document the field set as the body:

```json
{
  "conversionId": 123,
  "state": "completed",
  "outputBucket": "convertor-results",
  "outputKey": "results/2026/06/19/123.docx",
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
