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

**Open questions:**
- Что выносить и насколько глубоко: (а) только общий `async _run(argv, timeout)` хелпер; (б) + общая таблица MIME; (в) шаблонный `convert()` в `StreamConsumerBase`, вызывающий subclass-метод `_do_convert(src, src_fmt, target) -> Path` с матрицей/MIME как атрибутами класса (самый большой blast-radius — трогает все 4 воркера разом)?
- Делать пошагово (по одному хелперу) или единым рефактором?
- Объём регрессионного тестирования (у каждого воркера свои тесты — прогнать все).

**Зависимости:** Делать после стабилизации воркеров (все уже Streams-consumer'ы).

**Decisions:** —
