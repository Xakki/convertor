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
- **S1-секвенс (груминг 2026-07-05): ОТЛОЖИТЬ до post-`[[s1-11-onserver-workers-migrate]]` + re-scope.**
  Шаг (c) (template `convert()`/`_do_convert`) пересекается с `[[s1-10-streamconsumer-refactor-unify]]`,
  который и так расщепляет `StreamConsumerBase` на transport-agnostic `process_job`/`_do_convert` — (c)
  будет сделан там. Делать (a)/(b) сейчас параллельно нельзя: s1-10/s1-11 переписывают те же файлы
  воркеров → merge-конфликты и двойная работа. → когда S1-миграция воркеров завершится, **пере-скоупить
  эту карту до только (a) shared `_run`-subprocess + (b) MIME-таблица** (transport-agnostic хвост, в s1-10
  не входит), убрать (c). До тех пор — не стартовать.

- **RE-SCOPE подтверждён (груминг 2026-07-08):** s1-11 в `ready/`, миграция воркеров завершена → карта
  разблокирована. Скоуп сужен до **(a) shared `_run`-subprocess helper + (b) MIME-таблицы в `workers/common/`**.
  Шаг (c) (template `convert()`/`_do_convert`) исключён — сделан в s1-10. Поведение не меняется, после каждого
  шага — полный per-worker прогон. Работаем в `task/s1-ws-transport` (эпик-ветка).

**Status:** progress — (a)+(b) реализованы, все гейты зелёные, ждёт ревью.

**Execution Log (2026-07-08, Agent: extract-common-helpers):**

Чистый рефактор, поведение не менялось.

- **(a) subprocess-runner → `workers/common/subprocess_runner.py`.** Новый `run_capture(argv, timeout, *, full_error=True)`:
  инкапсулирует `create_subprocess_exec(PIPE,PIPE)` + `wait_for(communicate, timeout)` + `kill`/`wait` на таймауте
  (`RuntimeError "{argv[0]} timed out after {timeout}s"`) + проверку returncode. Логирования нет намеренно (чтобы имя
  лог-записи не уехало в `workers.common`). Расхождение форматирования ошибки параметризовано флагом `full_error`:
  - `full_error=True` (LibreOffice): `err or out or rc` — по умолчанию;
  - `full_error=False` (ffmpeg): только `err` (stderr).
  Рерайринг: `libreoffice/worker.py::_run` → тонкая обёртка над `run_capture` (сигнатура/дефолт `SOFFICE_TIMEOUT`
  сохранены, `run_soffice`/`run_pdftotext`/`run_pandoc` не тронуты — тесты патчат `run_soffice`). `ffmpeg/worker.py::run_ffmpeg`
  строит argv как раньше, зовёт `run_capture(argv, timeout, full_error=False)`, `logger.debug("ffmpeg stdout: …")` оставлен
  в самом воркере (под `workers.ffmpeg.worker`). Таймаут-сообщение идентично прежнему (`argv[0]=="ffmpeg"`). image/data
  subprocess не используют — не тронуты.
- **(b) MIME → `workers/common/mime.py`.** `DOCX_MIME` (дедуп дублировавшейся `_DOCX_MIME` из libreoffice+image; в image
  строка была склеена из двух литералов — проверена byte-identity) + `DOC_TEXT_MIME = {docx, pdf, txt, md}` — ровно общий
  выход document- и image/OCR-воркеров. `libreoffice._MIME = {**DOC_TEXT_MIME, odt, rtf, html, epub, rst}`,
  `image._MIME = {…image-native…, **DOC_TEXT_MIME}` — итоговые строки идентичны (порядок ключей на `.get()` не влияет).
  ffmpeg (audio/video) и data (json/csv/xml/yaml/toml) MIME оставлены локальными — общих записей нет.
- **Не трогалось:** `_MATRIX`/`SUPPORTED`, `CAPABILITIES`, `process_job`, `convert()`-шаблон, `__init__.py` → `test-drift` не затронут.
- **Гейты (project root, все зелёные):** `make docker-check` EXIT=0; `make test-drift` 2 passed; `make test-python`
  весь per-worker чейн EXIT=0 (включая real-binary интеграционные `test_ffmpeg_integration` / `test_libreoffice_integration`
  — реально исполняют `run_capture`); `make test-gateway` 100 passed. Python lint/type-гейта в проекте нет.
