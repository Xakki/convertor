# Remote worker host registry

One section per host. Keep this current — it is a live-host fact sheet, not
history. Found drift → fix it in the same change and report to the
team-lead. The tenant/container inventory below is a snapshot and goes
stale fastest; treat it as informational, never as permission (see the hard
safety boundary in `SKILL.md` — the base-directory rule holds regardless of
what this file currently lists).

## Template for a new host

Copy this table when registering a host (see `onboarding.md`); fill every
row before considering the host registered.

| Fact | Value |
|---|---|
| ssh alias | |
| ssh HostName / Port (if tunnelled — note it explicitly) | |
| Base directory (repo clone) | |
| `COMPOSE_PROJECT_NAME` | |
| `hostname` output (→ `WORKER_HOST` the DB will show) | |
| Which of the 6 workers run here | |
| `COMPOSE_PROFILES` | |
| `WORKER_PULL_POLICY` / `AI_PULL_POLICY` / `IMAGE_TAG` | |
| CPU or GPU (`AI_VARIANT`/`AI_RUNTIME` if GPU) | |
| `EXT_FLUENT_PORT` (must be loopback, e.g. `127.0.0.1:24224`) | |
| Other tenants/projects on the same host (informational only) | |
| Verified date | |

---

## uBook

CPU-only host. Six workers + a project fluent-bit sidecar; no gateway, no
php/mariadb/keydb/nginx here (verified live 2026-08-24).

