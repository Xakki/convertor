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

**Решение:**
- **Реализовать OCR в image-воркере** через `pytesseract` (jpg/png/pdf/tiff → txt/md/docx), убрать роут OCR в `conv.ai`.
- Провалидировать растровые Pillow-конвертации против матрицы форматов `ROADMAP.md` (jpg/png/gif/bmp/webp/tiff/ico/pdf).
- SVG/HEIC/AVIF — отложены в Стадию 7 (MVP-deferred), в scope не входят.

**Критерии приёмки:**
- **OCR реализован в image-воркере** через `pytesseract` (jpg/png/pdf/tiff → txt/md/docx); роут в `conv.ai` удалён; покрыт тестом.
- Растровая матрица Pillow провалидирована против матрицы форматов `ROADMAP.md`.
- `pytest workers/tests` зелёный (включая существующий `test_image_worker_stream.py`).
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Image-воркер — эталон: Streams + S3 уже сделаны и прошиты в рантайме; миграция/wiring здесь не требуется (снято из scope при ре-груминге 2026-06-20).
- **OCR: владелец — image-воркер, в MVP (Стадия 1)** (решение пользователя 2026-06-20). tesseract уже в его образе; роут в ai-воркер убирается.
- SVG/HEIC/AVIF отложены в Стадию 7 (MVP-deferred).

Siblings: [[validate-ffmpeg-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
