### Conversion chaining — offer A→B→C via two available conversions (Stage 7)

**Criticality:** Low — Stage 7 (post-MVP)

**TAGS:**
- feature

**Description:**
Stage 7. Offer a conversion as a CHAIN of two currently-available conversions (A→B→C when A→B and
B→C exist), so pairs no single worker handles can still be served (e.g. `epub→pdf` = epub→docx→pdf).
User requests A→C explicitly; the backend finds the path and runs it transparently under one
`chainId`. Chained pairs are NOT advertised as first-class in `/formats`.

**Decisions:**
- **Path-finding = on-demand BFS at submit, depth cap 2**, as a `findPath(from,to,maxDepth=2)` method
  on `ConversionRegistry` over `buildMatrix()` output (matrix is tiny → trivial cost, always fresh).
  Graph nodes = real plain formats only — **exclude** virtual STT/TTS keys (any key with `_`, e.g.
  `mp3_stt`) and flag-only OCR pairs (require explicit intent, not BFS discovery).
- **Orchestration = `ConversionCompleted` domain event emitted by `ConversionResultPersister`**, a
  listener dispatches the next pending hop. CRITICAL: both worker paths (on-server via
  `InternalWorkerController`, off-server/AI via `WorkerController`) converge ONLY at
  `ConversionResultPersister::persist()` — the advance hook MUST live there, else AI/off-server hops
  never advance.
