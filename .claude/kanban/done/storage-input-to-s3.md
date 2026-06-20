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

---

**Result (2026-06-20):** Implemented.
- PHP: `S3Storage::inputsBucket()` + `putObject(...)->resolve()`; `ConversionManager::storeInput()` PUTs the
  upload (stream from PHP tmp, never `/shared-files`) to `${S3_BUCKET_PREFIX}-inputs` with key
  `inputs/{Y}/{m}/{d}/{32hex}.{ext}` (random basename, path-traversal-safe). `ConversionMessage`:
  `inputPath` → `inputBucket`+`inputKey`+`originalFilename`. `$shareDir` fully removed (class +
  services.yaml). phpstan + cs-fix green.
- Workers: `s3.get_file()`; `StreamConsumerBase` downloads input to `WORK_DIR` tmp before `convert()`,
  cleans input+output tmp in `try/finally` on every path (success / convert-fail / upload-fail; download-fail
  self-cleans). image worker reads local path via `job['_localInput']`, output to tmp. 27 worker tests green.
- DevOps: `shared-files` volume + all mounts + `SHARE_DIR` removed from compose; bucket
  `convertor-dev-inputs` created on shared MinIO, user `convertor-dev` has built-in `readwrite` (covers it).
  No `chown 1000:1000 /shared-files` hack found in-repo (php image entrypoint is in the harbor base image).
- Docs: `queue-contract.md` + `queue-redesign-design.md` updated to the new contract.
- Review: 8-angle /code-review high. cs-fixer repo-wide reformat = purely cosmetic (verified hunk-by-hunk,
  no smuggled behavior). One substantive note: only image worker migrated to S3 input — ffmpeg/data/ai stay
  on legacy `BaseWorker` (user-approved deferral; they already did not consume the current Redis Streams, so
  no working path regressed).

**ALL workers migrated (same session, per user request):** `worker-ffmpeg` / `worker-data` / `worker-ai`
moved off the legacy `BaseWorker` to `StreamConsumerBase` + S3 input — they now consume the real Redis
Streams (`conv.audio`/`conv.video`/`conv.data`/`conv.ai`, group `convertor`, cross-checked vs
`messenger.yaml` + `ConversionManager::dispatch`), download input from S3 to `WORK_DIR` tmp, convert,
upload result, clean tmp. Legacy `base_worker.py` + `keydb_client.py` + `test_base_worker.py` deleted.
Conversion logic preserved (verified vs HEAD). Review confirmed the `asyncio.run()` inside `convert()` is
safe (base consume loop is synchronous — no live event loop).

**Gates green (2026-06-20):** `make docker-check` exit 0 (fixed 4 orphaned override blocks in
`docker/limits.yml`: nginx-exporter/mysqld-exporter/node/redis), `make test-python` 61 passed
(root-caused + fixed the urllib3/boto3 collection conflict — it was `sys.modules` stubs in the deleted
legacy tests, not a version mismatch), `make phpstan` clean (27), `make test-php` 5 passed.
`make cs`/`cs-check` fixed with `--allow-risky=yes`.

**Live e2e — PASS (2026-06-20):** rebuilt worker images (`make build-workers`), `worker-image` UP and
consuming `conv.image`. Round-trip proven (conversion id=5, PNG→JPG): `POST /api/v1/convert` → 202;
input PUT to `convertor-dev-inputs/inputs/2026/06/20/<hex>.png` (matches DB `storage_path`); worker
downloaded from S3 to `/tmp` (no shared volume), converted, uploaded to
`convertor-dev-results/results/2026/06-20/5.jpg` (633B, image/jpeg); result-consumer set
status=completed (~4s); `GET /download` returned JPEG bytes (`ff d8 ff e0`). All acceptance criteria met.

**worker-ai → roadmap Стадия 2 (deferred, user 2026-06-20):** stale container stopped/removed; its unit
tests disabled (`pytest.skip(allow_module_level=True)` in `test_ai_worker.py`). Code migration stays in
place, but worker-ai is not brought up / validated until Стадия 2. Image + ffmpeg + data remain active.
`make test-python` → 50 passed, 1 skipped.

**Minor follow-ups (non-blocking → fold into [[worker-conversion-tests]]):**
- ffmpeg/ai unit tests stub the real ffmpeg subprocess / AI providers (coverage gap; shipped
  `story.mp3` fixture unused) — add an ffmpeg round-trip test gated on binary availability.
- `data` worker `_read_data()` selects the parser by tmp-file suffix instead of `job['sourceFormat']`
  (safe by construction today, but brittle).
- No `document`/`markup`/`archive` stream consumer exists; `FileCategory::Archive` has no `conv_archive`
  transport (pre-existing).
