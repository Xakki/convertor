### Static SVG: image-worker legacy targets

**Criticality:** Medium

**TAGS:**
- feature
- images
- svg
- image-worker

**Description:**
Реализовать в image-worker статичную конвертацию SVG в GIF, BMP, TIFF и ICO. Карточка не меняет API-каталог и frontend.

**Problem:**
Однокадровый SVG pipeline ещё не применяет зафиксированные правила palette/alpha, TIFF и ICO, поэтому результаты legacy-форматов непредсказуемы.

**Impact:**
Без worker-реализации backend не сможет безопасно публиковать обещанные static SVG targets, а пользователи получат неверные кадры или свойства файлов.

**Recommendation:**
Использовать существующий безопасный SVG raster pipeline с CairoSVG и Pillow: GIF — один статичный кадр, BMP — без alpha, TIFF — single-page LZW, ICO — PNG-кадры 16/32/48/256. Принимать только нормализованные options из job; не добавлять browser runtime или анимацию.

**Acceptance Criteria:**
- image-worker создаёт статичный GIF из SVG без анимации.
- BMP не содержит alpha; TIFF состоит из одного LZW-сжатого кадра.
- ICO содержит PNG-кадры 16×16, 32×32, 48×48 и 256×256.
- Worker-тесты с SVG fixture проверяют MIME/свойства результата, размеры и кадры ICO.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Статичный SVG → GIF остаётся однокадровой image-worker конвертацией.
- Анимированный SVG → GIF принадлежит CNV-82 и browser-worker; fallback анимации в один кадр запрещён.
- Публикацию пар в catalog выполняет CNV-95, UI — CNV-96.

---

## Execution Log (worker, 2026-08-24)

### Что изменено

`workers/image/worker.py`:
- `_SVG_TARGETS` расширен с 4 до 8 форматов (+`gif`,`bmp`,`tiff`,`ico`); `_MATRIX["svg"]`
  ссылается на ту же переменную — AST-экстрактор (`workers/tools/capabilities_ast.py`)
  видит только литерал, никакой мутации.
- `_validate_svg_well_formed()` — новая функция, вызывается ДО `try/except` в
  `_do_svg_convert()`: `ET.fromstring()` → `ET.ParseError` перевыбрасывается как
  `ValueError("malformed SVG: ...")`, чтобы не попасть в существующий
  `except Exception: raise RuntimeError(...)` (который классифицируется как
  transient и ретраится вечно).
- `_save_svg_bmp()` — RGBA/LA/палитра-с-transparency композитится на `options["background"]`
  (дефолт `#FFFFFF`), тот же приём, что и JPEG-ветка `_save_image()` (не рефакторил —
  дублирование 6 строк вместо риска для уже протестированного raster→jpeg).
- `_save_svg_tiff()` — `image.save(..., "TIFF", compression="tiff_lzw")`.
- `_save_svg_ico()` — рендер сначала contain-fit'ится (аспект сохраняется) на
  прозрачный 256×256 canvas, и уже ЭТОТ canvas уходит в `save(..., "ICO",
  sizes=[16,32,48,256])`. Без этого шага Pillow's ICO-writer молча отбрасывает
  любой размер больше `im.size` — подтверждено can-fail proof (d) ниже.
- `_do_svg_convert()` диспетчеризует bmp/tiff/ico на новые функции; gif/png/jpg/jpeg/webp
  идут по прежнему пути через `_save_image()` без изменений (default Pillow GIF save
  уже однокадровый — отдельного кода не потребовалось).

`workers/tests/test_image_worker_stream.py`: `test_svg_matrix_has_only_raster_targets`
обновлён на 8-элементное множество; новый класс `TestSvgLegacyTargets` (9 тестов) +
хелперы `_make_transparent_svg`, `_make_malformed_svg`, `_parse_ico_entries` (сырой
байтовый парсер ICONDIR/ICONDIRENTRY — не через Pillow, чтобы реально проверить
PNG-сигнатуру `\x89PNG\r\n\x1a\n` каждого кадра, а не поверить абстракции).

`app-symfony/Makefile`: добавлен `dump-matrix-write` (`$(PHP_EXEC) php bin/dump-matrix.php
--write`) — таргета для регенерации golden-фикстуры не было, окружение требует
ЛЮБУЮ docker-команду только через Makefile (первая попытка сделал `docker exec`
руками — поймал сам, откатил на таргет).

`app-symfony/config/catalog/{worker_capabilities,conversion_pairs}.json` — регенерированы
`make formats-catalog` (не правились руками). `app-symfony/tests/Fixtures/conversion_matrix.golden.txt`
— регенерирован `make dump-matrix-write`. `app-symfony/tests/Functional/Controller/Api/
FormatsCatalogIndependenceTest.php` — константа `EXPECTED_CATALOG_PAIR_COUNT` 398→402.

