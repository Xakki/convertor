### Smoke-run the stack, check logs, run tests

**Criticality:** High
**Epic:** [[CNV-54]]

**TAGS:**
- tech-debt

**Description:**
End-to-end verification that the project actually runs: bring the stack up, confirm services are healthy, inspect logs for errors, and establish a green smoke baseline via `make smoke`.

**Problem:**
The stack has never been confirmed to boot cleanly (config defects, missing deps, logging not wired). No green-run baseline exists.

**Impact:**
Without a verified run, all other work is built on unknown ground.

**Recommendation:**
Run after its prerequisites land. Steps:
- `make docker-check` → compose configs ok; `make smoke` → services healthy + conversions.
- One conversion per worker category (document, image, audio, video, data, ai) via e2e seed→WS→S3; confirm outputs in `${S3_BUCKET_PREFIX}-results` (MinIO MCP / test assertions; not `/shared-files`).
- Inspect container logs; triage findings (grooming cards).
- Full PHPUnit / worker pytest / phpstan / cs — **deferred to CNV-54 epic integration checklist**.

**Dependencies (must precede this card):**
- [[fix-configs-working-state]] — stack must boot.
- [[fluent-logging-setup]] — needed for the log-check step.
- [[worker-conversion-tests]] — provides the worker test suite to run.
- Depends on `ci-test-db-provisioning`.

**Acceptance Criteria:**
- All services reach healthy state (test stand incl. AI profile for smoke).
- At least one successful conversion per worker category, output verified in S3.
- Logs reviewed; no unhandled errors (or filed as follow-up cards).
- `make smoke` target exists and passes (built on `test-e2e`).
- Full `make test` / phpstan / cs-check — **deferred to CNV-54 integration gate** (explicit).

**Decisions:**
- Form = **`make smoke` target built on `test-e2e`** (extend e2e to all categories), not a manual checklist (keep only a short manual log-review note).
- markup category = **fold into the document check** for now (no separate .md fixture). Later: add dependent tests with reverse conversion.
- AI leg = **hard-require green** — smoke enables `COMPOSE_PROFILES=server,test,ai`.
- DE-STALE: `/shared-files` → S3 + MinIO; bare `docker compose` → `make *`; full suite → epic gate.
- AI smoke case = **txt→wav TTS (espeak)** — lightest path.

**Status:** ready.

## Execution Log
- 2026-08-02: `make docker-check` ok; implemented parametrized `test_workers_e2e.py` (6 cases) + `make smoke` / `smoke-run` (AI profile on).
- 2026-08-02: `make smoke` → **6 passed** in ~15s (data/image/audio/video/document/ai); all test-stand services healthy incl. `worker-ai`.
- 2026-08-02: log review — startup WS `ConnectionRefused` then reconnect (benign race); one stale `dlq-fail` 404 (conv 37, leftover); compose warning on shared `worker-ai-models`/`worker-ai-data` → filed [[CNV-56]].
- 2026-08-02: full `make test` / phpstan / cs / PHPUnit / pytest unit — **deferred to CNV-54 integration checklist**.
