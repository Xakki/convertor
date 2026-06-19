### Redesign worker/queue architecture (Streams + capabilities + S3 results)

**Criticality:** Blocking

**TAGS:**
- feature
- bug-fix

**Description:**
Started as a producer/consumer mismatch fix; expanded by user decision (2026-06-19) into a worker/queue **redesign**. Currently PHP dispatches via Symfony Messenger **Redis Streams** (`conversions` stream, group `convertor`, PHP-native serializer) while Python workers read a plain **list** (`convertor:{cat}`, JSON) — so nothing is consumed. The libreoffice worker is HTTP-only (not queued). This card defines the target design; **needs a DESIGN phase before implementation.**

**User-set direction (binding):**
- **Queue → keep Symfony Messenger Redis Streams.** Workers are rewritten to consume Streams (XREADGROUP) and PHP Messenger uses a **JSON serializer** so Python can parse the envelope.
- **Results storage:** converted **files → S3/MinIO**; **status/metadata → Redis** (design the key schema + TTL). Must be **fault-tolerant** (ack/retry, crash recovery, no lost/double-processed jobs).
- **libreoffice → queue consumer** like the other workers (drop the HTTP-only design).
- **Worker capability registry:** each worker **declares the conversion tasks it can handle**; capabilities may **overlap** between workers; there may be **multiple worker instances**. Launch/scaling strategy is **deferred** ("продумаем отдельно").
- Status reaches the client via the existing polling endpoint, sourced from Redis (not HTTP callback).

**Problem (current, confirmed):**
- Streams (PHP) vs list (workers) → zero consumption.
- PHP-native serializer vs Python JSON.
- Message fields `conversionId/category` (camelCase) vs worker `id/category` (snake).
- `ConversionMessageHandler` calls workers over HTTP (`callWorker`), but queue workers expose no HTTP endpoint — dead path.
- `REDIS_QUEUE_DB=2` injected but `base_worker.py` reads `REDIS_DB` (db 0); naming/db drift.
- Callback unreachable (workers on `backend`, nginx on `default`).

**Impact:**
Core product non-functional end-to-end until redesigned.

**Recommendation:**
Run a DESIGN phase producing a concrete architecture + decision points, then break into implementation cards (PHP Messenger JSON+routing; worker Streams consumer base; capability registry; S3 result sink; Redis status store + fault-tolerance; libreoffice→queue; multi-worker launch).

**Acceptance Criteria (target):**
- File submitted via API → routed to a worker that declares the capability → processed → output stored in S3/MinIO → status in Redis → client polling reflects done.
- One consistent transport (Messenger Streams, JSON) both sides; capability-based routing with overlap support.
- Fault-tolerant: job survives worker crash (re-delivery), no double-final-write.
- libreoffice runs as a queue consumer.
- Covered by e2e test (ties into [[smoke-run-verify]], [[worker-conversion-tests]]).

**Design doc (canonical plan):** `docs/queue-redesign-design.md` — full architecture + phased cards A–N. This card is the epic tracker; phases are NOT exploded into separate files.

**Decisions:**
- Transport = Messenger Redis Streams + JSON serializer; workers consume Streams (user, 2026-06-19).
- One stream per routing-key (`conv.<key>`, key=`isAi?ai:category`, markup→document), group `convertor`; PHP routes via `TransportNamesStamp`.
- Fault-tolerance: XGROUP/XREADGROUP/XACK/XAUTOCLAIM, max_retries=3, idle=5min, DLQ `conv.dead`, idempotent ordered commit (S3→Redis status→XACK).
- **Status/history:** workers write only to Redis; **PHP consumes the result/ready queue and persists to MariaDB itself** (DB authoritative for history/download; Redis = live status, TTL 24h) (user).
- **S3:** outputs → shared **`apis3.variantgood.com`** MinIO in dev AND prod (no local MinIO container); inputs stay on `/shared-files` (input→S3 is [[docs-prod-polish]]) (user).
- **Registry:** drop archives now (follow-up later); expand libreoffice worker to advertised targets; fix AI routing `isAi→ai` + add `subType` to message (user).
- Download = authenticated PHP proxy; capability-declaring workers, overlap via shared group, multi-instance; launch strategy deferred (follow-up card).