### Rasterization и гарантии

- **GIF** — `_save_image()` без `save_all=True` → Pillow пишет ровно один кадр (default
  поведение, никакого нового кода). Can-fail (a): мутация форсит `save_all=True` +
  ВИЗУАЛЬНО ОТЛИЧНЫЙ второй кадр (identical-copy мутация НЕ красит тест — Pillow
  схлопывает пиксельно-идентичные последовательные GIF-кадры, это не баг теста, а
  реальное поведение библиотеки, обнаруженное эмпирически).
- **BMP** — explicit composite на фон перед save; `image.mode` после Pillow BMP save
  и БЕЗ композита уже "RGB" (Pillow сам режет alpha-канал из файла), но пиксель в
  месте бывшей прозрачности остаётся ЧЁРНЫМ без композита — реальный визуальный баг,
  который тест на pixel-value (не только на mode) ловит.
- **TIFF** — `compression="tiff_lzw"` явно; без него Pillow пишет `tag_v2[259]==1`
  (no compression), не 5 (LZW) — подтверждено can-fail (c).
- **ICO** — фиксированный 256×256 transparent canvas + `ImageOps.contain()` перед save
  гарантирует, что ни один из 4 запрошенных размеров не попадёт под Pillow's
  size>im.size silent-drop фильтр (подтверждено can-fail (d): без canvas'а на
  20×10 test-fixture все 4 записи молча исчезают, `sizes == set()`).

### Открытый вопрос (нужен team-lead ack)

`_save_svg_ico()` **сознательно НЕ применяет** job-level `width`/`height` (в отличие от
gif/bmp/tiff/png/jpg/webp, которые продолжают идти через `_apply_image_options()`).
ICO — контейнер с собственным фиксированным набором размеров; применить resize к
источнику, а затем всё равно contain-fit на 256-canvas — сделало бы опцию
silently-inert (запрещено CNV-75/Lesson 4). Решение по прецеденту CNV-98
(`markdownDialect` не влияет на `pdf→md`): явно НЕ применяем и документируем, а не
"применяем и перезаписываем". Backend-профиль для `svg→ico` не существует (CNV-95
идёт после этой карточки) — сегодня `options` для этой пары в проде всегда `{}`.
Зафиксировано тестом `test_svg_to_ico_ignores_width_height_options_by_design`.
**Если team-lead НЕ согласен с этим поведением** — дешёвое разрешение НЕ требует
правки воркера: CNV-95 просто не назначает `width`/`height` профилю `svg→ico`
(backend-side решение, ноль изменений здесь).

**Известная quality-накладка ICO (не блокер, AC выполнен):** `_save_svg_ico()`
рендерит SVG на его исходном/нативном разрешении (как и остальные targets), а уже
ПОТОМ contain-fit'ит растр на 256×256 canvas — для маленького source (напр. тестовый
20×10 fixture) это LANCZOS-апскейл, не честный векторный рендер в 256px. Правильные
4 размера кадров ICO это не нарушает (AC требует именно размеры, не резкость), но для
реального маленького логотипа итоговый 256×256-кадр будет менее чётким, чем мог бы.
Альтернатива (не реализована — доп. риск/сложность ради quality, не required by AC):
рендерить SVG ВТОРОЙ раз через `PNGSurface.convert(output_width=..., output_height=...)`
нативно на ~256px для ICO-ветки отдельно от остальных targets, с ручным сохранением
аспекта перед этим же canvas-letterbox шагом.

### Malformed SVG — permanent, не retry

Добавлена `_validate_svg_well_formed()`: `ET.fromstring()` до `try/except`, перехват
`ET.ParseError` → `ValueError("malformed SVG: input is not well-formed XML")`. Тот же
класс дефекта, что и CNV-128 (data-worker XML): `ParseError`/базовый `RuntimeError`
НЕ наследуют `ValueError` → `StreamConsumerBase.process_job()` относит их к transient →
бесконечный ретрай. Проверка стоит ДО `try:` в `_do_svg_convert()`, чтобы `ValueError`
не был проглочен существующим `except Exception: raise RuntimeError("SVG rasterization
failed")`. Побочный эффект: фикс закрывает эту же дыру и для СУЩЕСТВУЮЩИХ svg→png/jpg/
jpeg/webp targets (общая точка входа), не только для 4 новых — подтверждено отдельным
тестом `test_malformed_svg_fails_permanently_for_existing_targets_too`. Пользователь на
malformed SVG видит `ValueError: malformed SVG: input is not well-formed XML` (permanent,
job уходит в DLQ, не крутится вечно).

