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

**Open questions:**
- Local dev: spin up a local Graylog container, or always ship to remote `log.variantgood.com`?
- Workers log_format: reuse `php`/generic, or add a `python`/`worker` parser in fluent-bit?
- Is `docker/logs/` meant to hold fluent-bit config, or is everything inside the submodule? Decide what's committed here.
- Submodule pinning: lock to a specific commit/tag?

**Decisions:**
- (to be filled during grooming)
