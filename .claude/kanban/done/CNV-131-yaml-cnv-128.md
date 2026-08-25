### YAML и изображения: битый вход ретраится вечно (тот же класс, что CNV-128)

**Criticality:** High

**TAGS:**
- bug-fix
- reliability
- data
- images

**Description:**
Тот же дефект, что закрыт в CNV-128 для XML, живёт ещё в двух местах.
`StreamConsumerBase.process_job()` относит к ПОСТОЯННЫМ ошибкам только
подклассы `ValueError`; всё остальное считается временным и ретраится
бесконечно.

**Problem:**
- `workers/data/worker.py:108` — `yaml.safe_load()`: `yaml.YAMLError` НЕ
  наследует `ValueError`. Битый YAML ретраится вечно.
- `workers/image/worker.py:135` и `:263` — `Image.open(src)`:
  `PIL.UnidentifiedImageError` наследует `OSError`, не `ValueError`. Битое
  изображение ретраится вечно.

**Impact:**
Случай с изображением практически значимее XML и YAML вместе взятых:
повреждённый файл от пользователя — самый частый вид испорченного входа.
Один битый JPEG занимает воркер бесконечно, не давая обратной связи
пользователю. На хостах с одним ядром (saVpn) это вытесняет всю полезную
работу.

**Evidence:**
Найдено ограниченным обзором в рамках CNV-128 (2026-08-25). Иерархии
подтверждены исполнением (`python3 -c` по `__mro__`), а не чтением:
- `yaml.YAMLError` — не подкласс `ValueError`;
- `PIL.UnidentifiedImageError` — подкласс `OSError`;
- `tomllib.TOMLDecodeError` ПРОВЕРЕН и исключён — он уже подкласс `ValueError`.
⚠ Отдельно: существующий тест `test_malformed_yaml_raises` ЗАКРЕПЛЯЕТ текущее
ошибочное поведение как ожидаемое. Его придётся переписать, а не просто
дополнить — иначе исправление уронит его.

**Не обследовано (это НЕ значит «чисто»):**
ffmpeg- и libreoffice-воркеры обзором не покрыты — они subprocess-based,
форма отказа там другая. Нужен отдельный проход.

**Recommendation:**
Повторить приём CNV-128/CNV-98/CNV-75: перехват на месте чтения и
перевыброс как `ValueError` с сохранением исходной детали в сообщении.
Каждое исправление — с доказательством на падение: снять перехват, увидеть
красное, вернуть.

---

## Execution Log (2026-08-25, worker-python)

**Контракт `StreamConsumerBase.process_job()` сверен** с
`workers/common/stream_consumer.py` — дрейфа с описанием карточки/скилла
`backend-architecture` нет: `ValueError` → permanent=True, `FileNotFoundError`
→ transient, всё остальное → generic except → transient.

**Fix 1 — `workers/data/worker.py:106-124`** (чтение YAML). `yaml.safe_load()`
обёрнут в `try/except yaml.YAMLError` → `ValueError(f"malformed YAML: {exc}")`.
Доп. находка (тот же приём, что RecursionError у CNV-128 для XML): аномально
глубоко вложенный, но well-formed YAML (`"[" * 2000 + ... + "]" * 2000`)
переполняет рекурсивный конструктор PyYAML → отдельный `except RecursionError`
→ `ValueError("malformed YAML: nesting too deep (...)")`.
Проверено ОТДЕЛЬНО (мутация/красное/зелёное для каждого catch, оба в
контейнере `worker-data:test`):
- без `except yaml.YAMLError`: `yaml.parser.ParserError: while parsing a flow
  sequence` (сырое) — тест красный.
- без `except RecursionError`: `RecursionError: maximum recursion depth
  exceeded` (сырое) — тест красный.
- оба восстановлены → `make TEST=1 test-python-data` зелёный (121 passed).
Тесты: `test_malformed_yaml_raises` ПЕРЕПИСАН (раньше закреплял сырой
`yaml.YAMLError` как ожидаемое — теперь `ValueError, match="malformed YAML"`);
добавлены `test_deeply_nested_yaml_raises_value_error` и
`test_malformed_yaml_via_convert_propagates_value_error` (convert()-уровень,
по образцу CNV-128 XML-теста).

**Fix 2 — `workers/image/worker.py`** (оба `Image.open()`-сайта — было
`:135`/`:263`, после правки `:135`→`_do_convert`/`:353`(было `:263`)→
`_extract_text`, номера сдвинулись из-за вставленного хелпера). Добавлен
общий хелпер `_open_image(src)`: `Image.open(src)` + принудительный
`img.load()` в одном `try`, перехват `(OSError, Image.DecompressionBombError)`
→ `ValueError(f"malformed image: {exc}")`; `FileNotFoundError` явно
пробрасывается как есть (у `process_job()` для него отдельная TRANSIENT
ветка — блокет `except OSError` иначе тихо переклассифицировал бы его в
permanent). Оба сайта (`_do_convert`, `_extract_text`) переведены на
`with _open_image(src) as img:`.
Доп. находки (тот же класс дефекта, что `PIL.UnidentifiedImageError`,
упомянутый в карточке):
- `Image.open()` только лениво читает заголовок — битое ТЕЛО файла (валидный
  заголовок, обрезанные данные) не всплывает при `open()`, только при
  декодировании (обычно внутри `save()`, глубоко в вызывающем коде) — как
  простой `OSError`. `img.load()` форсирует декод сразу в `_open_image()`,
  до любого output-side I/O.
- `Image.DecompressionBombError` (аномально большие заявленные размеры) —
  наследует голый `Exception`, не `OSError` и не `ValueError`.