**Spin-off follow-ups (not in this epic):**
- Archive (zip/tar) worker — re-add to registry when implemented.
- Multi-worker launch/scaling strategy.

---

## Execution Log

### Phase 0 — DONE (2026-06-19, commit `1bedcef`)
Scope per user: **"Только Phase 0 пока"** — ship the contract foundation only; phases 1–6 deferred to a later session.

Delivered (cards A + B):
- `docs/queue-contract.md` (new) — canonical PHP⇄Python wire contract: Messenger Redis stream entry (single field `message` = JSON `{body,headers}`, body double-encoded), camelCase job body, Redis status hash `conv:status:{id}`, result event `conversions_result`. Phase-0 = single stream `conversions`; per-key `conv.<key>` naming documented as the Phase-1 target.
- `ConversionMessage` DTO extended: `outputFormat`→`targetFormat`; added `sourceFormat`, `isAi`, `subType`, `options`.
- `ConversionManager::dispatch()` populates all new fields from entity accessors; `resolveSubType()` derives ocr/stt/tts (null for plain formats).
- `messenger.yaml` — both transports use `serializer: messenger.transport.symfony_serializer` (JSON, Python-parseable).
- `docker-compose.yml` — 5 workers' env `REDIS_QUEUE_DB`→`REDIS_DB` (value `${REDIS_QUEUE_DB:-2}`), matching what `base_worker.py` reads; aligned to PHP `REDIS_DSN ?dbindex=2`.

Review: **APPROVE-WITH-NITS** (reviewer). No blockers. Nits all forward-looking/stylistic (final readonly class; result-stream envelope shape to pin in Phase 1; redundant explicit `options: []`).

**Important — not end-to-end yet:** Phase 0 ships only the *producer* + contract. Python workers still consume via Redis **lists**, not the stream, so conversions do NOT flow until the worker stream-consumer switch (Phase 2/4). Expected per scope.

Follow-up found during Phase 0 (→ belongs to [[fix-configs-working-state]] or a config card, NOT a Phase 0 blocker):
- `REDIS_DSN` (and `DATABASE_URL`) are referenced via `%env()%` in Symfony config but defined **only** in the gitignored `app-symfony/.env`. No tracked template (`app-symfony/.env.dist`) provides them → a fresh checkout has no Symfony env defaults. Root cause: the whole `app-symfony/.env` was gitignored because a live Telegram token was placed there instead of `.env.local`. Clean fix: move secrets to `.env.local`, commit `app-symfony/.env` (or `.env.dist`) with non-secret defaults incl. `REDIS_DSN=redis://keydb:6379?dbindex=2`.

### Phase 1–4 (image vertical slice) — IMPLEMENTED + WIRE-VERIFIED, pre-smoke (2026-06-19, commit `7646c17`)
Strategy (user): **vertical slice** — drive ONE worker (image) end-to-end before fanning out. Team: queue-impl (PHP), py-worker (Python), reviewer.

Delivered:
- **PHP (C+D):** 6 per-key transports `conv_<key>` (stream `conv.<key>`, group `convertor`, JSON serializer, **`serializer: 0`** redis conn option); `dispatch()` routes via `TransportNamesStamp`; deleted `ConversionMessageHandler`+HTTP path; `getStatus()` reads Redis hash w/ DB fallback; new `app:queue:result-consumer` (raw XREADGROUP `conv.result`, idempotent persist to MariaDB); `S3Storage` (async-aws) + `/download` authed S3 proxy; `RedisConnectionFactory`/`ConversionStatusReader`/`ConversionResultPersister`.
- **Python (E+G+H):** `StreamConsumerBase` (XGROUP id 0, double-decode envelope, ordered commit S3→HSET→XADD→XACK, XAUTOCLAIM idle 5min, max_retries 3→`conv.dead`, idempotency re-emit); `s3.py` (boto3); image worker→Pillow consumer; 22 pytest green.
- Registry trimmed (svg/heic/avif removed from image until Dockerfile libs added).

