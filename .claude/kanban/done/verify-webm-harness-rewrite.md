### verify_webm_partial.py — переписать под текущий путь + self-assert базлайна

**Критичность:** Low (dev/test-тулинг, не рантайм)

**TAGS:**
- ai-worker
- test-tooling

**Ветка:** off `main` (dev-тестер, НЕ часть S1-эпика). `task/verify-webm-harness`.

**Описание:**
Консолидация двух grooming-находок по одному файлу `workers/ai/verify_webm_partial.py`
(сворачивает бывшую `ai-verify-harness-assert-baseline`):

1. **Устаревший докстринг + raw-av child.** Докстринг (строки 3–8) описывает СТАРЫЙ
   путь стрима (accumulator-буфер → temp-файл → `process_file()` subprocess), которого
   после [[ai-devserver-stream-vad]] больше нет. Сам код декодит реальный av/ffmpeg, но
   **не через прод-декодер** `PcmStreamDecoder`.
2. **Вердикт не самопроверяет happy-path.** `run_parent` (строки 213–222) печатает
   `SAFE`/`UNSAFE` только по наличию CRASH/HANG. Мир, где КАЖДЫЙ срез (вкл. 100%) вернул
   ловимое EXCEPTION, всё равно даст `SAFE`.

**ВАЖНО (скаут 2026-07-05, факты с file:line):** харнесс даёт **уникальное покрытие,
которого в `test_ai_devserver.py` НЕТ** — поэтому НЕ ретайрить:
- усечение по процентам 10%…90% + header-only 1KB/4KB (строки 166–175); в pytest только
  4 равных чанка (25%);
- **subprocess-изоляция + `CHILD_TIMEOUT=60`** ловит SIGSEGV/SIGABRT/HANG (строки 179–184,
  classify 131); in-process pytest этого не может в принципе.

**Decisions (груминг 2026-07-05):**
- **Переписать + подхарденить** (не ретайр, не только докстринг): (а) переписать докстринг
  под текущий путь `PcmStreamDecoder` (PyAV) + `VadChunker` + `StreamingWhisper.transcribe_pcm`;
  (б) нацелить child-декод на реальный **`PcmStreamDecoder.feed()/close()`** (прод-путь),
  сохранив truncation-sweep + subprocess-изоляцию + timeout как есть.
- **Self-assert базлайна — ОБА профиля (`live` + `file`).** В `run_parent`: строка `100%`
  каждого профиля ДОЛЖНА классифицироваться как `CLEAN/TEXT`; иначе → `UNSAFE`/exit 1 с
  внятным сообщением. Делает `exit 0` надёжным регресс-сигналом после апдейтов PyAV/ffmpeg.

**Файлы:**
- Изменить: `workers/ai/verify_webm_partial.py` (докстринг; child → `PcmStreamDecoder`;
  `run_parent` baseline-assert обоих профилей).

**Критерии приёмки:**
- Докстринг описывает текущий путь (`PcmStreamDecoder`/`VadChunker`/`transcribe_pcm`); нет
  упоминаний accumulator→temp→`process_file()`.
- child прогоняет усечённые срезы через `PcmStreamDecoder.feed()/close()` (прод-декодер), а
  не raw-av; truncation-sweep (hdr-1KB/4KB, 10%…100%) + subprocess-timeout сохранены.
- `run_parent` ассертит `100%`-строку профилей `live` И `file` = `CLEAN/TEXT`; иначе exit 1.
- Ручной прогон харнесса на чистом окружении → `exit 0` (base line декодится в непустой текст,
  усечения crash/hang-safe).

**Найдено при:** ревью [[ai-devserver-stream-vad]] / [[ai-devserver-webm-partial-decode-gate]].

**Execution Log (2026-07-18, ветка `task/verify-webm-harness`, commit `7cb38ec`):**
- Реализовано (файл `workers/ai/verify_webm_partial.py`): докстринг переписан под
  прод-путь; `run_child` гонит усечённый префикс через прод-декодер
  `PcmStreamDecoder.feed()/drain()/close()` (`workers/ai/devserver/pcm_decoder.py`) →
  `VadChunker.push()/flush()` (`workers/ai/devserver/vad_chunker.py`) →
  `StreamingWhisper.transcribe_pcm()`; `decode_error()` ре-рейзится (→ EXCEPTION,
  зеркалит ловимый прод-путь). `classify()` без `probe`. `run_parent` ассертит
  `100%` обоих профилей = `CLEAN/TEXT`, иначе exit 1. Sweep/subprocess/timeout как были.
- `python3 -m py_compile` — OK. Уточнение (drift): три класса лежат в РАЗНЫХ модулях
  (`pcm_decoder.py`, `vad_chunker.py`, `providers/streaming_stt.py`), не в одном.
- **Ручной прогон в образе worker-ai (AC #4) — подтверждён на пересобранном образе:**
  `docker run … xakki-convertor/worker-ai:cpu workers/ai/verify_webm_partial.py` →
  **exit 0 / SAFE**, оба 100%-базлайна `CLEAN/TEXT`, транскрипция растёт монотонно по
  префиксу; `hdr-1KB`→`CLEAN/EMPTY`; ни CRASH, ни HANG. Вывод харнесса подтверждает:
  in-process `except`/`decode_error()` в WS-route ДОСТАТОЧЕН.
- **Стоп-находка (не баг харнесса) — образ пересобран, корневая причина установлена:**
  прежний `xakki-convertor/worker-ai:cpu` не содержал `webrtcvad` → первый прогон дал
  exit 1 (self-assert сработал верно). Причина НЕ в кэше: `ai.cpu.Dockerfile` берёт
  `FROM harbor.xakki.ru/convertor/worker-ai-base:latest` — устаревший base ИЗ Harbor
  (собран до `webrtcvad-wheels` в `requirements-ai-ml.txt:10`). Пересборка через
  локальный base-тег (в обход реестра) → `webrtcvad-wheels-2.0.14`, `import webrtcvad`
  OK, харнесс exit 0. Полный разбор + правильный порядок (`build-ai-base` →
  **`push-ai-base`** → `build-ai-cpu`) — grooming-карточка
  `stale-worker-ai-cpu-image-webrtcvad.md`.

**Известное ограничение:** декод идёт в фоновом демон-потоке `PcmStreamDecoder` с
ограничением `close()` в 5с — зависание НА СТАДИИ ДЕКОДА бьётся этим таймаутом и
классифицируется как `CLEAN/EMPTY`, а не `HANG` (это ФАКТ прод-архитектуры, харнесс
её честно отражает). Subprocess-`HANG` теперь стережёт `transcribe_pcm`/`feed`, а не
фоновый декод-поток. Осознанно, фикса не требует.

**Status:** test — реализовано + валидировано (харнесс exit 0 при наличии deps).