### Гейт

- `make TEST=1 test-python-image`: baseline 43 passed+1xfailed → **51 passed, 1 xfailed**
  (+8 новых тестов, 0 regressions).
- `make TEST=1 test-python` (все 6 воркеров, полный прогон): baseline 423/1xfail/2skip →
  data 116 + ffmpeg 77 + image **51+1xfailed** + libreoffice 60 + metrics 16 + ai 111/2skipped
  = **431 passed, 1 xfailed, 2 skipped, 0 failed**.
- `make formats-catalog`: 398 → **402** пары (+4: `svg→bmp`, `svg→gif`, `svg→ico`,
  `svg→tiff`). Diff `conversion_pairs.json`/`worker_capabilities.json` проверен —
  добавлены ТОЛЬКО эти 4 записи, ничего больше не сдвинулось.
- `make TEST=1 test-drift`: **28 passed** (после regen; до regen — красный, ожидаемо:
  `test_worker_matrix_subset_of_registry` + `test_catalog_matches_worker_capabilities`
  с диффом ровно на 4 новые записи).
- `make TEST=1 test-php`: baseline 949/5405 assertions → **949 passing, 5417 assertions**,
  12 deprecations (не менялось). Два ожидаемых падения ДО фикса зафиксированы и устранены:
  `FormatsCatalogIndependenceTest` (константа 398→402) и `ConversionRegistryGoldenTest`
  (`make dump-matrix-write` — новый таргет, регенерирует golden).
- **End-to-end routing подтверждён, не только `convert()`-уровень:** обновлённый golden
  `conversion_matrix.golden.txt` содержит `svg->bmp = image|image|0`, `svg->gif =
  image|image|0`, `svg->ico = image|image|0`, `svg->tiff = image|image|0` —
  `ConversionRegistry::streamFor()` реально резолвит все 4 новые пары в `category=image`,
  `stream=image` (→ `conv_image`), `isAi=0`. CNV-95 может полагаться на то, что бэкенд
  уже маршрутизирует эти пары в правильный KeyDB-стрим.

### Can-fail proof (мутация реального `worker.py` → красный → откат `cp` из
`/tmp/backup/convertor/backup_worker.py.cnv75-good` → снова зелёный, подтверждено `diff`)

**(a) GIF single-frame.** Мутация 1 (identical duplicate frame) НЕ покрасила тест —
Pillow схлопывает пиксельно-идентичные кадры (реальное поведение библиотеки, не баг
теста). Мутация 2 (`ImageChops.invert()` — визуально другой второй кадр) —
`AssertionError: assert 2 == 1` (`n_frames`). Правильная причина. Откат → 51/51 зелёные.

**(b) BMP no alpha.** Мутация: `_save_svg_bmp()` — убран весь композит-блок, голый
`image.save(..., "BMP")`. Два красных, оба на pixel-value (НЕ на `mode` — `mode`
неожиданно остался "RGB" даже без композита, Pillow сам режет alpha из файла):
- `test_svg_to_bmp_has_no_alpha_channel`: `AssertionError: assert (0,0,0) ==
  (255,255,255)` — бывший прозрачный угол стал чёрным, не белым фоном.
- `test_svg_to_bmp_uses_requested_background_color`: `assert (0 > 0)` — зелёный канал
  не преобладает (цвет не применился вообще).
Правильная причина. Откат → 51/51 зелёные.

**(c) TIFF single-page LZW.** Мутация: `_save_svg_tiff()` — убран `compression="tiff_lzw"`.
Красный: `AssertionError: assert 1 == 5` (`image.tag_v2[259]` — 1 = no compression,
не LZW). Правильная причина. Откат → 51/51 зелёные.

**(d) ICO frame sizes.** Мутация: `_save_svg_ico()` — убран 256-canvas/contain-fit,
голый `image.convert("RGBA").save(..., "ICO", sizes=[...])` на исходном 20×10 рендере.
Два красных:
- `test_svg_to_ico_has_expected_sizes_as_png_frames`: `AssertionError: assert set() ==
  {(16,16),(32,32),(48,48),(256,256)}` — Pillow молча отбросил ВСЕ 4 размера (больше
  источника).
- `test_svg_to_ico_ignores_width_height_options_by_design`: тот же symptom.
Правильная причина — подтверждает, что canvas-fit не декоративный, а обязательный.
Откат → 51/51 зелёные.

