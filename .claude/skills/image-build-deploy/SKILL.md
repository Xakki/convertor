---
name: image-build-deploy
description: >-
  Use when asked to build/push convertor Docker images to Harbor, deploy new
  worker/gateway code to the main server or a remote worker host, or verify a
  deploy actually took effect. Covers image topology (what's built locally vs
  pulled vs pushed), the main-server build→recreate→verify sequence, and why
  pushing to Harbor does NOT update a remote host. Триггеры RU: «собери и
  запушь образ», «обнови воркеры на удалённом хосте», «задеплой gateway»,
  «проверить что деплой применился», «Harbor push», «пересобрать образы»,
  «make harbor-login не работает»; EN: build and push image, update workers
  on remote host, deploy gateway, verify deploy took effect, Harbor push,
  rebuild images, make harbor-login broken.
---

# Image Build & Deploy Topology

Answers "what talks to Harbor, who builds what, in what order" — the map that
`make help` doesn't give you (targets are documented there; this skill is the
topology those targets sit inside).

> Sources: root `Makefile`, `workers/Makefile`, `docker-compose.yml`,
> `docker/workers/*.Dockerfile`, `docs/workers-remote-deploy.md`,
> `docs/worker-ai-deploy.md`. Verify any fact here against those before
> relying on it — found drift → fix this skill in the same change and report
> to the team-lead.

## Image topology

| Image | Source | Published to Harbor? |
|---|---|---|
| php (`DOCKER_IMAGE_PHP`), mariadb, nginx, keydb | external — pulled by tag from Harbor mirrors/registry (`harbor.xakki.ru/library/...`, `.../external/...`) | n/a — these are pulled, never built here |
| `worker-libreoffice`, `worker-ffmpeg` (audio+video share one image), `worker-image`, `worker-data`, `metrics-exporter` | built locally, `docker-compose.yml` `build:` context `.` | **No** |
| `ws-gateway` | built locally (`build-gateway`) | **Yes** — `push-gateway` → `harbor.xakki.ru/convertor/ws-gateway:latest` |
| `worker-ai-base` | built locally (`build-ai-base`, `FROM scratch`, code only) | **Yes** — `push-ai-base` → `harbor.xakki.ru/convertor/worker-ai-base:latest` |
| `worker-ai:cpu` / `worker-ai:cuda` | built locally FROM `worker-ai-base` + ML stack (`build-ai-cpu`/`build-ai-cuda`) | **No** |

So today only two images ever leave the building via Harbor push:
**ws-gateway** and **worker-ai-base**. Everything else either comes from
Harbor (the four externals) or never goes near it (the five plain worker
images + the two worker-ai working variants).

`push-gateway`/`push-ai-base` normally rely on the already-cached docker
credential for `harbor.xakki.ru` — **`make harbor-login` is NOT a routine
step of this flow.** Run it only when a push actually fails with an
auth error (401/403/`unauthorized: unauthorized to access repository`/
`denied`); re-running it every time is noise.

Worker/gateway Dockerfiles' own `FROM` lines pull generic upstream bases
(`python:3.12-slim`, `nvidia/cuda:...`) from Docker Hub, not Harbor — the
*only* Harbor-sourced input into a build is `${AI_BASE_IMAGE}`, and the
Makefile normally builds that fresh locally rather than pulling it (see
next section).

The two-layer AI scheme (`worker-ai-base` → `worker-ai:cpu`/`:cuda`) is
detailed in skill **`worker-ai-image`** — don't restate it here, cross-refer.

## Who builds what, where (the non-obvious part)

**Pushing to Harbor is not how a remote worker host gets new code.** The
documented remote-host flow (`docs/workers-remote-deploy.md`) is a full `git
clone` of this repo on the remote host, then `make build-workers`, which
builds **all six** worker images locally from source — including
`build-ai-cpu`, which itself depends on `build-ai-base` and rebuilds
`worker-ai-base` from source on that same host. Nothing is pulled from
Harbor in this flow, not even `worker-ai-base`.

Remote update sequence (repo-clone path — the normal one):
```bash
git pull
make build-workers       # rebuilds all 6, incl. ai-base from source
make workers-recreate    # --no-deps, only the 6 worker containers
```
`push-ai-base`/`push-gateway` are irrelevant to this host.

The **only** path where `worker-ai-base:latest` actually gets pulled from
Harbor is the no-repo path (no clone, just the Dockerfiles) — see
`docs/worker-ai-deploy.md` "path 2b": `docker login harbor.xakki.ru` →
`docker pull harbor.xakki.ru/convertor/worker-ai-base:latest` → build the
working image `FROM` that pulled tag. This is the exception, not the norm.

