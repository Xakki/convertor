### Вынести общие хелперы воркеров в `workers/common`

**Критичность:** Low — tech-debt / DRY (рефактор, поведение не меняется)

**TAGS:**
- tech-debt
- refactor

**Описание:**
Всплыло при ревью [[validate-libreoffice-worker]] (2026-06-21). Четыре воркера (ffmpeg/image/data/libreoffice) дублируют один и тот же код:
- **subprocess-runner**: `create_subprocess_exec` + `wait_for(timeout)` + `kill`/`wait` + проверка returncode (`libreoffice/worker.py:_run`, `ffmpeg/worker.py:run_ffmpeg`).
- **преамбула/эпилог `convert()`**: извлечение `conversionId`/`src`/`targetFormat`/`sourceFormat`, `is_file()`-проверка, валидация по матрице, `WORK_DIR.mkdir`, генерация `out-<id>-<uuid>` имени, `exists()`-проверка результата, `_MIME.get(..., octet-stream)`, `logger.info`. Одинаковая форма во всех четырёх.
- **MIME-константы** (напр. `_DOCX_MIME` дублируется в libreoffice + image).

**Зависимости:** Делать после стабилизации воркеров (все уже Streams-consumer'ы).

**Decisions:**
- `workers/common/` + StreamConsumerBase already exist → "deepen base". Stepwise: (a) shared `_run` → (b) +MIME table → (c) template `convert()`/`_do_convert`, full per-worker test run after each. Do after worker stabilization.

**Status:** grooming (LOW, post-stabilization).