- **Hop plan materialized as rows at submit** — add `chainId` + `sequence` (+ `finalToFormat` or
  parent self-FK) to `Conversion`; create ALL N hop rows at submit (hop-1 dispatched, hop-2+ Pending
  undispatched); each hop's `inputFile` = prior hop's `outputFile`, wired as each completes. "Advance"
  = "dispatch next pending hop" — idempotent, survives crash-between-flush-and-dispatch. (Lazy creation
  rejected: persister's terminal-state idempotency guard would wedge the chain on a redelivered result.)
- **Intermediate file B = copy `-results`→`-inputs` on advance** (Phase 1; simplest — dispatch hardcodes
  inputs bucket). Cleanup via existing TTLs; optional eager delete on chain completion. Parameterizing
  dispatch's input bucket = Phase 2 (touches hot single-conversion path).
- **Ambiguity (multiple 2-hop paths) = fewest hops → prefer non-AI intermediate** (mirrors the
  registry's existing AI-last-resort principle); cost/quality as later tiebreak.
- **Error handling = whole chain fails** — failed hop marked `Failed` with real worker reason (existing
  DLQ path), remaining un-run hops cancelled/Failed pointing to the failed hop; user status surfaces
  which hop (`sequence` + `from→to`). DLQ stays per-hop.
- **Quota = check BOTH counters (isAi + non-isAi) for the whole plan atomically up front**, then
  charge per-hop-type; refund every already-charged hop on chain failure. (QuotaService splits
  check/charge/refund by isAi — a mixed chain must reserve both up front or it half-runs.)
- **Phase 0 timing = STAYS in Stage 7** [USER DECISION 2026-07-01] — whole epic post-MVP; do NOT pull
  `findPath()` forward, even though it is buildable on the current matrix today.
- **(2026-08-01 grooming)** Дизайн готов; **не стартовать раньше Stage 7 / по приоритету ROADMAP**.
- **(2026-08-03 team-lead)** Remaining undropped hops on chain failure → status `Failed` (no new
  `Cancelled` enum in Phase 1), message points to failed hop.
- **(2026-08-03 team-lead)** POST `/convert` returns hop-1 conversion id; status by any hop id resolves
  chain via `chainId`; download only when final hop `Completed`.
- **(2026-08-03 team-lead)** Enablement: allowlist stub (default empty/off) — mechanism in Phase 1,
  curated edges later.

**Phasing:**
- **Phase 0** — `findPath()` on `ConversionRegistry` (pure, unit-testable, no wiring). Buildable on the
  current `buildMatrix()`; kept in Stage 7 per decision.
- **Phase 1** — depth-2 only; entity `chainId`/`sequence` migration; materialize hops at submit;
  `ConversionCompleted` event + listener; copy-B-to-inputs; whole-plan per-bucket quota; chain-aware
  failure propagation. Direct single-worker pairs ALWAYS preferred over chains. Enable only over a
  curated known-good edge subset (or once self-registration guarantees edge accuracy).
- **Phase 2** — depth-3+ if warranted; parameterized input bucket; cost/quality tiebreaks; eager cleanup.

**Dependencies:**
- `registry-00-self-registration` — **reframed from HARD block to enablement lever.** The graph mechanism
  exists now (`buildMatrix()` → depth-2 BFS runs today); self-registration buys **edge accuracy**.
  Enablement should gate on either self-registration OR a curated MVP-validated edge subset.
- `align-document-stream-matrix-dlq` — **DONE** (`done/`). PermanentError fast-DLQ already landed;
  no longer a start-blocker for chaining design, still a prerequisite for safe enablement in prod.
- `stage7-libreoffice-extra-formats` — the concrete consumer (its epub→pdf pandoc chain).
- `quota-service-hardening` — **DONE** (`done/`). Former frozen `quota-charge-refund-atomicity`
  concern addressed there; chaining still multiplies charge/refund per hop — follow the hardened API.

**Acceptance Criteria:**
- `findPath()` + depth-2 chaining per Decisions above; chained pairs not first-class in `/formats`.
- Tests/QA green per project gates when implemented.
- Start only when Stage 7 / ROADMAP priority says so (Decision 2026-08-01).

**Status:** progress (Phase 1 mechanism wired; enablement allowlist empty until curated edges).

## Execution Log

- Started Phase 0+1; branch `task/CNV-5`; team = Grok + composer for chores.
- Team-lead decisions recorded (2026-08-03); Phase 0 in progress.
- Phase 0 done (2026-08-03): `ConversionRegistry::findPath()` BFS + `ConversionRegistryFindPathTest` (12 tests). Manager/Entity/Persister/Quota untouched.
- Quota multi-hop API done (2026-08-03): `QuotaService::checkPlan` / `chargeHop` / `refundHops` + unit tests; Manager not wired.
- entity-chain-schema done (2026-08-03): `Conversion.chainId`/`sequence`/`finalToFormat` + `Version20260803180000` + repo `findByChainIdOrdered`/`findNextPendingHop`. Manager/Persister/Quota still untouched.
- manager-chain-submit + persister-event-advance done (2026-08-03): `ChainEnablement` (`CHAIN_ENABLED_PAIRS`, default empty), `ConversionManager` chain materialize (hop-1 return), `ConversionCompleted`/`ConversionFailed` from `ConversionResultPersister`, `ConversionChainListener` advance (results→inputs copy) + fail-propagate/`refundHops`, status/download chain-aware. Unit tests green (`make TEST=1 test-php-unit`). Prod unchanged until curated pairs added to allowlist.
- QA (2026-08-03): gates `make phpstan` PASS, `make cs-check` PASS (after CS align + listener phpstan fix), `make TEST=1 test-php-unit` PASS (332 tests; pre-existing PHPUnit Deprecations×6 / Notices×10 — not CNV-5). AC smoke OK: `findPath` present; `/formats` = `getSupportedFormats()` matrix only (no chain pairs); `CHAIN_ENABLED_PAIRS` default `''`; `ConversionCompleted`/`Failed` dispatched only from `ConversionResultPersister`. Residual: no dedicated `ChainEnablementTest`; enablement still empty (by design).
- Review must-fixes (2026-08-03): (1) `createChain` hop-1 abort fail-propagates sibling Pending via shared `ConversionChainFailPropagator`; (2) idempotent Completed persist re-emits `ConversionCompleted` when next Pending exists; (3) `wireIntermediateInput` validates S3 keys via `assertSafeObjectKey` before copy. Also: status JSON `from_format`/`to_format`; entity sequence doc → 1-based. Gates: phpstan/cs-check/test-php-unit PASS (336).
- PlanQuota createChain abort fix (2026-08-03): on ANY post-flush abort mark hop-1 Failed if still Pending, then always `failPropagateFrom`; `ConversionChainFailPropagator` required ctor dep on `ConversionManager`. Unit: `testCreateChainPlanQuotaDispatchAbortFailPropagatesSiblings`. Gates: phpstan/cs-check/test-php-unit PASS (337).
- QA green (337 unit), review APPROVE (after PlanQuota abort fix). Phase 0+1 complete; enablement via empty CHAIN_ENABLED_PAIRS (prod off).

