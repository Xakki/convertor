### Data воркер — миграция на stream-consumer + S3 + валидация матрицы данных

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
Data-воркер (`workers/data/worker.py`) уже содержит реальную логику конвертации csv/json/xml/yaml (не заглушка), но всё ещё читает задачи из Redis-LISTS, а не из KeyDB Streams. Образец миграции — image-воркер (на `stream_consumer`). Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (storage-input-to-s3, 2026-06-20).

**Проблема:**
- Воркер на Redis-LISTS — не получает задачи в новой очередной модели (PHP пишет в KeyDB Streams). Контракт рассинхронизирован.
- I/O ещё рассчитан на `/shared-files`, которого больше нет.
- Матрица данных (csv/json/xml/yaml) из `docs/plan.md` не провалидирована end-to-end на реальных датасетах через S3.

**Влияние:**
Без миграции воркер не работает в проде (не видит задач). Без валидации — заявленные конвертации данных могут не работать на реальных файлах.

**Решение:**
- Перевести data-воркер с Redis-LISTS на KeyDB Streams (`workers/common/stream_consumer.py`), по образцу image-воркера.
- Перевести I/O на S3: вход из `-inputs`, результат в `-results` (через `workers/common/s3.py`).
- Провалидировать матрицу csv/json/xml/yaml на реальных датасетах с S3 in/out.

**Критерии приёмки:**
- Воркер потребляет задачи из KeyDB Streams (stream-consumer подключён), Redis-LISTS больше не используется.
- I/O идёт через S3: вход из `-inputs`, результат в `-results`; `/shared-files` не используется.
- Матрица csv/json/xml/yaml провалидирована на реальных датасетах через S3.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Open questions:**
- **`toml` в матрице, но не в SUPPORTED.** Матрица `docs/plan.md` (строка 16) включает `toml` во входных форматах данных; текущий `SUPPORTED` воркера — только `csv/json/xml/yaml`. Решить: добавить поддержку `toml` или убрать его из матрицы.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams делается внутри этой же карточки (не отдельной prereq-картой).
- Unit-тесты data-воркера (полная матрица + round-trip + malformed) покрываются отдельно в [[worker-conversion-tests]]; здесь — часть про Streams/S3 + валидацию на реальных датасетах.

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
