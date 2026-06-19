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