## Deploy order on the main server (code change touching workers/gateway)

1. **Build** — `make build-workers` (all 6, incl. ai-base→ai-cpu) or a
   narrower `make build-<name>` target for a single worker; `make
   build-gateway` for the gateway.
2. **Recreate** — `make workers-recreate` (the 6 worker containers,
   `--no-deps`) and/or `make gateway-up` / `docker compose up -d
   --force-recreate --no-deps ws-gateway` for the gateway specifically.
3. **Verify** — don't trust "recreate exited 0" as proof anything changed:
   - Container health: `docker compose ps` (all target containers
     `healthy`/`running`, not restarting).
   - Workers re-registered: check `worker_capabilities` rows have a fresh
     `last_seen`, a populated `host` column, and non-null `metrics` — either
     via `/admin` → Воркеры, or a direct `SELECT` against the table.
   - Gateway not complaining: `make -C workers gateway-logs | grep -i "no
     capability row"` should come back **empty**. A hit means some
     worker/gateway instance is connected but PHP has no matching row for
     it — investigate before calling the deploy done.

## Pitfalls (all hit for real 2026-07-23)

- **`make harbor-login` is currently non-functional.** `DOCKER_REGISTRY`/
  `DOCKER_USER`/`DOCKER_PASS` are unset in both `.env` and `.env.local`
  (verified by grep — none of the three appear in either file). Pushes to
  Harbor only succeed today because of a cached docker credential for
  `harbor.xakki.ru` already present in the local `~/.docker/config.json`. On
  a clean machine or CI runner with no prior `docker login`,
  `push-gateway`/`push-ai-base` fail — that's exactly the case where
  `make harbor-login` is the (currently broken) recovery step. See kanban
  card `make-login-not-configured` (grooming).
- **Never trust a push's exit code alone.** Verify the tag actually landed:
  `docker manifest inspect harbor.xakki.ru/convertor/<image>:<tag>` and
  compare the digest against the local image (`docker inspect --format
  '{{.Id}}'`). A push interrupted mid-transfer is safely resumable and
  idempotent — just re-run it, don't assume corruption.
- **Recreating the gateway can also recreate keydb.** `ws-gateway` has
  `depends_on: keydb (condition: service_healthy)` in `docker-compose.yml`.
  Recreating it *without* `--no-deps` (e.g. a bare `docker compose up -d
  --force-recreate ws-gateway`) recreates `keydb` too as a pulled-in
  dependency. Data survives — `keydb-data` is a named volume — but expect a
  brief KeyDB restart. Add `--no-deps` to avoid it; the e2e Makefile targets
  already do this deliberately.
- **Mid-deploy worker restarts can race php/nginx.** A worker's initial
  `register()` used to run exactly once per WS connection; if it fired while
  php/nginx were still bouncing, registration failed silently and never
  retried. The gateway now heals this for current-image workers: it detects
  a `worker_capabilities`-less ("unknown") instance and pushes a
  `re-register` control frame over the live WS connection. **A worker still
  running an OLD image has no client-side handler for that frame and will
  never re-register on its own** — it needs an explicit recreate to pick up
  the fix, not just a wait.

## Build cache (BuildKit)

- `worker-ai-base` is `FROM scratch` carrying worker SOURCE + requirements, so
  it changes on every code change. `ai.cpu.Dockerfile`/`ai.cuda.Dockerfile`
  therefore copy only the requirements files out of it *before* the pip
  installs; the full `COPY --from=aibase /app /app` is the LAST content
  layer. Don't collapse that into one early copy — it re-installs ~2GB of
  torch on every code change.
- Every `RUN pip install` uses a BuildKit cache mount
  (`--mount=type=cache,target=/root/.cache/pip`) with `PIP_CACHE_DIR` pinned
  and `PIP_NO_CACHE_DIR=0`. A `--no-cache-dir` CLI flag would silently defeat
  this (env var can't override a CLI flag). Needs `# syntax=docker/dockerfile:1.7`
  on line 1 of each Dockerfile; BuildKit itself is on by default (Docker 29.x).
- Measured on `worker-data`: full build ~30s, rebuild after a source-only
  change ~3.6s with the pip layer `CACHED`.
- `make rebuild` (`--no-cache`) intentionally bypasses all of this — it's the
  escape hatch, not the default.

## See also

- `worker-ai-image` — the two-layer AI image build/deploy detail.
- `docs/workers-remote-deploy.md`, `docs/worker-ai-deploy.md` — full
  step-by-step remote-host and AI-worker instructions.
- `.claude/kanban/grooming/make-login-not-configured.md` — the
  `make harbor-login` gap tracked as a card.