Проверено ОТДЕЛЬНО для каждой из 3 частей (мутация/красное/зелёное, всё в
контейнере `worker-image:test`):
- без `OSError` в catch-tuple (оставлен только `DecompressionBombError`):
  `PIL.UnidentifiedImageError: cannot identify image file '...'` и
  `OSError: Truncated File Read` — сырые, 3 теста красные.
- без `DecompressionBombError` в catch-tuple (оставлен только `OSError`):
  `PIL.Image.DecompressionBombError: Image size (2500 pixels) exceeds limit
  of 200 pixels...` — сырое, 1 тест красный.
- без `img.load()` (catch на месте): изначальный фикстур-файл (`Image.new`
  сплошного цвета) оказался вакуумным тестом — truncation резал ЗАГОЛОВОК,
  не только тело, и `Image.open()` сам падал даже без `load()`. Фикстура
  переписана на реальное фото (`example_files/image.jpg`, обрезано до 1/3) —
  после этого без `img.load()` тест корректно красный: сырой `OSError: image
  file is truncated (47 bytes not processed)`, вылетающий изнутри
  `Image.save() → self.load()`.
- всё восстановлено → `make TEST=1 test-python-image` зелёный (55 passed,
  1 xfailed).
Новые тесты (в `TestImageConvertErrors`):
`test_corrupt_raster_input_raises_value_error`,
`test_truncated_raster_input_raises_value_error`,
`test_decompression_bomb_raises_value_error`,
`test_corrupt_raster_input_via_ocr_raises_value_error` (второй `Image.open()`
сайт, OCR-ветка). Ловушки закреплённого поведения на image-стороне НЕ
найдено (`grep` по тестам images — ни один тест не закреплял сырой
`OSError`/`UnidentifiedImageError`).

**Не тронуто (в рамках):** ffmpeg/libreoffice воркеры — отдельный проход
(card это фиксирует явно). Отдельная находка (не в скоупе CNV-131, чтение,
НЕ подтверждено выполнением): `workers/image/worker.py` `_do_svg_convert()`
оборачивает `PNGSurface.convert()` в `except Exception: raise
RuntimeError("SVG rasterization failed")` — тоже не `ValueError`, тот же
класс дефекта потенциально, но механический re-raise тут неверен (сделал бы
genuine TRANSIENT сбои cairo/OOM тоже permanent) → заведена карточка
`.claude/kanban/grooming/CNV-132-svg-rasterization-runtimeerror-not-permanent.md`.

**Гейты:** `make TEST=1 test-python` — 440 passed, 1 xfailed, 2 skipped
(baseline 434 + 6 новых тестов, без регрессий). `make TEST=1 test-up` →
`make TEST=1 test-drift` — 28 passed → `make TEST=1 test-down`. PHP не
трогался — не запускался.

## Execution Log (2026-08-25, worker-python — ревью-фикс на Fix 2)

**Регрессия найдена ревью Fix 2 (`_open_image()`):** catch-tuple
`except (OSError, Image.DecompressionBombError)` перекрывал ЛЮБОЙ
`OSError`, включая настоящие TRANSIENT ошибки уровня ядра (I/O error,
диск полон, permission) — не только сигналы порчи от Pillow. Ревьюер
пропатчил `Image.Image.load` синтетическим `OSError(5, "Input/output
error")` на ВАЛИДНОМ JPEG — ошибка проглатывалась в `ValueError`, задача
уходила в DLQ вместо ретрая. На 1-ядерном/892MB saVpn такой класс отказа
правдоподобен под decode-time memory pressure.

**Дискриминатор подтверждён исполнением** (не чтением) — пробный скрипт
внутри `worker-image:test`:
```
UnidentifiedImageError: errno=None
Truncated-body OSError("image file is truncated (...)"): errno=None
DecompressionBombError: errno-атрибута нет вовсе (голый Exception)
Синтетический OSError(5, "Input/output error"): errno=5
```
Оба сигнала порчи Pillow строятся из голой строки → `errno is None`;
настоящая ошибка ядра несёт числовой `errno`.

**Fix:** `workers/image/worker.py` `_open_image()` — catch-tuple разбит на
два отдельных `except`: `except OSError as exc:` с проверкой
`if exc.errno is not None: raise` (проброс как TRANSIENT) перед
переклассификацией в `ValueError`; `except Image.DecompressionBombError`
вынесен в свою ветку без errno-проверки (у него нет атрибута `errno`
вообще — совмещать с OSError-веткой было бы `AttributeError`).
`FileNotFoundError` — порядок `except`-клауз не тронут, ловится раньше.

**Тест (двунаправленное доказательство):**
`test_kernel_io_error_on_valid_image_stays_transient` — патчит
`Image.Image.load` на синтетический `OSError(5, "Input/output error")`
на валидном JPEG, ожидает `OSError` (не `ValueError`) с `errno == 5`.
- Мутация 1 (снята проверка `if exc.errno is not None: raise`): новый
  тест красный — `ValueError: malformed image: [Errno 5] Input/output
  error`. Восстановлено → зелёный.
- Мутация 2 (снят `img.load()`, регрессионный чек CNV-131): существующий
  `test_truncated_raster_input_raises_value_error` красный — сырой
  `OSError: image file is truncated (47 bytes not processed)` вместо
  `ValueError`; `test_corrupt_raster_input_raises_value_error` и
  `test_decompression_bomb_raises_value_error` остались зелёными (не
  зависят от форсированного декода). Восстановлено.

**Гейты:** `make TEST=1 test-python` — 441 passed, 1 xfailed, 2 skipped
(440 baseline + 1 новый тест, без регрессий). `make TEST=1 test-up` →
`make TEST=1 test-drift` — 28 passed → `make TEST=1 test-down`. PHP не
трогался — не запускался.
