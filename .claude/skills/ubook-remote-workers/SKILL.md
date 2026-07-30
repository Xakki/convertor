---
name: ubook-remote-workers
description: >-
  Use when working with the convertor project on the REMOTE host uBook:
  updating/rebuilding/recreating its workers over ssh, inspecting or debugging
  them, or any operation on that host. Holds uBook-specific facts (ssh alias &
  port, repo path, compose project name, which containers are convertor's vs
  unrelated tenants that must NOT be touched, the update happy-path, where to
  verify). Cross-refers image-build-deploy (topology) and
  docs/workers-remote-deploy.md (generic remote setup). Триггеры RU: «обнови
  воркеры на uBook», «пересобери воркеры на uBook», «зайди по ssh на uBook»,
  «воркеры на удалённом хосте uBook», «проверь воркеры uBook», «деплой на
  uBook»; EN: update workers on uBook, rebuild uBook workers, ssh to uBook,
  redeploy remote host uBook, verify uBook workers.
---

# uBook — remote convertor worker host

uBook is a **CPU-only remote host** running six convertor workers as pure
WS-clients of the gateway on the main server (saFin). It has NO gateway, NO
php/db, NO KeyDB — the workers reach the main server's public `wss://` and
Symfony API only. Generic remote-host theory lives in
`docs/workers-remote-deploy.md`; image topology + build/push in skill
`image-build-deploy`. This skill is only the uBook-specific concrete facts.

> Verify these against reality before relying on them — this is a live-host
> fact sheet and hosts drift. Found drift → fix this skill in the same change
> and report to the team-lead.

## Connection & layout (verified 2026-07-23)

