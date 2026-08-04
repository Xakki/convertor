# Reference — probe methodology and ruled-out checklist

Line numbers/paths cited below drift as the code changes — verify against
the cited source before relying on them; if drifted, fix it in the same
change and report the drift to the team-lead (same standing instruction as
the parent SKILL.md's Self-currency section).

Detail for `remote-worker-connectivity-triage/SKILL.md`. Open this when
actually running a new probe campaign to prove/disprove an episodic network
fault, not for a first read of the theory.

## Probe methodology that produced the answer (2026-08-04 incident)

- Sample repeatedly — roughly 60-120 iterations over 5-10 minutes — capturing
  per attempt: HTTP status code, `time_namelookup`, `time_connect`,
  `time_appconnect`, `time_total`, and the curl exit code. Report a **failure
  rate and distribution**, not a single data point — one successful probe
  proves nothing about an episodic fault, and neither does one failure.
- Probe from **both** the remote host itself and from inside the worker
  container — DNS resolution and egress NAT paths can differ between the two.
- Add a **control probe** from the main server over the same public path
  (same domain/port), to distinguish "this specific client's egress is bad"
  from "the public endpoint itself is flaky for everyone."
- Remember: episodic loss can hit SYN and first-data packets while an
  already-ESTABLISHED connection (e.g. a live WebSocket, or log shipping)
  keeps flowing in the very same seconds. A healthy-looking parallel
  connection does not rule out packet loss on a *new* connection attempt.
- Once you have per-attempt data, compute the failure rate **per egress IP**
  and compare it against every other client hitting the same hop on the same
  day. In the real case: the suspect remote host's egress IP had 56/659
  sessions blackholed (8.5%) on saNl's SNI hop, versus roughly 4/~50k (about
  0.008%) for every other client through the same hop on the same day.
  Without that comparison ratio, a handful of failures cannot be told apart
  from ordinary background noise.

## Ruled-out checklist (reusable — how each cause was excluded)

When triaging a similar episodic-connectivity report, work through these in
order; each entry below is how it was excluded last time, not a hardcoded
verdict for future incidents — verify each one fresh:

1. **DNS** — no AAAA record present (so no IPv6/IPv4 happy-eyeballs races),
   `A` records consistent across repeated lookups, `time_namelookup` in the
   probe data stayed normal (no resolver-side stalls).
2. **MTU / PMTU blackhole** — large request/response bodies passed through
   repeatedly without truncation; the learned path MTU was stable. A true PMTU
   blackhole would show up as large-payload-only failures, which this was not
   (small handshake-phase failures too).
3. **conntrack table exhaustion** — compared `nf_conntrack_count` against
   `nf_conntrack_max` on both the client host and the saNl hop; neither was
   anywhere near its ceiling.
4. **Front-end saturation** — checked nginx `ListenOverflows`/`ListenDrops`
   counters and general load on saNl/saFin; no correlation with the failure
   windows.
5. **Server-side IP ban** (fail2ban/ipset on saNl or saFin) — excluded by
   mechanism, not just by absence from a ban list: a ban would also have
   killed the same client's *other* live flows through the same hop at the
   same time, which did not happen (the WebSocket and log shipping kept
   working while new-connection attempts blackholed).

If a future incident rules any of these back in, update this checklist in
the same change rather than leaving it stale.

## Client-side hardening gap — full detail

`workers/common/ws_client.py`:
- `_download_input` (`:1167-1180`) and `_register` (`:778-804`,
  `timeout=5.0` at `:804`) both use the shared `httpx.AsyncClient()` built by
  `_get_http` (`:1158-1161`) with httpx's **default 5.0s timeout**, and
  neither retries. `_upload_large` (`:1182`) already sets a longer
  `httpx.Timeout(30.0, read=300.0)`.
- **A longer timeout alone would not have fixed the real incident** —
  stalled sessions sat 30s receiving nothing; recovery only came from
  opening a *new* connection. Any retry fix must open a fresh
  connection/TLS handshake, not just wait longer on the same socket.
- Gateway idle-reclaim is per worker type (`workers/gateway/config.py:95-104`,
  e.g. `reclaim_idle_ms_image = 120_000`), so one lost attempt with no fast
  retry costs a full reclaim cycle before the job is retried elsewhere.
- Card `CNV-65` tracks the fix — reference it, don't restate its AC.

## Access limitations hit during this investigation

- Graylog MCP (`mcp__graylog__*`) surfaces only `source`+`message`;
  structured fields (`context_error`, `context_exception`) are unreadable
  through it and the shipped token is search-restricted (403 on some
  queries). Read the exact worker exception text in the Graylog **web UI**
  instead (e.g. filter by `context_jobId:<id>`).
- Checks needing root on saNl (`iptables`/`ipset`, reading
  `stream-sni.log` if permissions are tight) must be handed to the user as
  a ready-to-run command, not attempted directly (see global root/sudo
  rule).
