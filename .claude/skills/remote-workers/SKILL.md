---
name: remote-workers
description: >-
  Use when updating convertor workers on a remote worker host (all hosts,
  a release, or specific workers/hosts), registering a NEW remote host and
  bringing its workers up in a given directory, or checking remote worker
  status. Триггеры RU: обнови воркеры на удалённом хосте, обнови все
  удалённые воркеры, релиз воркеров, добавь новый удалённый хост, запусти
  воркеры на новом сервере, проверь статус удалённых воркеров, пересобери
  воркеры на uBook, зайди по ssh на uBook. EN: update remote workers,
  release workers, add a new remote host, start workers on a new server,
  check remote worker status, redeploy uBook, ssh to uBook.
---

# Remote convertor worker hosts

A remote worker host runs a subset of convertor's six workers as pure
WS-clients of the `ws-gateway` on the main server — no gateway, no
php/mariadb/keydb/nginx there. It reaches the main server only over public
`wss://`/`https://` (see `remote-worker-connectivity-triage` for the network
hop and its failure modes). Image topology + build/push lives in skill
`image-build-deploy`; the generic setup walkthrough is `docs/workers-remote-
deploy.md`. **This skill is the procedures** (update / onboard / status) plus
the safety boundary; concrete per-host facts are in `hosts.md`.

> Verify facts here against the source before relying on them — this
> describes live hosts and a live Makefile, both drift. Found drift → fix
> this skill (or `hosts.md`) in the same change and report to the team-lead.
> Sources: root `Makefile` (`pull`, `ps`, `docker-check`), `workers/Makefile`
> (`workers-recreate`, `worker-logs`, `build-workers`, `build-ai-cpu`,
> `gateway-logs`), `.env.local_worker_example`, `docker-compose.yml`.

## Registry

One entry per host, its facts and current inventory, in **`hosts.md`**. Today
that's `uBook` only. Adding a host → **`onboarding.md`**.

## Hard safety boundary — read before touching a remote host

**A remote host is shared with other tenants/projects that this skill must
never touch.** On uBook alone there are ~14 unrelated project directories
next to `convertor/` (including a stale legacy checkout literally named
`xakki-convertor/` — do not confuse it with the real `convertor/` repo). The
container inventory in `hosts.md` is informational and goes stale (e.g. the
`xakkipro-*` containers it once listed are not running as of 2026-08-24) —
**never rely on "it's not in the list" as permission.** The rule is
structural, not enumerative:

- **Always `cd` into that host's registered base directory first**
  (`hosts.md`), then drive only that repo's `make` targets. Never run a
  bare `docker` command against a container/volume/network you found by
  browsing — if it isn't produced by `make ps`/`make workers-recreate` in
  that directory, it is out of bounds.
- **Never** host-wide docker operations from a remote host: no `docker
  system prune`, no `docker stop $(docker ps -q)`, no `docker compose down`
  run from any directory other than the registered base dir.
- **Never** touch files outside the base directory — no editing another
  project's `.env`, no `rm`/`mv` outside it, no reading its secrets.
- Every command in this skill's happy-paths already respects this — it's the
  deviations (ad-hoc debugging, "just checking" a neighbor container) that
  break it.

**Red flags — stop if you catch yourself doing any of these:**
- Running `docker` directly instead of a `make` target "just this once."
- Operating from `$HOME` or any directory other than the host's base dir.
- About to `docker rm`/`stop`/`prune` something not listed by `make ps` in
  the base dir.
- Reasoning "this container looks abandoned, I'll clean it up" — not this
  skill's job on any host.

**⚠️ A local `timeout`/Ctrl-C on `ssh <alias> '<cmd>'` does NOT stop the
remote command.** It only kills the local ssh client; by default nothing
propagates to the remote process, which keeps running (and, for `docker
compose up`, keeps pulling/creating containers) after your wrapper reports
"Terminated"/exit 124. Tested the hard way registering saVpn: a timed-out
`make workers-recreate` was believed stopped, a second one was started, and
for several minutes two concurrent `docker compose up` pulls of
multi-GB excluded-worker images ran at once on a 892 MB host next to a live
VPN (see `hosts.md`, host `saVpn`, for the full incident). **If you need to
actually stop remote work: `ssh <alias> 'ps aux | grep ...'` and kill it on
the host — never assume a local timeout stopped anything remote.**

## Update workers (all hosts / a release / specific workers or hosts)

All docker work goes through Makefile targets — never raw `docker compose`
(project rule). From the host's base directory:

```bash
ssh <alias> 'cd <base-dir> && git status --porcelain'   # 1. load-bearing, see below
ssh <alias> 'cd <base-dir> && git pull'                 # 2. ff expected
ssh <alias> 'cd <base-dir> && make pull'                # 3. never skip — see below
ssh <alias> 'cd <base-dir> && make workers-recreate'    # 4. --no-deps, seconds
```

