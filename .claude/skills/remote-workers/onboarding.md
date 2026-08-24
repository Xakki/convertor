# Registering a new remote worker host

Rare path — most tasks are covered by `SKILL.md`'s update/status
procedures. Use this when actually bringing up workers on a host that isn't
in `hosts.md` yet.

**Full generic detail (preflight connectivity checks, first-time
provisioning, troubleshooting table) lives in `docs/workers-remote-
deploy.md` — this file is the short, actionable checklist; open the doc for
anything not covered here.**

## 1. Prerequisites on the new host

- Docker 24+.
- A clone of the `convertor` repo, in a directory you choose deliberately —
  this becomes the host's **base directory** (record it in `hosts.md`).
  Don't reuse or nest it inside another project's directory.
- Initialise the fluent-log submodule:
  ```bash
  git submodule update --init docker/fluent-log
  ```
  Without it, compose fails on the missing `docker/fluent-log/docker-
  fluent.yml` path once `.env.local` references it (next step).
- Outbound network reachability to the main server's public `wss://`
  gateway and to Graylog — no inbound port needs opening (all connections
  are outbound: WS-client → gateway, fluentd-driver → local sidecar →
  Graylog). Run the three preflight `curl` checks in `docs/workers-remote-
  deploy.md` ("Preflight: проверить доступность транспорта ДО деплоя")
  before starting anything — a failure there means an infinite reconnect
  loop with no useful diagnostic once workers start.

## 2. `.env.local` from the worker template

```bash
cp .env.local_worker_example .env.local
```

Not `.env.local_example` — that one is for the main server. Fill in, at
minimum:

| Var | What to set | Why |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | unique, **not** `xakki-convertor` and not equal to any existing host's | It derives each worker's hostname/consumer-name for KeyDB `XREADGROUP`; a collision with another host makes the gateway unable to tell the two apart. |
| `WORKER_API_TOKEN` | the real token (matches the main server) | No default — empty blocks worker start on purpose (avoids a reconnect-storm against an unauthorized gateway). |
| `COMPOSE_PROFILES` | `ai` for a host that runs `worker-ai`, empty for a CPU-only host without it | Do NOT add `server`/`monitoring` — that pulls in php/mariadb/nginx/ws-gateway/metrics-exporter, none of which belong on a worker host. |
| `WORKER_PULL_POLICY` | `missing` (template default) | `make pull`/`make up` prefers a Harbor pull; falls back to `build:` only if the tag genuinely doesn't exist yet. |
| `AI_PULL_POLICY` | `always` on a CPU host, `build` on a GPU host | `worker-ai:latest-cpu` is published to Harbor (`always` safe); `worker-ai:latest-cuda` is NOT published — a GPU host must build locally, and `missing` only patches `make up`'s fallback, not `make pull` (that still tries the missing tag and fails). |
| `IMAGE_TAG` | `latest`, or a pinned release tag | Pin only if this host needs to lag behind `latest` deliberately. |
| `COMPOSE_FILE` | keep the template's value (adds `docker/fluent-log/docker-fluent.yml` + `docker/limits.yml`) | The project fluent-bit sidecar and resource limits both come from this. |
| `EXT_FLUENT_PORT` | `127.0.0.1:24224` — **loopback, never `0.0.0.0`** | The sidecar must not accept connections from outside the host. Verify after bring-up: `ssh <alias> 'ss -tlnp | grep 24224'` should show `127.0.0.1:24224`, not `0.0.0.0:24224`. (uBook currently fails this check — see `hosts.md` — don't repeat it on a new host.) |
| `HOST_NAME` (optional override, commented out by default) | only set if `hostname` on this box is uninformative (e.g. a generic VM name) | Otherwise leave it — root `Makefile` computes it from `hostname` and `docker-compose.yml` injects it into every worker as `WORKER_HOST`, which is what shows up as the `host` column on the main server. Record what `hostname` actually returns in `hosts.md` either way. |
| `AI_VARIANT` / `AI_RUNTIME` | `cuda`/`nvidia` only on a GPU host | Defaults (`cpu`/`runc`) are correct for a CPU host — leave unset. |

**Onboarding a resource-constrained host that must run only SOME of the 5
non-AI workers** (not the full set, not just "skip AI")? `COMPOSE_PROFILES`
cannot do this — `worker-libreoffice`/`worker-ffmpeg-audio`/
`worker-ffmpeg-video`/`worker-image`/`worker-data` carry no profile at all in
the tracked `docker-compose.yml`, so any host that isn't excluding all 5
gets all 5 together. See "Light-only host pattern" in `hosts.md` (host
`saVpn`) for the tested workaround (untracked host-local `COMPOSE_FILE`
override gating the excluded services behind a dummy profile) **and its
critical caveat: `make workers-recreate` bypasses that gate and will start
the excluded workers anyway — use `make up` for everything on such a host.**
Also budget the resource limits for the workers you DO run — set them in
that same override file, not by editing tracked `docker/limits.yml`.

## 3. Bring workers up

```bash
ssh <alias> 'cd <base-dir> && make docker-check'          # config validates through the Makefile's env layering
ssh <alias> 'cd <base-dir> && make pull'                  # pull images from Harbor (or fall back to build — see SKILL.md)
ssh <alias> 'cd <base-dir> && make up'                    # starts exactly the workers + fluent-bit + logrotate on this profile
```

If Harbor is unreachable or this is a from-scratch host with nothing
published yet, build locally instead of `make pull`:
```bash
ssh <alias> 'cd <base-dir> && make build-workers build-ai-cpu'   # or build-ai-cuda on a GPU host
ssh <alias> 'cd <base-dir> && make up'
```

## 4. Confirm and register

- Follow **"Verify from the MAIN server, not from the remote host"** in
  `SKILL.md` — `worker_capabilities` rows for this host's `host=<hostname>`
  value, non-null metrics, fresh `last_seen`, `ready` frames in
  `gateway-logs`.
- Once confirmed, fill in this host's row in `hosts.md` using the template
  at the top of that file — every fact, not just the ones that were
  convenient to check. Include the `EXT_FLUENT_PORT` loopback verification
  from step 2 explicitly (record pass/fail, not just "set to 127.0.0.1").
