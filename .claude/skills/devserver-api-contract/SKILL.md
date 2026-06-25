---
name: devserver-api-contract
description: Shared HTTP/WS API contract between the AI-worker dev-server backend (FastAPI) and its web UI. Use when implementing or changing workers/ai/devserver — routes, JSON shapes, WS protocol, settings model, pull-stats. Both backend-dev and frontend-dev MUST follow this contract verbatim.
---

# AI-worker dev-server — API contract (backend ↔ web UI)

Single source of truth for the JSON/WS shapes the FastAPI dev-server exposes and
the web UI consumes. Backend implements exactly these; frontend calls exactly
these. Any change here must be agreed via the teamlead, not invented per-side.

Entry: `python -m workers.ai devserver` **and** `python -m workers.ai --devserver`
(accept both — the card AC names `--devserver` literally). App binds
`127.0.0.1:8765` by default
(`DEVSERVER_HOST`/`DEVSERVER_PORT`). Optional bearer (`DEVSERVER_TOKEN`): if set,
every `/api/*` and `/ws/*` request must send `Authorization: Bearer <token>`
(WS via `?token=` query param too). Default = no token (localhost only).

Static UI served at `/` from `workers/ai/devserver/static/` (SPA, 4 tabs).
All API under `/api/`, WS under `/ws/`. JSON everywhere except file upload
(multipart) and result download (binary stream).

## Architecture notes (binding decisions)

- **Pull-loop runs IN the dev-server.** The dev-server owns a controllable
  background pull task (`PullRunner`) wrapping the worker poll loop. It starts
  when effective `PULL_ENABLED=true`, stops when false. Toggling the setting at
  runtime starts/stops this task — no process restart. This is how Stats sees
  live data and Settings toggles processing. (Prod `worker` mode is unchanged;
  the loop logic is refactored to be start/stop-able + instrumented, keeping
  existing `workers/tests/test_ai_worker.py` green.)
- **Stats are in-memory** in a shared `Stats` object the `PullRunner` updates and
  `routes_stats` reads. Not persisted; reset on restart.
- **Settings overrides persist to a volume JSON** (`DEVSERVER_CONFIG_PATH`,
  default `/data/devserver_settings.json`). MUST NOT default under `WORK_DIR`
  (that's the system tempdir — ephemeral, lost on restart, breaks the persist
  AC). docker-compose mounts a named volume at `/data` for the ai-worker
  service so the overlay survives restart; for local runs override the env.
  Effective config = env defaults (`load_config()`) merged with this JSON
  overlay. The dev-server reads the overlay at startup and after every
  successful `PUT /api/settings`.

## GET /api/methods
List of conversion methods derived from the worker's mode→formats mapping
(source of truth: `derive_mode` pairs in `workers/ai/convert.py`). No flags.
```json
{ "methods": [
  { "mode": "stt",       "label": "Speech → Text",      "sources": ["mp3","wav","ogg","m4a","opus","flac"], "targets": ["txt","srt","vtt"] },
  { "mode": "stt_stream","label": "Speech → Segments",  "sources": ["mp3","wav","ogg","m4a","opus","flac"], "targets": ["json"] },
  { "mode": "tts",       "label": "Text → Speech",      "sources": ["txt","md"], "targets": ["mp3","wav","ogg"] },
  { "mode": "embedding", "label": "Text → Embedding",   "sources": ["txt","md"], "targets": ["json"] },
  { "mode": "llm",       "label": "Text → Text (LLM)",  "sources": ["txt","md"], "targets": ["txt","md"] }
] }
```

