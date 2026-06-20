### Ffmpeg воркер — миграция на stream-consumer + 3gp + валидация матрицы

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
Ffmpeg-воркер (`workers/ffmpeg/worker.py`) уже содержит реальную логику конвертации audio/video (не заглушка), но всё ещё читает задачи из Redis-LISTS, а не из KeyDB Streams. Образец миграции — image-воркер (vertical slice уже на `stream_consumer`). Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (задача storage-input-to-s3, 2026-06-20).

**Проблема:**
- Воркер не получает задачи в новой очередной модели: PHP-сторона пишет в KeyDB Streams, а ffmpeg-воркер всё ещё на Redis-LISTS — контракт рассинхронизирован.
- В `SUPPORTED` отсутствует `"3gp"` на входе, хотя маппинг 3gp→mp4 уже идёт через `libx264` — формат фактически конвертируется, но не объявлен поддерживаемым.
- Матрица audio/video из `docs/plan.md` не провалидирована end-to-end на реальном ffmpeg.

**Влияние:**
Без миграции воркер не работает в проде вообще (не видит задач). Без объявления 3gp и валидации — часть заявленных конвертаций молча не работает.

**Решение:**
- Перевести ffmpeg-воркер с Redis-LISTS на KeyDB Streams (`workers/common/stream_consumer.py`), по образцу image-воркера.
- Перевести I/O на S3: вход скачивать из `${S3_BUCKET_PREFIX}-inputs`, результат заливать в `-results` (через `workers/common/s3.py`).
- Добавить `"3gp"` в `SUPPORTED` в `workers/ffmpeg/worker.py` (вход 3gp → mp4 уже маппится на `libx264`).
- Провалидировать audio/video-конвертации против матрицы `docs/plan.md` (видео/аудио форматы).
- Покрыть **integration-тестом 3gp→mp4** (реальный ffmpeg, pytest-маркер `integration`). Фикстура готова: `workers/tests/example_files/video.3gp` (укорочена до 41KB, h263+aac, 3s).

**Критерии приёмки:**
- Воркер потребляет задачи из KeyDB Streams (stream-consumer подключён), Redis-LISTS больше не используется.
- I/O идёт через S3: вход из `-inputs`, результат в `-results`; `/shared-files` не используется.
- `"3gp"` добавлен в `SUPPORTED`; конвертация 3gp→mp4 работает на реальном ffmpeg.
- Integration-тест 3gp→mp4 (маркер `integration`) зелёный на реальном ffmpeg.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams делается внутри этой же карточки (не отдельной prereq-картой).
- Unit-тесты ffmpeg покрываются отдельно в [[worker-conversion-tests]]; здесь — миграция на Streams/S3 + integration 3gp→mp4 + валидация матрицы.

Siblings: [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