**(e) Malformed SVG → permanent, не retry.** Мутация: вызов `_validate_svg_well_formed()`
в `_do_svg_convert()` закомментирован. Два красных, оба с одинаковым правильным механизмом:
`RuntimeError: SVG rasterization failed` вместо ожидаемого `ValueError` (т.е. БЕЗ фикса
malformed SVG действительно попадает в transient-путь — тот же класс бага, что CNV-128).
- `test_malformed_svg_fails_permanently_not_retried`
- `test_malformed_svg_fails_permanently_for_existing_targets_too`
Откат → 51/51 зелёные, `diff` подтвердил файл идентичен бэкапу.

### Коммиты

- (см. `git log` — коммит сразу после этого лога, единый коммит worker+catalog+golden+тест).

### Известная накладка (нужно решение team-lead)

`_save_svg_ico()` игнорирует job `width`/`height` by design (см. выше) — открытый вопрос,
не блокер для CNV-95 (backend может просто не назначать эти опции профилю `svg→ico`,
или team-lead подтверждает текущее поведение как окончательное).

---

## Execution Log (worker, 2026-08-24, repair)

### Дефект теста, найденный ревью

`test_svg_to_ico_ignores_width_height_options_by_design` не тестировал заявленное:
ревьюер мутировал `_save_svg_ico()` (вызвал `_apply_image_options()` до canvas-шага —
именно ту утечку width/height, которую тест должен ловить), и тест остался зелёным.
Причина: `ImageOps.contain()` на фиксированный 256×256 canvas ренормализует итоговый
набор размеров кадров независимо от того, был ли pre-resize — ассерт на
`{(16,16),(32,32),(48,48),(256,256)}` этого не видит. Функционального бага нет —
`_save_svg_ico()` сегодня действительно НЕ применяет options; проблема только в
отсутствии оракула на регресс.

### Фикс

Тест переписан: конвертирует один и тот же SVG дважды — без `options` и с
`{"width": 5, "height": 5}` — и сравнивает БАЙТЫ итоговых ICO-файлов
(`assert bytes_with_options == bytes_no_options`). Утечка width/height меняет
контент, который попадает на canvas (другой pre-resized растр), поэтому итоговые
PNG-кадры расходятся побайтово, даже когда их *размеры* остаются 16/32/48/256.
Ассерт на набор размеров оставлен как sanity-дополнение (belt-and-braces), не как
единственный оракул. Determinism подтверждён эмпирически — два независимых прогона
конвертации с идентичным options на неизменённом коде дают идентичные байты (PNG/ICO
writer Pillow не пишет таймстемпы).

### Can-fail proof (мутация — точь-в-точь как у ревьюера, восстановление из
`/tmp/backup/convertor/backup_worker.py.cnv75-repair-good`, `diff` подтвердил
идентичность после отката)

Мутация: `_save_svg_ico()` получает параметр `options`, вызывает
`image = _apply_image_options(image, options)` ПЕРЕД `rgba = image.convert("RGBA")`;
вызов в `_do_svg_convert()` прокинут (`_save_svg_ico(image, out_path, options)`).

Красный, правильная причина — байты расходятся с индекса 14 (внутри
ICONDIRENTRY/PNG-данных, не в размерах):
```
assert bytes_with_options == bytes_no_options
At index 14 diff: b'\x89' != b'\x92'
```
Только этот тест упал (1 failed, 50 passed, 1 xfailed) — остальные 50 тестов
image-сюиты мутацию не заметили (ожидаемо: они не задают `options` для ico-ветки).
Откат `cp` из бэкапа → `diff` пустой → `make TEST=1 test-python-image` снова
51 passed, 1 xfailed.

### Гейт

- `make TEST=1 test-python-image`: **51 passed, 1 xfailed** (без изменений от
  baseline, после отката мутации).
- `make TEST=1 test-python` (все 6 воркеров, восстановленный `worker.py`):
  data 116 + ffmpeg 77 + image 51+1xfailed + libreoffice 60 + metrics 16 +
  ai 111+2skipped = **431 passed, 1 xfailed, 2 skipped, 0 failed** — совпадает с
  baseline из первого Execution Log, регрессий нет.
- `test-php`/`test-drift`/`test-e2e` НЕ гонялись — изменения ограничены одним
  Python-тестовым файлом, ни worker-код, ни catalog, ни golden не менялись.

### Область изменений

Только `workers/tests/test_image_worker_stream.py`. `workers/image/worker.py` не
менялся (после проверки мутацией восстановлен до состояния коммита, `git diff` пуст).

### Открытый вопрос (не снят этим repair)

Team-lead ack на «`_save_svg_ico()` игнорирует `width`/`height` by design» из первого
Execution Log — по-прежнему НЕ получен. Этот repair добавляет регресс-оракул на
поведение, но не является решением по вопросу; CNV-95 всё ещё нуждается в явном ack
или в решении не назначать `width`/`height` профилю `svg→ico`.
