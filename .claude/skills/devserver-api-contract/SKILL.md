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
`127.0.0.1:8877` by default
(`DEVSERVER_HOST`/`DEVSERVER_PORT`). Optional bearer (`DEVSERVER_TOKEN`): if set,
every `/api/*` and `/ws/*` request must send `Authorization: Bearer <token>`
(WS via `?token=` query param too). Default = no token (localhost only).

Static UI served at `/` from `workers/ai/devserver/static/` (SPA, 4 tabs).
All API under `/api/`, WS under `/ws/`. JSON everywhere except file upload
(multipart) and result download (binary stream).

**Base-path aware (subpath serving).** The UI works BOTH at direct `:8877/` AND
behind a reverse proxy at a subpath (e.g. nginx `/worker-ai/` → `proxy_pass …/`
strips the prefix). To allow this: index.html references assets with **relative**
URLs (`app.js`, `vendor/…`, no leading slash); app.js derives a base from
`location.pathname` (`basePath()` = directory of the page) and builds every
API/WS URL as `basePath()+'api/…'` / `wsUrl('/ws/stream')` rather than absolute
`/api…`. Backend-returned absolute URLs (e.g. `downloadUrl: "/api/result/<id>"`)
are re-resolved against `basePath()` client-side (`resolveUrl`). nginx must
redirect the no-slash form (`/worker-ai` → `/worker-ai/`) so relative resolution
keeps the prefix.

## Architecture notes (binding decisions)

- **WS-runner runs IN the dev-server.** The dev-server owns a controllable
  background WS task (`WsRunner`) that wraps a `WsClient` connecting to the
  gateway. It always starts on lifespan startup (`WsClient.validate()` handles
  the "no gateway configured" case gracefully — logs and returns without looping).
  The runner can be hot-stopped/started via `runner.stop()`/`runner.start()`
  calls in `routes_settings`. `update_cfg()` swaps in a new AI config; it takes
  effect on the next job (LLM parameters etc. applied per-job).
- **Stats are in-memory** in a shared `Stats` object the `WsRunner` updates and
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
Each method carries a `description` (string) — a concise, human "what this does"
the UI shows under the mode selector (e.g. stt_stream explains sliding-window
streaming vs one-shot stt).
```json
{ "methods": [
  { "mode": "stt",       "label": "Speech → Text",      "sources": ["mp3","wav","ogg","m4a","opus","flac"], "targets": ["txt","srt","vtt"], "description": "One-shot transcription…" },
  { "mode": "stt_stream","label": "Speech → Segments",  "sources": ["mp3","wav","ogg","m4a","opus","flac"], "targets": ["json"], "description": "Streaming transcription via sliding windows…" },
  { "mode": "tts",       "label": "Text → Speech",      "sources": ["txt","md"], "targets": ["mp3","wav","ogg"], "description": "Speech synthesis…" },
  { "mode": "embedding", "label": "Text → Embedding",   "sources": ["txt","md"], "targets": ["json"], "description": "Vector embedding…" },
  { "mode": "llm",       "label": "Text → Text (LLM)",  "sources": ["txt","md"], "targets": ["txt","md"], "description": "LLM generation…" }
] }
```

## POST /api/run  (multipart/form-data)
Run one conversion. Input is EITHER `file` (the upload) OR `text` (a string —
for text-input methods like tts/llm/embedding where the user types directly);
exactly one is required, `file` wins if both are sent, neither → 422
`{"ok": false, "error": "provide a file or text input"}`. Other fields:
`sourceFormat` (str), `targetFormat` (str), optional `model` (str, LLM/embedding).
Backend writes the upload (or the `text`, UTF-8) to a temp path under WORK_DIR,
builds a job dict (`{_localInput, conversionId, sourceFormat, targetFormat,
model?}`), calls `convert(job, cfg)`, returns:
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

**WS Origin allowlist (anti-CSWSH).** The handshake rejects (close 1008) browser
Origins not in an allowlist. Localhost (`http(s)://localhost|127.0.0.1:<port>`)
is always allowed. When exposed off-loopback (`DEVSERVER_HOST=0.0.0.0`) or proxied
at a public origin (e.g. `https://convertor.xakki.pro/worker-ai/`), the Origin is
NOT localhost — set **`DEVSERVER_ALLOWED_ORIGINS`** (comma-separated absolute
origins, no path, e.g. `https://convertor.xakki.pro,http://myhost:8877`) to add
them. Decoupled from `DEVSERVER_HOST` (bind host ≠ public origin; a proxied origin
has no port). Missing Origin (CLI/TestClient) is allowed; the token check still applies.
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
Live WS-stats from the in-process `WsRunner`.
```json
{ "connected": true,
  "inflight": 1,
  "lastPong": null }
```
`connected` = WsRunner task alive (proxies "currently connected to gateway").
`inflight` = число задач, обрабатываемых прямо сейчас.
`lastPong` = ISO UTC string последнего pong от gateway (`null` до первого pong).
When runner not started / gateway not configured: `{ "connected": false, "inflight": 0, "lastPong": null }`.