## POST /api/run  (multipart/form-data)
Run one conversion on an uploaded file. Fields: `file` (the upload),
`sourceFormat` (str), `targetFormat` (str), optional `model` (str, LLM/embedding).
Backend writes upload to a temp path under WORK_DIR, builds a job dict
(`{_localInput, conversionId, sourceFormat, targetFormat, model?}`), calls
`convert(job, cfg)`, returns:
```json
{ "ok": true, "resultId": "<uuid>", "mime": "text/plain", "ext": "txt",
  "bytes": 1234,
  "text": "<inline UTF-8 text if mime is text/* or application/json, else null>",
  "downloadUrl": "/api/result/<uuid>",
  "elapsedMs": 842 }
```
On failure: HTTP 422 `{ "ok": false, "error": "<message>" }`. Text-like results
(`text/*`, `application/json`) include `text` for inline preview; binary
(audio) sets `text=null` and the UI uses `downloadUrl`. Note: **srt**
(`application/x-subrip`) is NOT `text/*`/json → `text=null`, download-only;
**vtt** (`text/vtt`) inlines. Frontend: use `downloadUrl` whenever `text===null`.

## GET /api/result/{resultId}
Streams the result file back with its `Content-Type` and
`Content-Disposition: attachment`. 404 if unknown/expired. Results are kept in a
small in-memory registry mapping `resultId` → temp path (best-effort cleanup).

## WS /ws/stream  (audio streaming, dev-only — NOT backend pull-API)
Browser captures mic and streams audio to the dev-server for live STT.
- **Client → server, first message** (text/JSON, handshake):
  `{ "type": "start", "sampleRate": 16000, "format": "webm/opus", "lang": null }`
  **FINALIZED.** Browser captures mic with `MediaRecorder` (`audio/webm;codecs=opus`)
  and sends each blob as a binary frame — NO AudioWorklet/PCM needed. The server
  **accumulates all frames** (first frame carries the container header, so the
  growing buffer is a valid file) and decodes/transcribes the accumulated buffer
  via faster-whisper (PyAV+ffmpeg, already in the image). `partial` messages are
  cumulative re-transcriptions of the buffer so far; `final` on stop. Fallback
  `format: "pcm_s16le"` is also accepted (server wraps PCM in a WAV header;
  handshake `sampleRate`, default 16000). Implementation note: server uses the
  provider's `process_file()` over the accumulated buffer (not `process_chunk`,
  which returns only `partial` with no segments/language). Wire shapes unchanged.
- **Client → server**: binary frames = raw audio chunks.
- **Client → server, end**: `{ "type": "stop" }`.
- **Server → client** (text/JSON), streamed as windows finalize:
  `{ "type": "partial", "text": "...", "segments": [ {"start":0.0,"end":2.1,"text":"..."} ], "language": "en" }`
  `{ "type": "final",   "text": "full transcript", "segments": [...], "language": "en" }`
  `{ "type": "error",   "message": "..." }`
Backed by `StreamingWhisper.process_chunk(bytes)` (window/overlap from settings).

## GET /api/stats
Live pull-processing stats from the in-process `PullRunner`.
```json
{ "pullEnabled": true,
  "state": "running",            // "running" | "idle" | "stopped"
  "processed": 42, "success": 40, "failed": 2,
  "latencyMs": { "avg": 1200, "p95": 3400, "last": 900 },
  "currentJob": { "conversionId": "c_123", "sourceFormat": "mp3", "targetFormat": "txt", "startedAt": "2026-06-26T10:00:00Z" },
  "lastErrors": [ { "conversionId": "c_120", "error": "...", "at": "2026-06-26T09:58:00Z" } ],
  "startedAt": "2026-06-26T09:50:00Z" }
```
When `PULL_ENABLED=false`: `{ "pullEnabled": false, "state": "stopped" }` plus
last-known counters if any (UI shows "processing disabled").