Review: **CHANGES-REQUESTED → resolved.** Blocker `symfony/redis-messenger` missing from composer.json (fixed); nits ext-redis declare + `/download` friendly filename (fixed); `getChunks()` verified correct.

**🎯 Critical runtime blocker caught by live wire-check (not by review/phpstan):** `symfony/redis-messenger` Connection DEFAULT_OPTIONS `serializer => 1` (SERIALIZER_PHP) → phpredis wrote `message` as `s:NNN:"{json}";` → Python `json.loads` would throw on every message. Fixed with `options.serializer: 0` on all conv_*; **verified on the wire** (`XRANGE conv.image` → raw `{body,headers}` JSON). worker-image confirmed live: creates group on `conv.image`, XREADGROUP loop running.

Gate: **PHPStan green** (1 slice + 15 pre-existing legacy errors fixed; added `phpstan/phpstan-doctrine`). `composer.lock` now committed.

### ✅ FULL API E2E PASSED (2026-06-19, commits `d24d463` etc.)
S3 access via MCP (`policy_attach convertor-dev → readwrite`; ⚠ broad — MCP can't create a scoped policy; scoped JSON in `docs/infra/` for later). Buckets created via MCP; effective prefix `convertor-dev` (user un-commented `.env.local` override) → bucket `convertor-dev-results`.

**Fully automatic round-trip proven (no manual steps):**
`POST /auth/telegram (JWT) → POST /convert (upload png, quota, mime, store, dispatch) → conv.image (serializer:0 raw JSON) → worker (decode → Pillow png→jpg → S3 PUT convertor-dev-results) → conv.result → cron app:queue:result-consumer auto-persists FileStorage+status to MariaDB → GET /status=completed → GET /download → HTTP 200 image/jpeg, valid JPEG (magic ffd8ff)`. Verified worker-half (status hash + conv.result + XACK + real S3 object) AND full HTTP e2e.

**Result-consumer robustness (routed to queue-impl, happy-path green):** persister logic correct (direct call + clean auto-run both OK), but a poison/orphan event throws → Doctrine **EntityManager closes** → long-running consumer wedges (every later persist fails) until supervisord restart. Fix = per-message catch + reset EM if closed + missing-conversion ack/skip + DLQ. Also py-worker: redis socket `TimeoutError` on XREADGROUP BLOCK crashed the worker (socket_timeout < block) → fix routed.

### fix-configs spillover (found while booting+e2e'ing the stack — belong to [[fix-configs-working-state]])
- FIXED here: `fluent-logging.yml` include path; missing `symfony/twig-bundle` (kernel boot); `enable_annotations`→`enable_attributes` (SF7); `image.Dockerfile` ignored `requirements.txt` (added boto3); **stray `nginx pf.conf`** (upstream `node:5173` of an unrelated frontend → nginx crash-loop) removed; **lexik JWT** `secret_key`/`public_key` → `%env(resolve:...)%` (was writing keys to a literal `%kernel.project_dir%` dir); **`shared-files` volume not mounted into php/cron** (uploads failed) — added; **`symfony/mime`** missing (upload MIME guess); `ConversionManager::createConversion` read upload mime/size AFTER `move()` → "file does not exist" (now reads before).
- RUNTIME-ONLY fix (needs persistent fix): `/shared-files` owned root:root vs php uid 1000 → `chown 1000:1000` applied at runtime; entrypoint should chown SHARE_DIR to PUID:PGID. JWT keypair generated in-container (gitignored).
- STILL OPEN: `make cs`/`cs-check` miss `--allow-risky=yes`; `libreoffice` healthcheck unhealthy (old HTTP main.py, later phase); worker Dockerfiles should install from `requirements.txt` ([[optimize-worker-dockerfiles]]); Symfony Flex scaffolded app baseline left untracked (decide whether to commit); migrations must be run (`make migrate`) on fresh setup.

### Phases 5–6 + remaining workers — DEFERRED (later session)
Per `docs/queue-redesign-design.md` §"Phased cards": migrate ffmpeg/data/ai/libreoffice (H–L remainder), fault-tolerance e2e (M), e2e smoke (N). Card stays in `progress/`.
