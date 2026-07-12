### Dynamic worker self-registration → DB-driven conversion matrix (EPIC)

**Criticality:** Medium

**TAGS:**
- feature
- tech-debt

**Description:**
EPIC (Stage 2+). Workers register their conversion capabilities at startup; PHP builds the
conversion matrix dynamically from a DB source, replacing the hardcoded
`ConversionRegistry::workerCapabilities()`. Capabilities are today declared in **3 hardcoded
places** — Python per-worker `CAPABILITIES` (canonical), PHP `workerCapabilities()`, and
`WorkerController::ALLOWED_TYPES`. No worker/capability DB table exists. On-server workers attach
to KeyDB Streams directly with no registration; remote workers use only the pull-API.

**Impact:**
While the matrix is hardcoded, PHP and workers drift; AI routing and multi-worker pairs need a
generalized router the static matrix can't express.

**Decisions:**
- **Registration transport = `POST /api/v1/worker/register` on the pull-API for ALL workers**
  (only option honoring "remote workers get no direct DB/KeyDB" rule; on-server can also reach PHP).
  **Registration MUST be best-effort / non-fatal** — a worker that can't reach PHP still starts and
  consumes from KeyDB (else a PHP blip halts on-server processing = regression).
- **Storage = new Doctrine entity `WorkerCapability`**, one row per worker-type, JSON `capabilities`
  blob mirroring the Python dict 1:1 (stream, isAi, routing_keys, matrix, image/version, last_seen),
  upsert keyed on worker-type. Registry reads a `WorkerCapabilityRepository` instead of the hardcode;
  `getSupportedFormats()`/`isSupported()`/`streamFor()` signatures unchanged; cache the built matrix.
- **Startup / no-worker-up = keep hardcoded `workerCapabilities()` as fallback when DB empty/unreachable
  (Phase 1); seed DB via migration so it's never empty (Phase 2).** Empty-matrix-reject was rejected
  (service-down window, blank `/formats`).
- **Eviction = long-TTL GC, NOT short liveness gating.** KeyDB streams buffer durably → a down worker
  doesn't need its pairs removed; jobs wait. Separate **capability** (durable, feeds `/formats`+routing)
  from **liveness** (last_seen, for monitoring + future candidate selection). Prune capability only
  after a generous configurable TTL (hours–days) or explicit deregister.