## GET /api/settings
All editable settings with metadata. `apply` = `"hot"` (live) or `"restart"`
(needs model reload / next job). `value` reflects the effective (env+overlay) value.
```json
{ "settings": [
  { "key": "PULL_ENABLED",       "value": false, "type": "bool",   "group": "pull",      "apply": "hot",     "label": "Enable pull processing" },
  { "key": "POLL_INTERVAL",      "value": 10,    "type": "int",    "group": "pull",      "apply": "hot" },
  { "key": "LLM_MAX_TOKENS",     "value": 1024,  "type": "int",    "group": "llm",       "apply": "hot" },
  { "key": "LLM_TEMPERATURE",    "value": 0.7,   "type": "float",  "group": "llm",       "apply": "hot" },
  { "key": "LLM_SYSTEM_PROMPT",  "value": "",    "type": "str",    "group": "llm",       "apply": "hot" },
  { "key": "WHISPER_MODEL",      "value": "base","type": "enum",   "group": "stt",       "apply": "restart", "options": ["tiny","base","small","medium","large"] },
  { "key": "WHISPER_DEVICE",     "value": "cpu", "type": "enum",   "group": "stt",       "apply": "restart", "options": ["cpu","cuda","mps"] },
  { "key": "WHISPER_COMPUTE_TYPE","value":"int8","type": "enum",   "group": "stt",       "apply": "restart", "options": ["int8","int16","float16","float32"] },
  { "key": "STREAM_WINDOW_SEC",  "value": 20,    "type": "int",    "group": "stt_stream","apply": "restart" },
  { "key": "STREAM_OVERLAP_SEC", "value": 2,     "type": "int",    "group": "stt_stream","apply": "restart" },
  { "key": "TTS_ENGINE",         "value": "espeak","type":"enum",  "group": "tts",       "apply": "restart", "options": ["espeak","pyttsx3"] },
  { "key": "EMBEDDING_MODEL",    "value": "BAAI/bge-m3","type":"str","group": "embedding","apply": "restart" },
  { "key": "EMBEDDING_DEVICE",   "value": "cpu", "type": "enum",   "group": "embedding","apply": "restart", "options": ["cpu","cuda","mps"] },
  { "key": "LLM_BACKEND",        "value": "ollama","type": "enum", "group": "llm",       "apply": "restart", "options": ["ollama","llamacpp"] },
  { "key": "OLLAMA_URL",         "value": "http://localhost:11434","type":"str","group":"llm","apply":"restart" },
  { "key": "OLLAMA_MODEL",       "value": "llama3.2","type":"str", "group": "llm",       "apply": "restart" }
] }
```
The exact key set must match `Config` fields in `workers/ai/config.py`; the list
above is the authoritative grouping/apply-mode. Secrets (`WORKER_API_TOKEN`,
`LLM_MODEL_PATH`) are NOT exposed/editable here.

## PUT /api/settings
Body: `{ "<KEY>": <value>, ... }` (subset). Backend validates types/enums,
writes the overlay JSON (persist on volume), re-derives effective config, and:
- `hot` keys apply immediately; toggling `PULL_ENABLED` starts/stops `PullRunner`.
- `restart` keys are persisted and take effect on next model build / restart.
Response:
```json
{ "ok": true, "applied": ["PULL_ENABLED"], "pendingRestart": ["WHISPER_MODEL"],
  "settings": [ /* same shape as GET, refreshed */ ] }
```
Validation error: HTTP 422 `{ "ok": false, "error": "...", "key": "WHISPER_MODEL" }`.

## Web UI (static/)
SPA, Alpine.js + HTMX + Tailwind via CDN (project frontend rules — no npm in prod).
Tabs: **Methods** (pick mode/source/target, upload, run, preview/download),
**Audio stream** (mic → WS → live transcript), **Pull stats** (poll `/api/stats`
every ~2 s; show "disabled" when off), **Settings** (render groups, hot vs
restart badge, edit + save, `PULL_ENABLED` toggle). Send bearer if configured.

## Conventions
- All timestamps ISO-8601 UTC `Z`.
- Errors: non-2xx with `{ "ok": false, "error": "..." }`.
- The dev-server must never touch the real backend pull-API for the Methods or
  Stream tabs — those run conversions purely locally on `/tmp` files.
