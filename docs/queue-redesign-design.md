# Worker/Queue Redesign — Design

Status: **proposal / grooming** (decisions pending). Source card: `.claude/kanban/grooming/fix-queue-php-worker-mismatch.md`.

Binding user direction (2026-06-19): keep Symfony Messenger **Redis Streams** + JSON serializer; Python workers consume Streams; converted **files → S3/MinIO**, **status → Redis** (fault-tolerant); **libreoffice → queue consumer**; workers **declare capabilities** (may overlap; multiple instances; launch deferred); client status via existing polling, sourced from Redis.

## Current state (confirmed)
- PHP `messenger.yaml` → stream `conversions`, group `convertor`, **PHP-native serializer**; `ConversionManager::dispatch` builds `ConversionMessage{conversionId,inputPath,outputFormat,category}`.
- Workers use Redis **lists** (`LPUSH`/`BRPOPLPUSH` on `convertor:{queue}`, JSON) → streams vs list = **zero consumption**.
- Field drift camel (PHP) vs snake (workers); `ConversionMessageHandler` calls a dead worker HTTP `/convert`.
- DB/auth drift: compose `REDIS_QUEUE_DB=2` vs worker reads `REDIS_DB`(0); `REDIS_DSN` has no password while KeyDB may require one.
- libreoffice is aiohttp HTTP server (per-job soffice profile isolation), not a consumer.

### Extra bugs found (affect design)
- **AI routing broken:** registry tags AI jobs under Audio/Document/Image with `isAi=true`, never emits category `ai`; handler maps `ai`→AI worker → AI never reached. Routing key must be **`isAi ? 'ai' : category`**.
- **Archive unimplemented:** registry advertises archives; no worker has zip/tar.
- **AI sub_type gap:** `ai/worker.py` needs `sub_type`; message carries none.
- **libreoffice matrix gap:** registry promises pdf/odt/html/rtf/epub; `convert()` only does txt/docx/md (+pdf input).

## Architecture
- **One stream per routing-key** (`conv.<key>`, key = `isAi?ai:category`), **one group `convertor`** per stream. Workers join the streams for the routing-keys they declare; consumer-group load-balances "any idle capable worker"; overlap = both worker types in the same group → no double-processing; a worker capable of N keys runs N concurrent `XREADGROUP`.
- **PHP routing** via `TransportNamesStamp(['conv_'.$key])`, one transport per stream in `messenger.yaml`; single `ConversionMessage` class.
- **Fault-tolerance (workers speak the group protocol):** `XGROUP CREATE … MKSTREAM`; `XREADGROUP … >`; on success `XACK` (+ periodic `XTRIM MAXLEN ~`); crash recovery via `XAUTOCLAIM` (idle ≥5min); retry via delivery count (max 3) → `conv.dead` DLQ + status=failed + ack. **Idempotency:** ordered commit (1) S3 PUT at deterministic key (conversionId+target → overwrite), (2) `HSET conv:status:{id}`, (3) `XACK`; guard at start `if status==completed → ack+skip`.
- **Redis status** `conv:status:{id}` HASH {state, source/targetFormat, category, isAi, outputBucket/Key/Url/Size/Mime, error, attempts, worker, started/finished/updatedAt}, **TTL 24h**. Status endpoint reads Redis directly; durable history/download via a **`conversions_result`** stream consumed by a PHP handler that persists FileStorage(S3 key)+Conversion.status to MariaDB (DB writes stay in PHP).
- **S3 sink:** inputs stay on `/shared-files` (moving inputs→S3 is docs-prod-polish); **outputs → S3** bucket `${S3_BUCKET_PREFIX}-results`, key `results/{Y}/{M}/{D}/{conversionId}.{target}` (deterministic). `/download` → presigned redirect or authenticated PHP proxy.
- **Capabilities:** per-worker `CAPABILITIES = {routing_keys, matrix, sub_types}`; single source of truth = `ConversionRegistry` (from→to→category→isAi); add a **drift test** (worker matrix ⊆ registry; every routing-key has ≥1 worker).
- **libreoffice→consumer:** rewrite `main.py` to a `StreamConsumerBase` subclass, keep per-job soffice profile isolation, drop HTTP handlers; declare exactly the (from,to) it implements and align registry.

## Canonical JSON job body
```
{ "conversionId":123, "inputPath":"input/2026/06/19/ab12.pdf", "sourceFormat":"pdf",
  "targetFormat":"docx", "category":"document", "isAi":false, "subType":null, "options":{} }
```
Result event (worker→`conversions_result`): `{conversionId,state,outputBucket,outputKey,outputMime,outputSize,error,processingMs}`. Envelope (`body`+`headers`) shape pinned in `docs/queue-contract.md`.

## Phased cards (ordered, deps)
- **Phase 0:** A. schemas + `ConversionMessage` extension; B. unify Redis transport (DSN db+pwd, JSON serializer).
- **Phase 1:** C. per-key transports + `TransportNamesStamp` routing, delete HTTP callWorker; D. Redis status read + `conversions_result` handler + `/download` from S3.
- **Phase 2:** E. `StreamConsumerBase` (group protocol, retry/DLQ, idempotency, multi-stream, status, result event); F. capability declaration + drift test.
- **Phase 3:** G. S3 result sink + dev MinIO.
- **Phase 4 (parallel):** H. image, I. ffmpeg, J. data, K. ai (subType stt/tts/ocr), L. libreoffice→consumer.
- **Phase 5:** M. fault-tolerance e2e.
- **Phase 6:** N. e2e smoke (ties to smoke-run-verify, worker-conversion-tests).

## Decisions (resolved 2026-06-19)
1. **Stream granularity:** routing-keys = `FileCategory` values + dedicated `ai`; markup folded into `document`. (accepted default)
2. **Status/history:** workers write **only to Redis** (status + result event). **PHP consumes the result/ready queue and persists to MariaDB itself** — DB writes stay in PHP; MariaDB is authoritative for `/history`+`/download`; Redis is live status (TTL 24h). (user)
3. **Dev outputs S3:** use the **shared `apis3.variantgood.com`** MinIO in dev too — **no local MinIO container**. Configure S3 client (endpoint/keys) for both dev and prod against shared infra. (user)
4. **Prod MinIO:** shared `apis3.variantgood.com` (same as dev). (user)
5. **Registry reconciliation:** **drop archives** from the registry now (follow-up card later); **expand libreoffice worker** to all advertised targets (pdf/odt/html/rtf/docx, epub if soffice supports); confirm AI `isAi→ai` routing + `subType` in message. (user)
6. **Retry/DLQ:** max_retries=3, XAUTOCLAIM idle=5min, stream MAXLEN cap. (accepted default)
7. **Download delivery:** authenticated PHP proxy (keeps per-user access check). (accepted default)

## Follow-ups (deferred)
- **image: add pillow-heif/cairosvg/libavif to worker Dockerfile to re-enable heic/svg/avif.** Phase-1 image slice trims `svg`/`heic`/`avif` from `ConversionRegistry` (image inputs + `avif` output) because the plain-Pillow worker rejects them. Once the worker image ships cairosvg (svg), pillow-heif (heic), and libavif/pillow-avif (avif), restore those formats to the image matrix and add a registry⇄worker drift test.

This document is the canonical plan; phases A–N above are tracked here (not exploded into 14 separate cards). The epic card is `fix-queue-php-worker-mismatch`.
