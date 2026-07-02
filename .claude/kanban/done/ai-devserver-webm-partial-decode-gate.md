### AI dev-server — verify truncated webm/opus partial decode in a real av/ffmpeg image

**Критичность:** Medium

**TAGS:**
- verification
- ai-worker

**Описание:**
Dev-сервер AI-воркера (`workers/ai/devserver`) стримит аудио по WS как `webm/opus`
(браузерный `MediaRecorder`) и на сервере **аккумулирует** все бинарные фреймы,
прогоняя растущий буфер через faster-whisper (`process_file`) для `partial`/`final`.

**Проблема:**
Путь декода webm/opus был протестирован backend-dev только с **замоканной** моделью —
`av`/faster-whisper отсутствуют в его окружении и в тест-образе. `final` по полному
буферу безопасен (валидный файл). Риск — **`partial` по обрезанному mid-stream буферу**:
не гарантировано, что `av.open()`/ffmpeg корректно декодируют неполный webm-контейнер
(возможны исключения или пустой результат на «хвосте» без полного кластера).

**Что проверить:**
- В образе с установленным `av` (PyAV): синтезировать короткий webm/opus, обрезать его
  в нескольких точках, прогнать `av.open()` → decode и `StreamingWhisper.process_file`
  над обрезанным буфером. Убедиться, что partial либо даёт корректный текст, либо
  деградирует мягко (без 500/краша WS).
- Если обрезанный буфер падает — добавить на сервере fallback: ловить ошибку декода
  partial и просто пропускать тик (ждать следующего фрейма), либо декодировать только
  по границам кластеров; `final` оставить как есть.

**Влияние:**
Без проверки live-partial в аудио-вкладке может молча не работать (только финал) на
реальном раннере с GPU. Не блокер MVP dev-сервера, но нужно закрыть до полагания на
live-транскрипт.

**Decisions:**
- On undecodable partial = **emit an error frame (noisy), keep socket open** (not silent skip-tick). Plus the in-image verification task: synthesize+truncate webm/opus, run av.open() + StreamingWhisper.process_file.

**Status:** ready (todo).

---

### Execution Log

**Verdict: routes_stream.py:161-165 is SUFFICIENT as-is. NO server code change needed.**

**Harness:** `workers/ai/verify_webm_partial.py` (committed). Runs inside
`xakki-convertor/worker-ai:cpu` (PyAV 17.1.0, faster_whisper, ffmpeg 7.1.5, py3.12):
```
docker run --rm -v /home/xakki/convertor:/app -w /app \
  --entrypoint python xakki-convertor/worker-ai:cpu workers/ai/verify_webm_partial.py
```
Synthesizes a ~5s real-speech webm/opus (espeak-ng → libopus, sine fallback),
truncates it as a PREFIX (server always holds header+prefix) at 12 offsets
(1KB, 4KB, 10%..90%, 100%), and runs EACH decode in a **separate subprocess with a
60s timeout**, classifying by child exit status: exit0=clean, nonzero+traceback=
catchable exception, killed-by-signal=CRASH, timeout=HANG. Each child runs the exact
production path `StreamingWhisper("tiny","cpu","int8").process_file(tmp.webm)` plus a
raw `av.open()`+decode probe for diagnostics. A single-process try/except would be a
false green (can itself be signal-killed), so isolation is mandatory.

**MediaRecorder fidelity:** swept TWO webm profiles — `live` (`ffmpeg -live 1`:
unknown segment/cluster sizes, no trailing Cues/SeekHead = what a browser
MediaRecorder emits) and `file` (ffmpeg default mux, known sizes + Cues). Both
behave identically, closing the "that's not really MediaRecorder output" objection.

**Results (24/24 points, both profiles):**

| prof | offset | bytes | classification | note |
|---|---|---|---|---|
| live/file | hdr-1KB | 1024 | CLEAN/EMPTY | av opens, 2 frames, whisper returns "" (soft) |
| live/file | hdr-4KB | 4096 | CLEAN/TEXT | partial text appears |
| live/file | 10%..90% | … | CLEAN/TEXT | cumulative partial text grows monotonically |
| live/file | 100% | 107136/107166 | CLEAN/TEXT | full baseline transcribes |

**Zero CRASH, zero HANG, zero catchable exception** across all 24 truncation points.
PyAV/ffmpeg's demuxer is designed for partial/streaming input: a mid-cluster prefix
decodes the frames it has and stops cleanly (probe frame counts grow monotonically,
`probe_err=None` everywhere). The header-only 1KB slice degrades softly to empty text.

**Why this settles it:** a C-level SIGSEGV/SIGABRT in libav would kill any host
process — thread or standalone child alike — so an isolated child that never crashes
proves the in-thread `asyncio.to_thread(_transcribe,...)` decode won't crash either.
The isolated harness is a *stricter* proxy than production. Therefore the existing
`except Exception → error frame, keep socket open` (routes_stream.py:161-165) already
satisfies the decision; no subprocess isolation / cluster-boundary gating is required.

**Out of scope (noted, not fixed here):** the WS buffer grows unboundedly and every
partial re-transcribes the entire accumulated buffer (O(n²) per session) — a perf
smell, not this card's crash/hang gate. Candidate for a separate grooming card.