| Fact | Value |
|---|---|
| ssh alias | `uBook` (key auth, BatchMode works — it's a tunnel: `HostName 127.0.0.1`, `Port 22100`, `User xakki`, `IdentitiesOnly yes`). Always wrap `ssh uBook '<cmd>'` in `timeout`. |
| Base directory | `/home/xakki/www/xakki/convertor` — **not** the sibling `/home/xakki/www/xakki/xakki-convertor/` (a stale legacy checkout of unrelated age; confirmed present on disk 2026-08-24, do not touch or confuse with the real repo). |
| `COMPOSE_PROJECT_NAME` | `convertor-remote-ubook` |
| `hostname` output | `uBook` — confirmed live 2026-08-24 (`ssh uBook hostname`); matches `host=uBook` in `worker_capabilities`. |
| Workers running | All 6: `worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video`, `worker-image`, `worker-data`, `worker-ai` (cpu), plus `fluent-bit` + `logrotate` sidecars. |
| `COMPOSE_PROFILES` | `ai` (confirmed live in `.env.local` 2026-08-24) |
| `WORKER_PULL_POLICY` / `AI_PULL_POLICY` / `IMAGE_TAG` | `missing` / `always` / `latest` (confirmed live in `.env.local` 2026-08-24 — CPU host, so `always` is safe for `worker-ai` too: `worker-ai:latest-cpu` IS published to Harbor). |
| CPU/GPU | CPU only. |
| `EXT_FLUENT_PORT` | `127.0.0.1:24224` — **fixed and verified live 2026-08-25** (`ss -tlnp` + `ss -ulnp`: TCP and UDP both loopback-only). CNV-122 closed. Old `.env.local` backed up on uBook itself at `/tmp/backup/convertor/backup_env.local.20260825_012014`. **Exposure before the fix was smaller than the card claimed:** uBook sits behind NAT (private 192.168.10.0/24, no public IP); a proxied probe from saBots to its egress IP `95.211.47.43` got `connection refused` on both 22 and 24224 (the router forwards neither), so it was never internet-reachable — the real risk was any device on that LAN. ⚠ The 6 worker containers still carry the OLD `fluentd-address: 0.0.0.0:24224` in their `LogConfig` (not recreated). Harmless: on Linux `connect()` to `0.0.0.0` goes to `127.0.0.1` (verified live). It normalises for free on the next `workers-recreate` — do NOT recreate just for this, `AI_PULL_POLICY=always` would drag the `worker-ai:latest-cpu` image down as a side effect. **Delivery evidence is partial, do not overstate it:** a record demonstrably reached Graylog right after `fluent-recreate` (fluent-bit metrics 1 in / 1 out, 0 errors; the Graylog entry at `22:20:30Z` is the `logrotate` line). Worker-attributed delivery is NOT confirmed — a Graylog query for `container_name:convertor-remote-ubook*` returns nothing over 3 days, because idle workers emit no logs at all. Consequence worth knowing: on this host you cannot distinguish "log delivery broke" from "nothing to deliver" until a real job runs. Re-check after uBook next processes a conversion. |
| Docker | `29.7.1` (confirmed live 2026-08-24, `docker version --format '{{.Server.Version}}'`), BuildKit active by default. |

**Other tenants on this host (informational, confirmed 2026-08-24) — do NOT
touch:** a standalone `portainer` container (host-wide management, not a
convertor container), and ~13 unrelated project directories under
`/home/xakki/www/xakki/` (`xakki.pro`, `AiNewsChannel`,
`antigravity-claude-proxy`, `BotFerma`, `crewAI`, `ExRate`, `knowledge`,
`obsidian-notes`, `p2p-proxy`, `profmatrix`, the stale `xakki-convertor/`
noted above, etc.). The previous version of this fact sheet named
`xakkipro-php-1`/`xakkipro-mariadb-1`/`xakkipro-redis-1` as the tenant to
avoid — **those containers are NOT currently running** (checked `docker ps
-a`, 2026-08-24; the `xakki.pro` project directory still exists, so they
may come back). This is exactly why the boundary in `SKILL.md` is structural
("only the base dir, always") rather than a container name blocklist: a
blocklist goes stale, the directory boundary doesn't.

### Ops notes

**CNV-44 (2026-08-02) — recreate AI named volumes.** Named volumes
`worker-ai-models` / `worker-ai-data` had a stale compose label
`com.docker.compose.project=convertor-remote-xbook` (pre-rename; the project
was renamed `xbook`→`ubook`). Every `make up` warned about orphans. Fix:
stop+rm the AI container → `docker volume rm` both volumes → `make
workers-recreate` (compose recreates volumes with the correct label).
Decision was recreate+redownload, not migrate — see the general note in
`SKILL.md`. Whisper models are lazy (not pulled at container start); after
recreate the HF cache was empty, warming `WhisperModel("base", device="cpu",
compute_type="int8")` inside the running container redownloaded in ~17s
load / ~25s with a smoke transcribe, cache ≈148 MB on the volume
(unauthenticated HF Hub, fine at this size). Post-fix `make up` has no
orphan/`xbook` warnings; labels show `convertor-remote-ubook`.

## saVpn

**Light-only host — first use of the "light-only" pattern on this skill
(onboarded 2026-08-24).** 1 CPU core, 892 MB RAM, co-tenant with a LIVE
production VPN (`amnezia-xray`) + `cadvisor`/`nodeexporter`/
`app-portainer_agent-1`. Runs ONLY `worker-data` + `worker-image` — no
`worker-ai`, no `worker-ffmpeg-audio`/`worker-ffmpeg-video`, no
`worker-libreoffice` (CPU transcode / hundreds of MB RSS would starve the
VPN on a single shared core).

| Fact | Value |
|---|---|
| ssh alias | `saVpn` (key auth, `User root`, `HostName 151.245.217.133`, `Port 22022`). Always wrap in `timeout`. |
| Base directory | `/var/www/convertor` — did not exist before onboarding, created fresh. |
| `COMPOSE_PROJECT_NAME` | `convertor-remote-savpn` |
| `hostname` output | `saVpn` — confirmed live 2026-08-24; matches `host=saVpn` in `worker_capabilities`. |
| Workers running | ONLY `worker-data` + `worker-image`. **Excluded on purpose:** `worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video` (CPU-heavy transcode, would starve the 1-core VPN), `worker-ai` (no `ai` profile activated — hundreds of MB RSS, no budget). |
| `COMPOSE_PROFILES` | empty (`COMPOSE_PROFILES=`) — no `ai`/`server`/`monitoring`. The 3 excluded CPU workers are NOT profile-gated in the tracked `docker-compose.yml` (only `worker-ai` has `profiles: ["ai"]`) — see "Light-only host pattern" below for how they're excluded instead. |
| `WORKER_PULL_POLICY` / `AI_PULL_POLICY` / `IMAGE_TAG` | `missing` / n/a (no ai) / `latest` |
| CPU/GPU | CPU only. |
| Resource limits (measured, not the originally-suggested 0.25/0.25 split) | `worker-data`: **0.5 cpu / 128M**. `worker-image`: **0.25 cpu / 192M**. Total 0.75 cpu / 320M. worker-data's cpu was bumped from the suggested 0.25 → 0.5 after empirical testing: its healthcheck (`import pandas, yaml, lxml`) took **22.6s at 0.25 cpu** (over the compose file's 10s healthcheck timeout, container stuck "health: starting" indefinitely) and **7.3s at 0.5 cpu** (passes) — measured via `docker exec ... python3 -c "import time; ..."` on the live host. worker-image passed healthy at 0.25 unmodified. |
| `EXT_FLUENT_PORT` / fluent-bit | **Not deployed.** No memory budget left after the 320M worker allocation (budget was ~320M total for everything added) — `docker/fluent-log` submodule not initialised, `COMPOSE_FILE` does not include it. Consequence: saVpn's worker logs go to the default `json-file` driver only and do **not** reach Graylog, unlike uBook. |
| Non-root | Host-side: dedicated OS user `convertor` (uid/gid 1000) owns `/var/www/convertor`, in the `docker` group, runs all `make`/`git` commands (`su - convertor -c '...'`). Container-side: **no compose `user:` override needed** — both `docker/workers/data.Dockerfile` and `image.Dockerfile` already bake `USER app` (uid 1000) into the image; confirmed live via `docker exec ... id` on both containers → `uid=1000(app) gid=1000(app)`. Note `usermod -aG docker convertor` is effectively root-equivalent on this box (docker group can control the whole daemon) — accepted since Docker access is the whole point of the user. |
| Swap | **Present**: 10G swapfile (`/swapfile`), 268K used before onboarding, ~15-31M after — plenty of headroom. No action needed. |
| Verified date | 2026-08-24 |