- **Step 1 is load-bearing.** Uncommitted *tracked* changes on a remote
  host → STOP and escalate; never `stash`/`checkout`/`reset` there
  (rollbacks need explicit user approval regardless of host). An untracked
  `shared-files/` dir is known cruft (the project has no shared volume) —
  safe to ignore, worth flagging for deletion.
- **Never skip step 3.** `workers-recreate` recreates containers from
  whatever image is *already local* — after a `git pull` that only bumped
  compose/Makefile (no new image release) `make pull` is a no-op, but
  skipping it risks running stale code with no in-band signal telling you
  apart (`worker_capabilities` looks healthy either way). The decisive check
  for "did a new image actually land" is `docker images` timestamps or the
  reported build version, not `git diff` — the image, not the checkout, is
  the unit of deploy on a remote host.
- **Releasing to "all hosts"** = repeat the 4 steps once per host in
  `hosts.md`. **No mechanism iterates hosts for you** — no host list, no
  fan-out target in the Makefile. Confirmed by reading root+workers
  Makefiles; do not invent one, just do it host by host.
- **Updating only specific workers is not possible through sanctioned
  tooling.** `make pull` is `docker compose pull` with no service filter
  (pulls all images), and `workers-recreate` hardcodes all six service
  names (`workers/Makefile`) — there is no `workers-recreate-<service>`
  target, even though the root Makefile already has the pattern-rule idiom
  for exactly this (`logs-%` → `make logs-php`). The underlying command
  *does* support one service — `docker compose up -d --no-deps
  <service>` — but running it by hand violates the project's Makefile-only
  docker rule, so in practice: **recreate all 6.** It's `--no-deps` and
  takes seconds, so this is cheap, not a real limitation day to day. Report
  the missing `workers-recreate-%` target as a gap if precision ever
  matters (e.g. a worker mid-job you don't want bounced).
- **⚠️ On a light-only host (a host that excludes some of the 5 non-AI
  workers via a host-local profile override — see "Light-only host pattern"
  in `hosts.md`, host `saVpn`), `workers-recreate` is UNSAFE, not just
  imprecise.** It names all 6 services explicitly. This is Compose's
  documented design, not a version quirk to expect fixed later: explicitly
  naming a service on the command line auto-enables that service's profile
  regardless of `COMPOSE_PROFILES` — confirmed live on saVpn, running it
  actually started pulling the excluded workers' multi-GB images instead of
  erroring or skipping them. On such a host use **`make up`** for both first bring-up
  and updates (`git pull && make pull && make up`) — it never names services
  explicitly, so the profile gate holds. This is specific to hosts using the
  light-only override pattern; on a full-worker-set host like uBook
  `workers-recreate` is fine as documented above.
- **`.env.local` pull-policy vars are per-host, not a fixed value** — see
  `hosts.md` for what each host actually needs (`WORKER_PULL_POLICY`,
  `AI_PULL_POLICY`, `IMAGE_TAG`; CPU vs GPU hosts differ). Template with the
  reasoning: `.env.local_worker_example`.
- **Local build is the fallback**, not the happy path — only when Harbor is
  unreachable or for one-off debugging: `make build-workers build-ai-cpu`.
- **git submodule** `docker/fluent-log` must be initialised
  (`git submodule update --init docker/fluent-log`) — remote `.env.local`
  adds `docker/fluent-log/docker-fluent.yml` to `COMPOSE_FILE`; without the
  submodule compose fails on the missing path.
- **`make up`/`make down` are safe on a worker host only once `.env.local`
  is generated from `.env.local_worker_example`** — that template puts the
  server stack behind compose profile `server` and metrics-exporter behind
  `monitoring`, neither activated on a worker host, so `up`/`down` touch
  only the workers + fluent-bit sidecar (+logrotate). With an OLD
  `.env.local` (no `COMPOSE_PROFILES`) `up` tries the full stack and dies on
  `network common declared as external, but could not be found` — that
  network exists only on the main server. **Verify, don't assume:**
  `ssh <alias> 'cd <base-dir> && make docker-check'` validates the compose
  config through the Makefile's own env layering; there is no target that
  *prints* the resolved service list, only one that validates it.
- **⚠ `docker compose config` run over a bare `ssh` LIES.** `docker compose`
  by itself auto-loads only `.env`; `.env.local` reaches it solely because
  the root Makefile does `include .env.local` + `export` before invoking
  `docker compose`. So `ssh <alias> 'docker compose config'` (no `make`)
  renders the tracked `.env` defaults, not what the running containers
  actually use. Always go through `make` (`make docker-check`), or read live
  values off the running container: `docker inspect <c> --format
  '{{.HostConfig.LogConfig.Config}}'`.
- **`WORKER_HOST`** (the `host` column workers report) is not a var you set —
  `docker-compose.yml` injects it into every worker as `WORKER_HOST:
  ${HOST_NAME}`, and root `Makefile` computes `HOST_NAME ?= $(shell
  hostname)`. `.env.local_worker_example` correctly has no `WORKER_HOST` line
  (only a commented `HOST_NAME=` override for when the host's own `hostname`
  output is uninformative, e.g. a generic VM name). Record what `hostname`
  actually returns for each host in `hosts.md` — that is the value that will
  show up in the DB.
- **Volume-orphan trap on `COMPOSE_PROJECT_NAME` rename:** if a host's
  `COMPOSE_PROJECT_NAME` is ever renamed, named volumes keep the OLD
  project's compose label and `make up` warns about orphans. Fix: stop+rm
  the affected container → `docker volume rm <vol>` (both, if AI) → `make
  workers-recreate` (recreates volumes with the correct label). Decision
  precedent: recreate + redownload, not migrate — AI's Whisper models are
  lazy-loaded (not pulled at container start), so a fresh volume just costs
  one re-warm (~17s load / ~25s with a smoke transcribe, ≈148 MB on disk,
  unauthenticated HF Hub is fine at this size). See CNV-44 in `hosts.md` for
  the uBook incident record.

## Check status across remote hosts

- **Per host, from its base dir:** `ssh <alias> 'cd <base-dir> && make ps'`
  (container up/healthy) and `make worker-logs` (root-Makefile pattern rule
  `logs-<service>` also works for one worker, e.g. `logs-worker-image`).
- **Don't run `make -C workers queue-status`/`dlq-inspect` on a remote
  host** — those read KeyDB directly, and a worker host has no KeyDB.
  Queue-level status is a main-server-only view.
- **The authoritative check is on the MAIN SERVER, not the remote host** —
  see next section; `docker ps healthy` on the remote side is necessary but
  not sufficient (it doesn't prove the gateway sees the worker).
- **No mechanism aggregates status across hosts** — same gap as the update
  fan-out above: check `hosts.md`'s host list, repeat per host.

## Verify from the MAIN server, not from the remote host

The proof of a successful deploy/registration lives in the **main-server**
DB — `worker_capabilities` is written by the gateway on saFin, not by the
remote host. From `/home/xakki/convertor` on the main server:

- Query `worker_capabilities` (via `make console` / a Doctrine query — no
  dedicated raw-SQL target exists) and confirm the host's rows now show
  `host = <hostname>`, non-null `metrics` (cpu/mem/load), fresh `last_seen`.
  Rows appear **~60-90 s after recreate** — query too early and you'll see
  only the previous `disconnected` rows. (Empirical range; consistent with
  the gateway's 30 s liveness-push tick — `workers/gateway/liveness.py:
  liveness_push_interval_s` — plus reconnect/registration delay, not a
  documented single constant.)
- A healthy-looking row is **not** proof of fresh code — it only proves the
  container registered. `host`/`metrics` non-null was a registry-08 fix
  (pre-2026-07-23 images report empty `host` + zero metrics); any image
  built after that fix reports them fine regardless of how stale the image
  itself is. Use image build timestamps (`docker images`), not this row, to
  confirm code freshness (see the update section above).
- Old `instance_id` rows (`<container-id>:...`, `host=NULL`) are not
  instantaneous — they persist until garbage-collected, either by the
  hourly scheduled job (`app-symfony/src/Schedule.php`:
  `WorkerCapabilityGcMessage` every 1h) once older than
  `WORKER_CAPABILITY_GC_TTL_HOURS` (default `168` = 7 days,
  `app-symfony/config/services.yaml`), or immediately via `make -C
  app-symfony worker-capability-gc [TTL_HOURS=<n>]`. Until GC runs, stale
  rows linger — filter on `host=<name>`, don't read the full table.
- `make -C workers gateway-logs | grep -i "no capability row"` must be
  EMPTY. Wait past the gateway's ~60s liveness-snapshot warmup first.
- `grep -i ready` in the same gateway logs — a `ready` frame per worker type
  with the expected `workerId` (`<COMPOSE_PROJECT_NAME>-worker-*`) confirms
  the handshake.

## Register a new remote host

Facts to capture, and the bring-up procedure with a specified base
directory — **`onboarding.md`**. Summary: record the host in `hosts.md`
using the blank template there, then follow `onboarding.md` to bring workers
up in the chosen directory and confirm via the verify-from-main-server
section above.

**Onboarding a resource-constrained host that can't run all 5 non-AI
workers?** There is no `COMPOSE_PROFILES` mechanism for that — see "Light-
only host pattern" in `hosts.md` (host `saVpn`) for the tested workaround
(an untracked host-local compose override gating the excluded workers behind
a dummy profile) and the critical `workers-recreate` footgun that comes with
it.

## See also
- `hosts.md` — the host registry (facts + inventory), one section per host.
- `onboarding.md` — register-a-new-host procedure.
- `image-build-deploy` — image topology, main-server build/push, Harbor.
- `remote-worker-connectivity-triage` — the saNl SNI hop and network-fault
  diagnosis (does not duplicate host inventory).
- `docs/workers-remote-deploy.md` — full generic remote-host walkthrough
  (preflight connectivity checks, fluent-bit, first-time provisioning).
- `docs/worker-ai-deploy.md` — AI worker two-layer build detail.
