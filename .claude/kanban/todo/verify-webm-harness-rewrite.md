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

**Status:** todo — груминг завершён, scope ясен.