**Other tenants on this host (informational, confirmed 2026-08-24) — do NOT
touch:** `amnezia-xray` (the production VPN — the whole reason for the
resource ceiling), `cadvisor`, `nodeexporter`, `app-portainer_agent-1`. All
four were still up with their original (pre-onboarding) uptimes after this
work completed.

### Light-only host pattern (new — read before onboarding another constrained host)

The skill had NO documented way to run a true subset of the 5 non-AI
workers. `COMPOSE_PROFILES` only gates `worker-ai` (`ai`) and the server
stack (`server`/`monitoring`) — `worker-libreoffice`/`worker-ffmpeg-audio`/
`worker-ffmpeg-video`/`worker-image`/`worker-data` carry **no profile at
all** in the tracked `docker-compose.yml`, so on any host that activates
none of those profiles, all 5 come up together. `.env.local_worker_example`
and `docs/workers-remote-deploy.md` both say this explicitly ("Остальные
воркеры профиля не имеют и поднимаются всегда"). `deploy/docker-compose.yml`
(the public gist-bootstrap path) already has the correct fix — one profile
per worker — but that's a separate standalone compose file, not the
Makefile-driven path this skill documents, and porting that matrix into the
tracked `docker-compose.yml` is a real repo change out of scope for this
onboarding (recommend it to the team-lead as a follow-up rather than editing
it ad hoc).

**Workaround used on saVpn — an UNTRACKED host-local compose override**
(`docker/host-savpn-light-workers.yml`, never `git add`-ed, lives only in
that host's own clone, referenced via `COMPOSE_FILE` in `.env.local`). It
adds `profiles: ["excluded-on-this-host"]` to the 3 excluded services (a
profile `COMPOSE_PROFILES` never activates) plus the tightened resource
limits above. Verified this actually works — with proper Makefile env
layering (`set -a; . ./.env; . ./.env.local; set +a; docker compose config
--services`), the resolved list is exactly `worker-data worker-image`,
nothing else.

**⚠️ This exclusion rests on a single unprotected file — protect it and know
the failure mode.** Because the override is untracked, a plain `git clean
-fd` in that clone deletes it silently, and the very next `make up` would
then bring up all 5 non-AI workers on this 892 MB host next to the live VPN
— no error, no warning. Mitigated by adding the path to
`/var/www/convertor/.git/info/exclude` (host-local, not a tracked file) so
`git clean -fd` skips it and it no longer shows as `??` in the skill's step-1
`git status --porcelain` check — done on saVpn 2026-08-24. **If this file is
ever lost on a light-only host, recreate it before the next `make up`/`make
pull`**, don't just re-run the happy path.

**⚠️ CRITICAL, TESTED FOOTGUN — `make workers-recreate` BREAKS THE
EXCLUSION.** `workers-recreate` hardcodes `docker compose up -d --no-deps
worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image
worker-data worker-ai` — it names all 6 services **explicitly**. This is
Compose's documented behavior, not a version quirk to expect fixed later:
explicitly naming a service on the command line auto-enables that service's
profile regardless of `COMPOSE_PROFILES`. Running `make workers-recreate` on
saVpn during this onboarding actually started pulling
`worker-libreoffice`/`worker-ffmpeg`/`worker-ai` images (multi-GB) and would
have started those containers on this 892 MB host, right next to the live
VPN — caught and killed mid-pull, no excluded container ever reached
"created" state, no damage done (verified `docker ps -a` after the kill —
only the 2 intended containers exist; VPN
uptime unaffected). This is the opposite of `docker compose config
--services` / plain `make up` (no explicit names), which correctly respect
the profile gate. **On a light-only host: NEVER run `make workers-recreate`.
Use `make up` for both first bring-up and updates** (`git pull && make pull
&& make up` — `up` detects the changed/pulled image digest and recreates
just that service; it never explicitly names services, so profile gating
holds). **Note: this update path is inferred from how `up` recreated
worker-data after its resource-limit change during this onboarding, not
separately exercised for a genuine image-digest change** — treat "confirmed"
as "confirmed for a config change," and re-verify the image-pull case the
first time it's actually needed.

**⚠️ How the near-miss above was caught, and a footgun in the catching
itself:** the first `make workers-recreate` invocation was run under a local
`timeout 25 ssh saVpn '...'`; it printed "Terminated" / exit 124 after 25s,
which reads as "the command stopped." **It did not** — killing the local ssh
client does not send anything to the remote process by default, and
`workers-recreate`'s `docker compose up` kept running on saVpn, still
pulling images, for several more minutes after the local wrapper "timed
out." Believing the false stop signal, a second `make workers-recreate` was
started in the background — resulting in two concurrent pulls of the
excluded workers' images at once before both were found and killed via
`ps`/`kill -9` on the host itself. **Lesson for every future `timeout ssh
<alias> '<cmd>'` in this skill: a local timeout/Ctrl-C only kills the local
ssh client. To actually stop remote work, `ssh <alias> 'ps aux | grep ...'`
and kill it there — never assume a timed-out local wrapper stopped anything
on the remote host.**

**Watch item, not yet a problem:** worker-data's 128M limit is the thinner
margin of the two — idle RSS measured at 28 MiB, but its healthcheck alone
(`import pandas, yaml, lxml`) needs 7.3s of its 0.5 cpu quota, and a real
`pandas` DataFrame during an actual data-conversion job shares that same
128M cgroup. If worker-data starts OOM-killing mid-job (would look like a
silently failed/retried task, not an obvious crash), the lever is
rebalancing within the existing 320M total (e.g. 160M/160M) in
`docker/host-savpn-light-workers.yml`, not raising the total budget.

## Other hosts

**None besides uBook and saVpn currently registered.** A GPU host was
discussed in frozen card
`.claude/kanban/freeze/CNV-7-cuda-worker-ai-rebuild-gpu-host.md` but was
never actually onboarded — no ssh alias exists for it, no confirmed repo
clone, and the card's own procedure is a standalone `docker run` workflow
rather than the Makefile-clone deploy this skill documents ("нужен доступ к
хосту" — access not available). Treat it as aspirational, not a registry
entry, until someone actually runs the onboarding procedure below against
it.
