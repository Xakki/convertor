# Triage: "queues stalled" on convertor dev stand — 2026-08-04

## Verdict: NOT a stall. Conversion 66 completed 09:30:15 UTC (6m10s after enqueue).

DB: `conversions` id=66 → status=completed, created_at 09:24:05, updated_at 09:30:15.
All 56 rows in `conversions` are `completed`; zero pending/processing/failed.

## What actually happened (UTC, 2026-08-04)
- 09:24:05 conversion 66 created; 09:24:06 XADD conv.image (jobId 1785835446004-0), dispatched same second.
- 09:24:11 / 09:26:16 / 09:28:17 — remote worker `convertor-remote-ubook-worker-image` fails:
  `httpx.ConnectTimeout` in `ws_client.py:1176 _download_input` → TLS `start_tls` to
  `GET https://convertor.xakki.pro/api/v1/worker/jobs/{id}/input`. Sends `fail permanent=false`.
- Gateway: "fail retryable — leaving pending for idle-reclaim retry", then XAUTOCLAIM every ~2min
  (minIdleMs 120000) → "idle entry reclaimed → handoff" → redispatch. 4 dispatches total.
- 09:30:15 4th attempt: input GET 200 OK → "image converted" jpg→png → result sent inline (1264 B)
  → gateway POST /internal/worker/result 200 OK → XACK. Job terminal-success.

Root cause = transient egress/TLS reachability failure from remote host uBook to the main
server's public HTTPS, lasting ~09:24–09:30. Self-healed via the gateway's idle-reclaim retry.
Frequency: only 3 ConnectTimeout events in 48h on uBook workers — all three are job 66. Not chronic.

## Recent commits: NOT implicated
`1d12da5` + `d8b6f82` touch only Twig templates, `ConversionController`/`ConversionLogController`
(read endpoints `/api/v1/admin/conversions/{id}/file`, `/api/v1/convert/{id}/preview`), tests,
translations, kanban. No `/api/v1/worker/*` or `/api/v1/internal/worker/*` route, no worker/gateway/
Python code. The failure was a TCP/TLS connect timeout before any HTTP response — backend code
cannot produce that.

## Premise corrections for the team-lead
- "Uploaded ~1.5h ago": DB says created 09:24:05, i.e. ~17 min before triage (now 09:41). Estimate off.
- "Multiple streams affected / host-wide": unproven. `make queue-status` prints XLEN, which counts
  UNTRIMMED (incl. already-XACKed) entries — it is not a backlog gauge. Static XLEN across 3 min is
  expected when no new jobs arrive. `conv.dead: 1` / `conv.result.dead: 11` are historical DLQ.

## Open questions / cards
1. All 4 attempts went to the REMOTE worker while a healthy on-server `xakki-convertor-worker-image`
   was connected — dispatch did not fail over to the local worker after a retryable failure.
2. `make queue-status` shows XLEN only; no XPENDING/consumer-group visibility → cannot answer
   "is there a real backlog?" without ad-hoc redis-cli.
3. UI perceived the job as never progressing while the backend completed — verify job-status polling
   (`_converter_job_status.html.twig` / `_converter_app_script.html.twig` were rewritten in 1d12da5).
4. OBSERVABILITY DEFECT: worker sends `"fail sent" {"permanent": false, "error": "worker error: "}`
   - the error string is EMPTY (`str(httpx.ConnectTimeout())` == ""). Gateway therefore logged only
   "fail retryable" with no reason and nothing reached the DB; diagnosing a one-line cause required
   ssh to another host. Propagate the exception type/repr into the fail frame.
5. On-server worker-image logged `AttributeError: 'ClientConnection' object has no attribute
   'close_code'` on 08-03 03:13 (ws close path, websockets version) — cosmetic but latent.

## Scratch artifacts (need user consent to delete)
/tmp/backup_gw.log, /tmp/backup_wimg.log, this file.
