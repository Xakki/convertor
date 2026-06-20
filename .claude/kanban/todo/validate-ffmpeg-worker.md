### Ffmpeg воркер — добавить 3gp + integration 3gp→mp4 + валидация матрицы

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Ffmpeg-воркер (`workers/ffmpeg/worker.py`) уже Streams-consumer (через `workers/common/stream_consumer.py`: XREADGROUP, группа `convertor`, стрим `conv.<routing_key>`, вход из S3 `{prefix}-inputs`, результат в `{prefix}-results`) и содержит реальную логику конвертации audio/video. Перевод на Streams + S3 уже сделан в коде. Осталось: объявить `3gp`, покрыть integration-тестом 3gp→mp4 и провалидировать audio/video-матрицу.

**Проблема:**
- В `SUPPORTED` отсутствует `"3gp"` — его нет НИ на входе, НИ на выходе, хотя маппинг 3gp→mp4 уже идёт через `libx264` — формат фактически конвертируется, но не объявлен поддерживаемым.
- Матрица audio/video из матрицы форматов `ROADMAP.md` (справочные данные) не провалидирована end-to-end на реальном ffmpeg.

**Влияние:**
Без объявления 3gp и валидации — часть заявленных конвертаций молча не работает.

**Контекст (уже сделано в коде):**
- Streams-consumer + S3 I/O — уже подключены (`stream_consumer.py`); старый Redis-LISTS транспорт и `base_worker.py`/`keydb_client.py` удалены. Это done-контекст, не задача.
- `test_ffmpeg_worker.py` уже существует (run_ffmpeg замокан) — нужен ещё integration-тест на реальном ffmpeg.

**Решение:**
- Добавить `"3gp"` в `SUPPORTED` в `workers/ffmpeg/worker.py` (вход 3gp → mp4 уже маппится на `libx264`).
- Провалидировать audio/video-конвертации против матрицы форматов `ROADMAP.md` (категории «Видео» / «Аудио»).
- Покрыть **integration-тестом 3gp→mp4** (реальный ffmpeg, pytest-маркер `integration`). Фикстура готова: `workers/tests/example_files/video.3gp` (41KB, h263+aac, 3s).

**Зависимости:**
- Runtime-валидация через S3 блокируется [[finish-worker-compose-wiring]]: `worker-ffmpeg` сейчас на сети `backend` (`internal:true`, без NAT) и без S3_*-env — в коде мигрирован, но до S3 в рантайме не дотягивается.

**Критерии приёмки:**
- `"3gp"` добавлен в `SUPPORTED`; конвертация 3gp→mp4 работает на реальном ffmpeg.
- Integration-тест 3gp→mp4 (маркер `integration`) зелёный на реальном ffmpeg.
- audio/video-матрица провалидирована против `ROADMAP.md`.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams + S3 — **уже сделана в коде** (снято из scope при ре-груминге 2026-06-20).
- Unit-тесты ffmpeg покрываются отдельно в [[worker-conversion-tests]]; здесь — 3gp + integration 3gp→mp4 + валидация матрицы.

Siblings: [[validate-image-worker]] · [[validate-data-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
