### Image воркер — реализовать OCR (tesseract) + валидация растровой матрицы

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Image-воркер (`workers/image/worker.py`) — эталонный воркер: Streams-consumer (через `workers/common/stream_consumer.py`) + S3 I/O уже сделаны и **полностью прошиты в рантайме** (есть S3_*-env и сеть `default`). Содержит реальную растровую конвертацию через Pillow. Единственный реальный остаток scope — **OCR**: провалидировать растровую матрицу и реализовать OCR (tesseract) inline.

**Проблема:**
- **OCR не реализован нигде:** `pytesseract` стоит в requirements, но **никогда не импортируется**; image-воркер роутит OCR в ai-воркер (`conv.ai`). Нужно реализовать OCR inline в image-воркере и убрать роут в ai.
- Растровые конвертации Pillow не провалидированы против матрицы форматов `ROADMAP.md` (справочные данные).

**Влияние:**
OCR заявлен в матрице (jpg/png/pdf/tiff → txt/md/docx) и входит в MVP — без реализации в image-воркере функция не работает.

**Контекст (уже сделано в коде):**
- Streams-consumer + S3 I/O — уже подключены и прошиты в рантайме (S3_*-env + сеть `default`); старый Redis-LISTS транспорт удалён. Это эталонный воркер, runtime-wiring не требуется.

**Реальное состояние точки входа (выявлено при старте 2026-06-20):**
OCR-задача СЕЙЧАС НЕ ДОХОДИТ до воркера. `ConversionController` → `ConversionManager::createConversion` берёт `fromFormat` из расширения файла (`jpg`), а реестр знает только виртуальный ключ `jpg_ocr` → `isSupported("jpg","txt")=false`, загрузка отклоняется до диспатча. Виртуальные ключи `_ocr` (как и `_stt`/`_tts`) — «мертвы» (задокументированный deferred gap, `ConversionManager.php:114-117`). Поэтому scope расширен с «воркер-онли» до сквозного capability-роутинга + OCR-флага (решения пользователя 2026-06-20).

**Решение (расширенный scope — всё в одной карточке):**
1. **Capability-роутинг на бэке (PHP).** Реестр (`ConversionRegistry`) реорганизовать в явную per-worker карту подписок: воркер (stream-suffix) + `isAi` + список поддерживаемых `from→to`. Роутинг (`ConversionManager::routingKey`) выводится из неё — задача уходит в stream того воркера, что умеет конвертацию; **AI — в крайнем случае**. Существующие маршруты не меняются (покрыть тестами на отсутствие регрессий).
2. **OCR как обычная (не-AI) способность image-воркера.** Добавить прямые записи `jpg/png/tiff → txt/md/docx` (владелец image, `isAi=false`); убрать мёртвые `_ocr`-ключи для них. `isSupported(jpg,txt)=true` → роут `conv.image`. OCR-квота — **бесплатная** (`isAi=false`).
3. **Растровый OCR доходит e2e без правок UI:** загрузил jpg/png/tiff, выбрал txt/md/docx → `conv.image`. Воркер включает OCR-режим, когда вход растровый, а выход текстовый.
4. **Явный OCR-флаг (API + UI) для pdf.** `pdf → txt/md/docx` по умолчанию остаётся у document-воркера (извлечение текста). Для сканов — явный `ocr`-флаг в API (`ConversionController` + `createConversion` + сообщение, `subType=ocr`) и переключатель в UI; такой pdf роутится в `conv.image` (OCR).
5. **pdf-OCR в image-воркере** через рендер страниц `pdf2image` + poppler (`poppler-utils` в образе), затем tesseract.
6. **OCR-реализация в воркере** через `pytesseract` (rus+eng); txt = сырой текст, md = текст в markdown-обёртке, docx через `python-docx`.
7. Провалидировать растровые Pillow-конвертации против матрицы `ROADMAP.md` (jpg/png/gif/bmp/webp/tiff/ico/pdf).
8. SVG/HEIC/AVIF — отложены в Стадию 7 (MVP-deferred), в scope не входят.

**Контракт PHP↔воркер (для параллельной работы):**
- Растровый OCR: `sourceFormat=jpg|png|tiff`, `targetFormat=txt|md|docx`, `isAi=false`, `subType=ocr`, stream `conv.image`.
- pdf-OCR (по флагу): `sourceFormat=pdf`, `targetFormat=txt|md|docx`, `isAi=false`, `subType=ocr`, stream `conv.image`.
- Воркер включает OCR-режим по `targetFormat ∈ {txt,md,docx}` (для pdf — рендер через poppler).

