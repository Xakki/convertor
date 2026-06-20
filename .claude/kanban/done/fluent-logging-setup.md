### Finish fluent-logging setup (docker/fluent-logging.yml)

**Criticality:** High

**TAGS:**
- feature
- tech-debt

**Description:**
`docker/fluent-logging.yml` defines the fluentd logging driver config (tags, labels, per-service `log_format`) and depends on a `fluent-bit` service from an included submodule. It must align with the cross-project standard (skill `ai-agents-skills:fluent-logging`: containers emit structured JSON → fluent-bit → Graylog GELF). The file is currently non-functional.

**Problem / TODO (from the file + checks):**
- Line 1: `# TODO: get submodule https://github.com/Xakki/FluentLog` — the include `fluent-log/docker-fluent.yml` does **not exist** (no `.gitmodules`, no `fluent-log/` dir). **Hard blocker.**
- `fluent-bit` service is referenced in `depends_on` but defined only in the missing submodule → compose fails.
- Logging config covers only php/cron/mariadb/redis/nginx — **workers and libreoffice are not wired** for logging.
- `EXT_FLUENT_PORT` / `EXT_FLUENT_METRIC_PORT` present in `.env` but missing from `.env_dist`.
- Graylog is external (`log.variantgood.com:443/gelf` per `.env`) — confirm vs a local dev Graylog.
- `docker/logs/` is empty (only `.gitignore`) — unclear if fluent-bit config is expected there or comes from the submodule.

**Impact:**
No centralized/structured logging; can't verify the stack via logs (blocks [[smoke-run-verify]]'s log-check step).

**Recommendation:**
Add the `Xakki/FluentLog` git submodule, wire all services (incl. workers + libreoffice) into the `_logging` anchor, sync env vars to `.env_dist`, and follow the `ai-agents-skills:fluent-logging` standard (JSON to stdout/stderr, GELF to Graylog).

**Acceptance Criteria:**
- `Xakki/FluentLog` submodule present and `fluent-bit` service resolves; `docker compose config` valid.
- All app + worker + libreoffice containers carry `<<: *_logging` with correct `log_format`.
- Logs visible in target Graylog (or local dev sink) end-to-end.
- `.env_dist` includes fluent vars with comments.

**Decisions (resolved):**
- **Graylog → REMOTE** `log.variantgood.com:443/gelf` (no local dev container) (user).
- Workers `log_format: "auto"` — FluentLog has no python parser; `gl.auto`/`json_default` unwraps worker JSON globally. Switch to a `python` parser if added upstream.
- fluent-bit config lives in the submodule (not `docker/logs/`); `Xakki/FluentLog` pinned to **v0.1.4** (`2d0c11c`).

**Execution Log:**
- Implemented by logging-fixer; reviewed by reviewer (APPROVE-WITH-NITS → nits fixed).
- Submodule `docker/fluent-log` (v0.1.4) added (provides fluent-bit + logrotate); `COMPOSE_FILE` restored to `docker-compose.yml:docker/fluent-logging.yml` in .env + .env_dist.
- All 6 remaining services (libreoffice + 5 workers) wired with `<<: *_logging` + tier/log_format=auto + depends_on fluent-bit; existing 5 untouched; keydb rename intact.
- Workers converted to structured JSON stdout: new `workers/common/logging_config.py` (stdlib JsonFormatter, Monolog-numeric levels, ISO8601, recursive secret redaction over message+context+exception); base_worker + all worker entrypoints + libreoffice/main.py route through it; no stray basicConfig.
- Fixed pre-existing broken `COPY main.py` in libreoffice.Dockerfile; added `__pycache__/`+`*.pyc` to .gitignore.
- Validation: PyYAML parse OK + py_compile OK + redaction smoke OK (`docker compose config` unavailable in sandbox).
- Committed: submodule/.gitmodules in `779c7e8`; impl in `5582e0c`.

**Status — handed to `test/`:** implemented + reviewed; static validation green. Runtime verification (logs actually arriving in Graylog) deferred to [[smoke-run-verify]] (needs `docker compose up`). Out-of-scope note: libreoffice HTTP-proxy vs `libreoffice` service overlap (reviewer N3) handled in the queue redesign (libreoffice→consumer).
