---
name: remote-worker-connectivity-triage
description: >-
  How to tell a genuinely stalled convertor queue from a misread instrument,
  and how to tell a client-side network fault (packet loss / TLS-handshake
  blackhole on the remote-worker path) from a server or application bug.
  Covers `make queue-status` XLEN vs real backlog (`convertor_stream_group_pending`),
  the confirmed remote-worker transport hop through saNl's nginx SNI-passthrough
  (verified against saNl's own domain map — see body), reading
  saNl's `stream-sni.log` for a blackholed session, and the repeated-probe
  methodology needed to prove an episodic network fault instead of asserting it
  from one sample. Триггеры RU: «очередь зависла», «воркер не отвечает»,
  «джоба не завершается», «XLEN не двигается», «worker connectivity», «TLS
  handshake timeout», «SNI blackhole», «пакеты теряются», «uBook не
  подключается», «ConnectTimeout в httpx»; EN: queue looks stuck, worker
  connectivity, ConnectTimeout, TLS handshake stall, SNI passthrough blackhole,
  packet loss diagnosis, is this network or app, queue backlog vs XLEN.
---

# Remote worker connectivity triage (convertor)

Diagnosis playbook for "a remote worker job never finished" / "the queue looks
stalled" reports. Two traps drive most false leads: (1) reading a lifetime
counter as a live backlog, and (2) blaming the app/server for what is actually
an episodic client-side network fault several hops away. Both were resolved
end-to-end on 2026-08-04; see cards `CNV-63` (todo) and `CNV-65` (todo) for the
follow-up fixes tracked from this diagnosis — don't restate their AC here,
just link.

## Step 0 — rule out a misread instrument FIRST

`make queue-status` (`workers/Makefile:262-265`) prints **XLEN** per `conv.*`
KeyDB stream. Streams are never trimmed, so XLEN is a **lifetime total**, not
a backlog — "46 in conv.document, static for 3 minutes" with no new uploads is
the *healthy* reading, not a stall signal.

- Real backlog = Prometheus `convertor_stream_group_pending` (see
  `workers/metrics_exporter/exporter.py:57`), plus staleness via
  `convertor_stream_pending_max_idle_ms` (`exporter.py:72`). Zero pending =
  nothing is stuck, regardless of XLEN.
- Before assuming a job "never finished," check the `Conversion` row's status
  and `processing_ms` — it may have completed after retries. Real precedent:
  conversion 66 — 31 ms of actual work, 6 min wall-clock, 4 dispatches.
- Card `CNV-63` tracks fixing this misleading output — reference it, don't
  duplicate its content.

Only after this comes back "yes, something really is stuck" move to network
triage below.

## Transport topology (why the fault is invisible on the main server)

Remote workers do **not** reach the Symfony API directly. Path:

```
remote worker host (e.g. uBook) → saNl 95.211.47.43
  (nginx `stream` block, SNI passthrough) → saFin:448
  → convertor nginx → Symfony API
```

The saNl hop is easy to miss because it's outside this repo and outside the
convertor project's own infra — an absent ACCESS-log entry on the main server
does **not** mean the packet never left the client; check the saNl SNI/stream
hop before concluding "nothing arrived."

> **Confirmed 2026-08-04** against `nginx -T` run live on saNl: the
> `stream {}` block's `map $ssl_preread_server_name $upstream_pool` contains
> `xakki.pro            safin_448;` and `~*\.xakki\.pro$      safin_448;`,
> with `upstream safin_448 { server 95.217.118.82:448; }`. Only
> `ip.xakki.pro`, `~*\.ip\.xakki\.pro$` and `exrate.xakki.pro` are terminated
> locally on saNl — `convertor.xakki.pro` (`.env:40,117`) matches the
> `~*\.xakki\.pro$` wildcard and is relayed to saFin:448 (`proxy_protocol on`,
> `proxy_connect_timeout 10s`, `proxy_timeout 5m`). The chain above is settled
> for convertor traffic; `/home/.claude/DEVOPS/saNl.md` still claims
> `xakki.pro` is locally terminated and omits it from the map it documents —
> that doc has drifted from the live config and needs a fix in its own repo.

## Client-side network fault vs server/app fault

- **Worker-side symptom**: `httpx.ConnectTimeout` raised inside
  `httpcore .../connection.py → stream.start_tls`. That is a **TLS-handshake**
  stall — TCP already connected. Do not read it as "cannot connect at all."
  This is expected at exactly the saNl relay hop above: `ssl_preread`
  terminates nothing itself, so a blackhole there always shows up as a
  stuck TLS handshake, never a refused/reset TCP connect.
- **Server-side proof** lives on saNl in `/var/log/nginx/stream-sni.log`
  (root-only host, not in this repo). Signature of a blackholed client:
  `sni="" upstream=- sent=0 recv=0 dur=30.000` — connection accepted, zero
  bytes ever received from the client, closed by `preread_timeout`.
- **Time-attribution rule**: the log line is written at CLOSE time, so the
  attempt actually started at `log_time - dur`. Matching on start-semantics
  gives false negatives — in the real case, 3/3 dispatch times matched saNl
  log entries only after subtracting `dur`.
- **Scale by egress IP before blaming the network**: compare the blackhole
  rate for the suspect client against everyone else on the same day/hop. A
  ~0.01% baseline vs a client at several-percent is what actually
  distinguishes "flaky client" from "flaky server." A single successful (or
  failed) probe proves nothing either way.
- Live WebSocket traffic and successful log shipping in the same seconds do
  **not** rule out packet loss — episodic loss can hit SYN/first-data packets
  while already-ESTABLISHED connections keep flowing fine.

Full probe methodology (sampling procedure, dual-location probing, the
ruled-out checklist for DNS/MTU/conntrack/front-end-saturation/IP-ban) is in
[reference.md](reference.md) — open it when actually running a new probe
campaign, not for a quick read of the theory above.

## Client-side hardening gap (network-independent, found on the way)

`workers/common/ws_client.py`'s `_download_input`/`register` share a default
5.0s `httpx` timeout and neither retries, unlike `_upload_large`'s longer
timeout — but **a longer timeout alone would not fix it**, recovery needs a
*new* connection, not a longer wait on the same one. Full detail (exact line
numbers, gateway idle-reclaim interaction) and the Graylog/root access
limits hit while investigating are in [reference.md](reference.md). Card
`CNV-65` tracks the fix — reference it, don't restate its AC.

## Self-currency

This skill cites: `workers/Makefile:262-265` (queue-status target),
`workers/metrics_exporter/exporter.py` (`convertor_stream_group_pending` /
`convertor_stream_pending_max_idle_ms` metric names), `workers/common/ws_client.py`
(`_get_http`, `_download_input`, `_upload_large`, `_register`), and
`workers/gateway/config.py` (`reclaim_idle_ms_for` and its per-type constants).
Line numbers drift as the code changes — **before relying on a fact from
here, verify it against the cited source; if it has drifted, fix this skill in
the same change and report the drift to the team-lead.**

## See also
- `ubook-remote-workers` — uBook-specific host facts (ssh, compose project,
  containers); this skill only adds the saNl hop and the fault-diagnosis
  method, it doesn't duplicate uBook's inventory.
- `image-build-deploy` — image topology, unrelated to network triage.
- Cards `.claude/kanban/todo/CNV-63-queue-status-misleading-xlen.md`,
  `.claude/kanban/todo/CNV-65-worker-download-input-no-fast-retry.md`.