**Критерии приёмки:**
- Реестр — явная per-worker capability-карта; роутинг выводится из неё; AI в крайнем случае; существующие маршруты не сломаны (PHPUnit).
- OCR реализован в image-воркере через `pytesseract`: `jpg/png/tiff → txt/md/docx` (e2e без UI) и `pdf → txt/md/docx` по явному OCR-флагу (poppler); роут в `conv.ai` для OCR удалён; `isAi=false`; покрыт тестами (unit с моком tesseract + integration на реальном tesseract, skip если бинарь отсутствует).
- Явный OCR-флаг прошит: `ConversionController` (API-параметр) + `createConversion` + UI-переключатель.
- Растровая матрица Pillow провалидирована против матрицы форматов `ROADMAP.md`.
- `pytest workers/tests` зелёный (включая существующий `test_image_worker_stream.py`); PHPUnit зелёный.
- `composer test:phpstan` / `composer test:cs-fix` чисто; `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Image-воркер — эталон: Streams + S3 уже сделаны и прошиты в рантайме; миграция/wiring здесь не требуется (снято из scope при ре-груминге 2026-06-20).
- **OCR: владелец — image-воркер, в MVP (Стадия 1)** (решение пользователя 2026-06-20). tesseract уже в его образе; роут в ai-воркер убирается.
- **OCR-квота — бесплатная (`isAi=false`)** (решение 2026-06-20): OCR = tesseract, не LLM; в ROADMAP AI-колонка пуста.
- **Роутинг — capability-driven** (решение 2026-06-20): per-worker конфиг подписок + поддерживаемых форматов; бэк шлёт в поддерживающий stream; AI в крайнем случае.
- **pdf→текст: document по умолчанию + явный OCR-флаг** (решение 2026-06-20). pdf-OCR через poppler/pdf2image. Флаг прошивается в API+UI в этой же карточке (решение «всё в одной карточке» 2026-06-20).
- SVG/HEIC/AVIF отложены в Стадию 7 (MVP-deferred).

**Execution Log (2026-06-20):**
- PHP: `ConversionRegistry` → per-worker capability-конфиг (`workerCapabilities()` + чистый `streamFor()`); golden-тест (`ConversionRegistryGoldenTest` + `bin/dump-matrix.php`) доказал сохранность маршрутов — единственный дифф = −12 `_ocr` / +9 растровых ключей. OCR-флаг прошит: `ConversionController` (`ocr`-параметр) → `createConversion(...,bool $ocr)` → persisted `Conversion::isOcr` (+ миграция `Version20260620000001`). OCR-квота бесплатная. `make test-php` 18 тестов зелёных; phpstan level 8 + cs чисто.
- Python: OCR inline в `workers/image/worker.py` — триггер по `targetFormat ∈ {txt,md,docx}`; растр через PIL+pytesseract, pdf через pdf2image(poppler)+pytesseract; txt/md/docx (python-docx). poppler-utils в образе. warn при пустом OCR. Хост pytest 78 passed/5 skipped; в контейнере на **реальном** tesseract+poppler png→txt/md/docx и pdf→txt (resume.pdf, 6 страниц) прошли.
- Растровая матрица сверена с ROADMAP — расхождений нет (svg/heic/avif корректно отложены).
- Ревью: **ship-with-fixes** → обе med-находки закрыты (реальный pdf-тест, warn на пустой OCR), minor (regen-сообщение golden) исправлен.

**Известные ограничения (вне scope этой карточки → требуют отдельных карточек):**
- UI OCR-чекбокс (`templates/conversion/_upload_form.html.twig`) создан, но **не подключён ни к одной странице** — upload-страницы пока нет. API-путь (`ocr`-параметр) полностью готов и покрыт тестами; визуально OCR недоступен, пока не появится upload UI.
- Миграция `Version20260620000001` (ADD `is_ocr`) не прогнана на живой БД (аддитивная, с дефолтом — низкий риск).
- `_stt`/`_tts` оставлены вне capability-конфига (дописаны отдельно, маршрут `conv.ai` сохранён) — AI-роутинг остаётся отложенным gap.

Siblings: [[validate-ffmpeg-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
