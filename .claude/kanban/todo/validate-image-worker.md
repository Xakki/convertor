### Image воркер — валидация stream-consumer/S3 + растровая матрица (OCR — open question)

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Image-воркер (`workers/image/worker.py`) — единственный, уже переведённый на KeyDB Streams (vertical slice через `workers/common/stream_consumer.py`). Содержит реальную растровую конвертацию через Pillow. Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (storage-input-to-s3, 2026-06-20). Карточка — про подтверждение работы на Streams/S3 и валидацию растровой матрицы, плюс фиксация нерешённого вопроса по OCR.

**Проблема:**
- Нужно подтвердить, что image-воркер по-прежнему корректно работает после перевода хранилища на S3 (вход/выход через `-inputs`/`-results`, не `/shared-files`).
- Растровые конвертации Pillow не провалидированы против матрицы `docs/plan.md`.
- **OCR не имеет владельца:** в image-Dockerfile установлены `tesseract` + `pytesseract`, но image-воркер маршрутизирует OCR в ai-воркер (`conv.ai`), а реализации OCR нет нигде. Конфликт ответственности.

**Влияние:**
Если image-воркер не валиден на S3 — ломается уже мигрированный slice. OCR заявлен в матрице (jpg/png/pdf/tiff → txt/md/docx), но фактически не реализуем без решения по владельцу.

**Решение:**
- Подтвердить, что stream-consumer + S3 I/O работают: вход из `-inputs`, результат в `-results`.
- Провалидировать растровые Pillow-конвертации против матрицы `docs/plan.md` (jpg/png/gif/bmp/webp/tiff/ico/pdf).
- Решить вопрос владельца OCR (см. Open questions) и зафиксировать решение.
- SVG/HEIC/AVIF — отложены (MVP-deferred), в scope не входят.

**Критерии приёмки:**
- Image-воркер подтверждённо работает на KeyDB Streams + S3 (вход из `-inputs`, результат в `-results`).
- Растровая матрица Pillow провалидирована против `docs/plan.md`.
- `pytest workers/tests` зелёный (включая существующий `test_image_worker_stream.py`).
- `make docker-check` проходит.
- Решение по владельцу OCR зафиксировано в Decisions (реализация OCR — в рамках выбранной карточки/воркера).

**Open questions:**
- **Владелец OCR не определён.** `tesseract` + `pytesseract` стоят в image-Dockerfile, но image-воркер роутит OCR в ai-воркер (`conv.ai`), реализации нет нигде. Выбрать: реализовать OCR inline в image-воркере (tesseract уже на месте) **или** в ai-воркере. От решения зависит, где появляется код OCR и куда уходит маршрут `conv.ai`.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Image-воркер — уже на stream-consumer (эталон миграции для остальных); миграция Streams здесь не требуется, только валидация на S3.
- SVG/HEIC/AVIF отложены как MVP-deferred.

Siblings: [[validate-ffmpeg-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