- **`/formats`** — unchanged externally; internal source becomes the repository (active capabilities).
- **Drift-test rework** — once PHP no longer *declares* pairs, drift dissolves; replace with a
  **register-round-trip** test (registered DB == union of workers' declared CAPABILITIES). Note:
  `bin/dump-matrix.php` does `new ConversionRegistry()` with no container → breaks when the registry
  needs a repository; needs a seeded DB/fixture; retire/regenerate `ConversionRegistryGoldenTest`.
- **⚠ Stage-7 "coming-soon" pairs = LET THEM DISAPPEAR (honest 400).** [USER DECISION 2026-07-01]
  A registration-driven matrix contains only what a live worker declares, so the deliberately-advertised
  unhandled pairs (xlsx→pdf, ppt→pdf, dwg→*, pdf→jpg) vanish and the API flips from accept-then-DLQ to
  **400-reject-at-submit**. This **overrides** `align-document-stream-matrix-dlq`'s "do NOT remove the
  Stage-7 pairs" decision. Interim (before Phase 2 lands): those pairs still exist → the align-document
  `PermanentError` fast-DLQ covers them; after Phase 2 they're gone → 400 up-front, no churn.
- **Collision policy (interim, Phase 1-2):** two workers registering the same pair → mirror current
  `buildMatrix()`: non-AI precedence, last-registered-wins. Real multi-candidate handling = Phase 3.

**Phasing:**
- **Phase 1 — infra, no behavior change.** `POST /worker/register` (both classes, best-effort);
  `WorkerCapability` entity + migration + repository; registry builds matrix from DB **with hardcoded
  fallback**; workers call register at startup (`StreamConsumerBase.__init__`; AI via `PullApiClient`);
  add caching. Drift/golden tests unchanged (fallback covers). Independent of the AI-pair shape.
- **Phase 2 — remove hardcode + eviction.** Seed DB; delete `workerCapabilities()`; derive
  `WorkerController::ALLOWED_TYPES` from the registry (the 3rd hardcoded source); heartbeat + long-TTL
  GC + admin visibility; drift→register-round-trip; retire/regenerate golden. Stage-7 pairs disappear
  here (per decision above). **Blocked by `php-ai-virtual-key-submit-resolution`** (AI worker's
  CAPABILITIES.matrix is empty by design until that card flattens the `_stt`/`_tts` virtual keys).
- **Phase 3 — generalized candidate+intent router** (absorbs php-ai-virtual-key Phase 2). Matrix stores
  multiple candidate workers per pair (pdf→txt: document-extract vs image-OCR); `streamFor()` becomes a
  resolver taking intent/flag + picks among live candidates (liveness now matters). Generalizes the
  `ocr` special case. Foundation for `conversion-chaining` graph path-finding.

**Dependencies:**
- `php-ai-virtual-key-submit-resolution` (todo) — blocks **Phase 2** specifically (not Phase 1).
- `align-document-stream-matrix-dlq` (todo) — interacts via the Stage-7-pairs decision above.
- `conversion-chaining` (grooming, Stage 7) — needs Phase 3's live capability graph for path-finding.
- **S1 WS-transport (in-flight) — Phase 1 register-hook seam MOVED.** The design note
  "workers call register at startup (`StreamConsumerBase.__init__`)" predates S1.
  `[[s1-10-streamconsumer-refactor-unify]]` splits `StreamConsumerBase` into transport-agnostic
  `process_job` + a transport layer, and `[[s1-08-shared-ws-client]]` introduces the shared WS client.
  → Phase 1 must hook `register()` into the **shared WS-client startup (s1-08 seam)**, not the old
  `StreamConsumerBase.__init__`, and start only AFTER `[[s1-11-onserver-workers-migrate]]` (else it
  targets a seam S1 is deleting). Best-effort/non-fatal rule unchanged.

**Status:** grooming (epic, Stage 2+). Design settled.
- **Phase 1 выделен в todo** → `[[registry-01-worker-register]]` (2026-07-07, после приземления
  s1-11). Seam register() уточнён: хук в `WsClient` на `ready` (единообразно для всех воркеров),
  а не старый `StreamConsumerBase.__init__`. Register-контракт закреплён в карточке Phase 1.
- Phase 2/3 остаются здесь до своей очереди (Phase 2 блокирована `[[php-ai-virtual-key-submit-resolution]]`;
  Phase 3 — нужен дизайн multi-candidate router).

**Carry-over из ревью Phase 1 (в Phase 2):**
- **Семантика `streams` vs `routingKeys`.** Сейчас Python шлёт в оба поля одно и то же (`routing_keys` из
  CAPABILITIES, напр. `["image"]`). Phase 2 должна решить: это разные сущности (имена stream-каналов
  `conv.<type>` vs routing-суффиксы) или их схлопнуть. Пока хранятся оба в blob как есть.
- **`isAi` источник.** Python выводит `isAi` из `worker_type == "ai"`, а не из `CAPABILITIES["isAi"]`.
  Для Phase 1 корректно (единственный AI — `worker_type="ai"`); в Phase 2 брать из `caps.get("isAi", False)`.
- **Upsert TOCTOU.** `WorkerCapabilityRepository::upsert()` — find-then-update на уровне PHP; при гонке двух
  одновременных register одного `workerType` второй `flush()` упрётся в UNIQUE и даст 500. Для Phase 1
  безвредно (register единожды на старте). В Phase 2 — нативный `INSERT ... ON DUPLICATE KEY UPDATE`.
