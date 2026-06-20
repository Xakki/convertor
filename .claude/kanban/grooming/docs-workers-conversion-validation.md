### Эпик: валидация и допил воркеров конвертации (umbrella)

**Критичность:** High

**TAGS:**
- feature
- epic

**Описание:**
Зонтичная карточка для приведения воркеров конвертации к рабочему состоянию end-to-end. Актуальное состояние (по результатам разведки 2026-06-20): воркеры **в основном реализованы**, а не заглушки — ffmpeg/image/data/ai/libreoffice содержат реальную логику конвертации. Эпик теперь = **валидация end-to-end + per-worker миграция на Streams/S3 + добивание пробелов**, а не «написать конвертацию с нуля».

Ключевые факты текущего состояния:
- Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`); общий том `/shared-files` удалён (задача storage-input-to-s3, 2026-06-20). Все воркеры работают с S3 in/out.
- Только **image-воркер** мигрирован на KeyDB Streams (vertical slice, эталон). ffmpeg/data/ai всё ещё на Redis-LISTS. **LibreOffice** — вообще HTTP-прокси, не consumer очереди.
- ffmpeg-воркер не объявляет `3gp` в `SUPPORTED` (хотя 3gp→mp4 уже маппится на `libx264`).
- OCR не имеет владельца: tesseract/pytesseract стоят в image-Dockerfile, но роут идёт в ai-воркер (`conv.ai`), реализации нет.
- Все `docker compose` команды — только через Makefile-таргеты (`make up`, `make docker-check`, …), не напрямую.

**Влияние:**
Ширина продукта (матрица форматов в `docs/plan.md`) не подтверждена; часть заявленных конвертаций может молча не работать. Воркеры на Redis-LISTS не получают задачи в новой очередной модели (PHP пишет в Streams).

**Решение:**
Раздробить эпик per-worker. Каждая subcard валидирует свой воркер против матрицы `docs/plan.md` и (где нужно) мигрирует его с Redis-LISTS на KeyDB Streams и на S3 in/out. Зонтичная карточка трекает прогресс и держит сквозные решения.

**Критерии приёмки (на уровне эпика):**
- Все 5 subcard'ов закрыты.
- Каждый воркер потребляет задачи из KeyDB Streams (где применимо) и работает с S3 in/out.
- Матрица форматов `docs/plan.md` (за вычетом MVP-deferred) провалидирована end-to-end.
- `pytest workers/tests` зелёный; `make docker-check` проходит.

**Subcards:**
- [[validate-ffmpeg-worker]] — миграция на Streams, добавить `3gp`, валидация audio/video, integration 3gp→mp4.
- [[validate-image-worker]] — валидация stream-consumer/S3 + растровая матрица; open question по владельцу OCR.
- [[validate-data-worker]] — миграция на Streams + S3 + валидация csv/json/xml/yaml на реальных датасетах.
- [[validate-libreoffice-worker]] — решить модель (Streams-consumer vs HTTP) + S3 + валидация doc/pdf/markup.
- [[validate-ai-worker]] — гибридный backend (внешний default + local fallback), фикс egress Whisper, миграция на Streams, квоты, STT/TTS.

**MVP-deferred (вне scope сейчас, трекаются в [[post-mvp-conversion-formats]] — backlog-карточка, заведена по просьбе пользователя 2026-06-20):**
- Архивы (zip/tar/gz/bz2/7z).
- CAD/DWG.
- Доп. форматы изображений (SVG/HEIC/AVIF).
- Разметка rst/latex/wiki.
- MarkItDown (doc→markdown).

**Decisions (зафиксировано с пользователем 2026-06-20):**
- **Split per-worker.** Эпик становится umbrella/tracking-карточкой; вся работа — в 5 per-worker subcard'ах выше.
- **Миграция Redis-LISTS → KeyDB Streams (stream-consumer)** делается ВНУТРИ карточки соответствующего воркера, не отдельной prereq-картой.
- **AI backend = гибрид.** Внешние провайдеры (OpenAI/Gemini/Claude + g4f через `aip.xakki.ru`, см. [[add-open-ai]]) — по умолчанию; local whisper/espeak — fallback. Локальному fallback всё ещё нужен фикс egress модели Whisper (pre-bake в образ ИЛИ контролируемый egress на сети `backend` `internal:true`).
- **MVP-deferred** (см. секцию выше) — вне scope сейчас, но теперь трекаются в backlog-карточке [[post-mvp-conversion-formats]] (заведена по просьбе пользователя 2026-06-20): архивы, CAD/DWG, доп. форматы изображений (SVG/HEIC/AVIF), разметка rst/latex/wiki, MarkItDown.
- Docs→markdown остаётся на **pandoc**; MarkItDown отложен (зафиксировано в [[add-open-ai]]).
- Связанная карточка unit-тестов: [[worker-conversion-tests]].