## GET /api/settings
All editable settings with metadata. `apply` = `"hot"` (live) or `"restart"`
(needs model reload / next job). `value` reflects the effective (env+overlay) value.
Each setting also carries `label` (short human name) and `help` (one-line
description: what it controls, valid values, effect) — the UI shows `label` as the
field title and `help` as a `<small>` + native `title=` tooltip. Both omitted from
the abbreviated sample below for brevity; they are present on every setting.
```json
{ "settings": [
  { "key": "LLM_MAX_TOKENS",     "value": 1024,  "type": "int",    "group": "llm",       "apply": "hot" },
  { "key": "LLM_TEMPERATURE",    "value": 0.7,   "type": "float",  "group": "llm",       "apply": "hot" },
  { "key": "LLM_SYSTEM_PROMPT",  "value": "",    "type": "str",    "group": "llm",       "apply": "hot" },
  { "key": "WHISPER_MODEL",      "value": "base","type": "enum",   "group": "stt",       "apply": "restart", "options": ["tiny","base","small","medium","large"], "helpUrl": "https://huggingface.co/Systran" },
  { "key": "WHISPER_DEVICE",     "value": "cpu", "type": "enum",   "group": "stt",       "apply": "restart", "options": ["cpu","cuda","mps"] },
  { "key": "WHISPER_COMPUTE_TYPE","value":"int8","type": "enum",   "group": "stt",       "apply": "restart", "options": ["int8","int16","float16","float32"] },
  { "key": "STREAM_WINDOW_SEC",  "value": 20,    "type": "int",    "group": "stt_stream","apply": "restart" },
  { "key": "STREAM_OVERLAP_SEC", "value": 2,     "type": "int",    "group": "stt_stream","apply": "restart" },
  { "key": "TTS_ENGINE",         "value": "espeak","type":"enum",  "group": "tts",       "apply": "restart", "options": ["espeak","pyttsx3"] },
  { "key": "EMBEDDING_MODEL",    "value": "Qwen/Qwen3-Embedding-0.6B","type":"str","group": "embedding","apply": "restart", "helpUrl": "https://huggingface.co/models?library=sentence-transformers&pipeline_tag=feature-extraction&sort=trending" },
  { "key": "EMBEDDING_DEVICE",   "value": "cpu", "type": "enum",   "group": "embedding","apply": "restart", "options": ["cpu","cuda","mps"] },
  { "key": "LLM_BACKEND",        "value": "llamacpp","type": "enum", "group": "llm",     "apply": "restart", "options": ["ollama","llamacpp"] },
  { "key": "LLM_MODEL_REPO",     "value": "Qwen/Qwen2.5-0.5B-Instruct-GGUF","type":"str","group":"llm","apply":"restart", "helpUrl": "https://huggingface.co/models?library=gguf&pipeline_tag=text-generation&sort=trending" },
  { "key": "LLM_MODEL_FILE",     "value": "qwen2.5-0.5b-instruct-q4_k_m.gguf","type":"str","group":"llm","apply":"restart" },
  { "key": "OLLAMA_URL",         "value": "http://localhost:11434","type":"str","group":"llm","apply":"restart" },
  { "key": "OLLAMA_MODEL",       "value": "llama3.2","type":"str", "group": "llm",       "apply": "restart", "helpUrl": "https://ollama.com/library" }
] }
```
The exact key set must match `Config` fields in `workers/ai/config.py`; the list
above is the authoritative grouping/apply-mode. Secrets (`WORKER_API_TOKEN`,
`LLM_MODEL_PATH`) are NOT exposed/editable here. Optional `helpUrl` (string): a
"where to find compatible models" link for model-valued settings; the UI renders
it as a "find models ↗" link next to the field. Absent on settings without one.

## PUT /api/settings
Body: `{ "<KEY>": <value>, ... }` (subset). Backend validates types/enums,
writes the overlay JSON (persist on volume), re-derives effective config, and:
- `hot` keys apply immediately (LLM params — runner picks up on next job).
- `restart` keys are persisted and take effect on next model build / restart.
Response:
```json
{ "ok": true, "applied": ["LLM_MAX_TOKENS"], "pendingRestart": ["WHISPER_MODEL"],
  "settings": [ /* same shape as GET, refreshed */ ] }
```
Validation error: HTTP 422 `{ "ok": false, "error": "...", "key": "WHISPER_MODEL" }`.

## Web UI (static/)
SPA: semantic HTML styled by **Pico.css** (classless, no build step) + **Alpine.js**.
Both **vendored locally** under `static/vendor/` (`pico.min.css`, `alpine.min.js`)
and referenced with relative URLs — NOT CDN, and NO Tailwind/runtime JIT. Deliberate
deviation from the project "use CDN" frontend rule because this is an internal dev
tool that must run on an offline/restricted GPU box (no external network). The page
uses `<html data-theme="dark">` plus a small inline `<style>` for the few bits Pico
doesn't cover (x-cloak, tab active state, status badges, record indicator, field
rows). Asset/API/WS URLs are base-path relative (see above) so it serves at `:8877/`
and under `/worker-ai/`.
Tabs: **Methods** (pick mode/source/target, upload, run, preview/download),
**Audio stream** (mic → WS → live transcript), **WS stats** (poll `/api/stats`
every ~2 s; show connection state + in-flight count), **Settings** (render groups,
hot vs restart badge, edit + save). Send bearer if configured.

## Conventions
- All timestamps ISO-8601 UTC `Z`.
- Errors: non-2xx with `{ "ok": false, "error": "..." }`.
- The dev-server must never touch the real backend pull-API for the Methods or
  Stream tabs — those run conversions purely locally on `/tmp` files.
