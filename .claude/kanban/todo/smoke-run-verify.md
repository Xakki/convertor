### Smoke-run the stack, check logs, run tests

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
End-to-end verification that the project actually runs: bring the stack up, confirm services are healthy, inspect logs for errors, and run the test suites. This is the final "does it work" gate.

**Problem:**
The stack has never been confirmed to boot cleanly (config defects, missing deps, logging not wired). No green-run baseline exists.

**Impact:**
Without a verified run, all other work is built on unknown ground.

**Recommendation:**
Run after its prerequisites land. Steps:
- `docker compose up` → all services healthy.
- Submit one conversion per worker category (doc, image, audio, video, data, ai) and confirm output files appear in `/shared-files/`.
- Inspect container + Graylog logs for errors/warnings; triage findings.
- Run `composer test:*` (PHP) and `pytest workers/tests` (workers).

**Dependencies (must precede this card):**
- [[fix-configs-working-state]] — stack must boot.
- [[fluent-logging-setup]] — needed for the log-check step.
- [[worker-conversion-tests]] — provides the worker test suite to run.

**Acceptance Criteria:**
- All services reach healthy state.
- At least one successful conversion per worker category, output verified.
- Logs reviewed; no unhandled errors (or filed as follow-up cards).
- PHP tests (`composer test:phpstan`, `test:cs-check`, PHPUnit) and worker pytest suite green.

**Decisions:**
- Form = **`make smoke` target built on `test-e2e`** (extend e2e to all categories), not a manual checklist (keep only a short manual log-review note).
- markup category = **fold into the document check** for now (no separate .md fixture). Later: add dependent tests with reverse conversion.
- AI leg = **hard-require green** (error out if not working) — all workers must be configured.
- DE-STALE the body: `/shared-files` → S3 buckets + MCP minio verification ; bare `docker compose up` → `make up`/`make ps`/`make docker-check` ; `composer test:*` → `make phpstan`/`make cs-check`/`make test-php`/`make test-python`.
- Depends on `ci-test-db-provisioning`.

**Status:** ready (todo).
