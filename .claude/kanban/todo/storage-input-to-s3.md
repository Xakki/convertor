### Move input files to S3 — drop the `/shared-files` shared volume

**Criticality:** High

**TAGS:**
- feature
- tech-debt

**Description:**
Split out of [[docs-prod-polish]] (phase-6 storage item). Output files already go to S3
(`${S3_BUCKET_PREFIX}-results`) via the queue redesign. **Input** files still land on the
local `/shared-files` volume mounted into php/cron/all workers. Goal: inputs → S3 too, and
**remove `/shared-files` entirely** so every service works only with Redis (status) + S3 (files).
This is the prerequisite for [[distributed-workers]] (a worker on another host can't mount the volume).

**Scope:**
- **PHP `POST /convert`** (`ConversionManager::createConversion`): keep current upload validation
  (MIME + extension + size + quota), then `PUT` the input to `${S3_BUCKET_PREFIX}-inputs/<key>`
  via `S3Storage`. No write to `/shared-files`. Key must be path-traversal-safe (uuid-based, not
  user filename).
- **`ConversionMessage` DTO**: replace the local input path with `inputBucket` + `inputKey`
  (and original filename/ext as metadata). `ConversionManager::dispatch()` populates them.
- **Workers** (`StreamConsumerBase` + `s3.py`): download input from S3 to a **writable tmp/work
  dir** (provided by [[optimize-worker-dockerfiles]] non-root setup), convert, upload result
  (already implemented), then clean the tmp file. Same path for every worker.
- **docker-compose.yml**: remove the `shared-files` volume mount from `php`, `cron`, and **all**
  worker services; remove the named volume; remove the runtime `chown 1000:1000 /shared-files` hack
  and any entrypoint chown of SHARE_DIR.

**Decisions (2026-06-20, user):**
- **Input upload = PHP proxies to S3** (client → PHP validates → PHP PUTs). NOT browser-direct
  presigned PUT (keeps MIME/size/quota validation server-side, minimal flow change).
- **Separate `-inputs` bucket** `${S3_BUCKET_PREFIX}-inputs`, consistent with `-results`.
- **Shared MinIO `apis3.variantgood.com`** in dev AND prod (no local MinIO container); buckets via
  MCP `minio` per project CLAUDE.md.
- **Drop `/shared-files` completely** — no dev fallback (user: "все воркеры только с redis и s3").
- 24h auto-delete is owned by [[docs-prod-polish]] (Symfony Scheduler cron deletes S3 objects + DB
  rows); not implemented here, but key/bucket layout must be cron-friendly.

**Depends on:** [[optimize-worker-dockerfiles]] (boto3 + writable non-root tmp dir in every worker).

**Acceptance Criteria:**
- `POST /convert` stores the input in `${S3_BUCKET_PREFIX}-inputs`; nothing is written to a shared
  local volume.
- Message carries `inputBucket`/`inputKey`; no local input path anywhere.
- Each worker fetches input from S3, converts, uploads result, cleans tmp — verified for the image
  worker end-to-end.
- `/shared-files` volume + mounts + chown hack fully removed from compose; stack boots and the image
  e2e round-trip (upload → convert → S3 → download) still passes (regression gate).
- `${S3_BUCKET_PREFIX}-inputs` bucket exists on shared MinIO; input keys are path-traversal-safe.

**Spin-off / related:**
- Cleanup cron (24h) → [[docs-prod-polish]].
- Remote worker hosts → [[distributed-workers]].