| Fact | Value |
|---|---|
| ssh alias | `uBook` (key auth, BatchMode works; it's a tunnel — HostName 127.0.0.1, Port 22100). Always run `ssh uBook '<cmd>'` wrapped in `timeout`. |
| Repo path | `/home/xakki/www/xakki/convertor` |
| Compose project | `convertor-remote-xbook` (`COMPOSE_PROJECT_NAME`). Note "xbook" in the project name; the `host` the workers report to the DB is `uBook`. |
| Docker | 29.x, BuildKit active by default |

## What runs here — and what is NOT ours

**Convertor's** containers (the only ones this skill touches):
`convertor-remote-xbook-worker-ai` (cpu), `-worker-ffmpeg-audio`,
`-worker-ffmpeg-video`, `-worker-image`, `-worker-data`,
`-worker-libreoffice`, plus a local `-fluent-bit` log sidecar. Six workers +
sidecar. **No ws-gateway, no metrics-exporter, no php/mariadb/keydb/nginx** —
those stay only on the main server.

**`make up` / `make down` are SAFE here since 2026-07-30 — but only once
`.env.local` is regenerated from `.env.local_worker_example`.** The server side
(php/cron/mariadb/keydb/nginx/ws-gateway) now sits behind compose profile
`server` and metrics-exporter behind `monitoring`; the worker-host template
activates neither, so `up` starts exactly the 6 workers + the fluent-bit
sidecar (+ logrotate) and `down` stops only those. **Verify before trusting it
on this host:** `ssh uBook 'cd <repo> && docker compose config --services'`
must list no php/mariadb/nginx/ws-gateway/metrics-exporter. With the OLD
`.env.local` (no `COMPOSE_PROFILES`) `up` still tries the full stack and dies
on `network common declared as external, but could not be found` — that network
lives only on saFin. Card
`.claude/kanban/grooming/remote-host-make-up-footgun.md`.

**`-fluent-bit` is not part of `COMPOSE_FILE`** (since `cab0124` moved logging to
a host-level shared fluent). Log shipping WORKS — uBook's `.env.local` sets
`EXT_FLUENT_PORT=0.0.0.0:24224`, the sidecar listens there, and entries land in
Graylog under source `192.168.10.12` (verified 2026-07-29). Since 2026-07-30
`make fluent-up` / `fluent-restart` / `fluent-logs` work again: they run the
submodule compose explicitly (`$(DC_FLUENT)` in the root Makefile), so a stopped
sidecar can be brought back. Card
`.claude/kanban/grooming/fluent-bit-orphan-remote-host.md`.

**⚠ `docker compose config` over bare `ssh` LIES here.** `docker compose`
auto-loads only `.env`; `.env.local` reaches compose solely because the root
Makefile does `include .env.local` + `export`. So a bare
`ssh uBook 'docker compose config'` renders the tracked `.env` defaults (e.g.
`EXT_FLUENT_PORT=127.0.0.1:24224`) and not what the running containers use.
Read the live values instead — `docker inspect <c> --format
'{{.HostConfig.LogConfig.Config}}'` — or drive everything through `make`.

**⚠ Other tenants on the same host — DO NOT touch.** uBook also hosts an
unrelated project (`xakki.pro`, repo `/home/xakki/www/xakki/xakki.pro`):
`xakkipro-php-1`, `xakkipro-mariadb-1`, `xakkipro-redis-1`, and a standalone
`portainer`. A bare `docker compose down` in the wrong directory, or any
host-wide `docker` prune, would hit them. Always operate from the convertor
repo dir via its Makefile targets, never host-wide.

## Update happy-path (the normal task)

All docker work through Makefile targets — never raw `docker compose`. From
the convertor repo dir on uBook:

```bash
ssh uBook 'cd /home/xakki/www/xakki/convertor && git status --porcelain'  # 1. check first
ssh uBook 'cd /home/xakki/www/xakki/convertor && git pull'                # 2. ff expected
ssh uBook 'cd /home/xakki/www/xakki/convertor && make build-workers build-ai-cpu'  # 3. all 6 (AI is a separate target since 2026-07-30) — minutes
ssh uBook 'cd /home/xakki/www/xakki/convertor && make workers-recreate'   # 4. --no-deps, ~seconds
```

- **Never skip step 3.** `workers-recreate` alone recreates containers from the
  EXISTING local images — after a pull that touched worker code the host comes
  up healthy, `alive`, `host=uBook`, with fresh metrics, and still runs the OLD
  code. The DB signals cannot tell the two apart; the decisive check is
  `git diff --name-only HEAD@{1} HEAD -- workers/ docker/workers/`. When in
  doubt just run `build-workers build-ai-cpu` — cache-warm and idempotent (~1 min).
- **Step 1 is load-bearing.** If `git status` shows uncommitted *tracked*
  changes, STOP and escalate — never stash/checkout/reset on uBook (rollbacks
  need explicit user approval). An untracked `shared-files/` dir is known
  cruft, not a blocker (the project has no shared volume; safe to ignore, and
  worth flagging for deletion).
- **git submodule** `docker/fluent-log` — initialised on uBook (v0.1.4).
  `COMPOSE_FILE` does NOT reference `docker/fluent-log/docker-fluent.yml`, so it
  no longer gates `docker compose config`; the `fluent-*` targets reach it via
  the Makefile's `$(DC_FLUENT)` override instead (since 2026-07-30).
- `make build-workers` on a first build after a Dockerfile change ran ~7-8 min
  (torch/ML stack downloads fresh; the BuildKit pip cache mount is empty on
  first run, warm thereafter). apt/base layers hit `CACHED`.

## Verify from the MAIN SERVER, not from uBook

The proof of a successful deploy lives in the **main-server** DB, because
`worker_capabilities` is written by the gateway on saFin, not on uBook. From
`/home/xakki/convertor` on the main server:

- Query `worker_capabilities` (via an existing Makefile console/sql target) and
  confirm the uBook rows now have `host = uBook`, non-null `metrics`
  (cpu/mem/load), and a fresh `last_seen`. Empty `host` + zero metrics = a
  pre-2026-07-23 image. **Not** a stale-code check in general: an old image
  built after that fix reports `host`/metrics just fine (see step 3 above).
  Rows appear ~60-90 s after recreate — query too early and you'll see only the
  previous `disconnected` rows.
- Old `instance_id` rows (`<container-id>:...`, `host=NULL`) are never cleaned
  up and stay `disconnected` forever — filter on `host="uBook"`, don't read the
  full table.
- `make -C workers gateway-logs | grep -i "no capability row"` must be EMPTY.
  Wait past the gateway's ~60s liveness-snapshot warmup before trusting it.
- On uBook itself, `ssh uBook 'docker ps'` should show all 6 workers
  `healthy`, none restarting — necessary but not sufficient; the DB check
  above is the real confirmation.

## See also
- `image-build-deploy` — image topology, main-server deploy, Harbor.
- `docs/workers-remote-deploy.md` — full generic remote-host setup (preflight
  connectivity checks, fluent-bit, first-time provisioning).
- `docs/worker-ai-deploy.md` — AI worker two-layer build detail.
