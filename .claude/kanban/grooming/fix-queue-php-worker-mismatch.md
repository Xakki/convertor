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

**Open questions (DESIGN phase — to resolve with user):**
- Capability routing under overlap: one Messenger stream + workers filter by declared caps, or one stream per capability/category, or a routing key? How does Messenger group/consumer semantics map to "any worker that can handle it"?
- Fault-tolerance: Messenger Streams consumer-group ack/claim (XACK/XAUTOCLAIM) for crash recovery — retry limits, dead-letter? How does the Redis status store stay consistent with stream redelivery?
- Redis status schema: key layout, what's stored (state, output S3 key, error), TTL, and how PHP polling reads it (direct Redis read vs sync to MariaDB `conversions` table that already exists).
- S3/MinIO: reuse the storage work in [[docs-prod-polish]] (S3 result sink) — sequence/couple them. Local-dev MinIO vs shared infra?
- libreoffice conversion to queue: keep soffice profile-isolation per job; capability list (doc/docx/odt/pdf/html/epub→docx/txt/md).
- Multi-worker launch/scaling — explicitly deferred; capture as a follow-up card.

**Decisions:**
- Transport = Messenger Redis Streams + JSON serializer; workers consume Streams (user, 2026-06-19).
- Results: files→S3/MinIO, status→Redis, fault-tolerant (user, 2026-06-19).
- libreoffice becomes a queue consumer (user, 2026-06-19).
- Capability-declaring workers, overlapping caps, multiple instances; launch strategy deferred (user, 2026-06-19).
