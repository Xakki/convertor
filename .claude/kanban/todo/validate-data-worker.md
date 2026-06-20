### Data воркер — добавить toml + валидация матрицы данных на S3

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Data-воркер (`workers/data/worker.py`) уже Streams-consumer (через `workers/common/stream_consumer.py`: XREADGROUP, группа `convertor`, стрим `conv.<routing_key>`, вход из S3 `{prefix}-inputs`, результат в `{prefix}-results`) и содержит реальную логику конвертации csv/json/xml/yaml. Перевод на Streams + S3 уже сделан в коде. Осталось: добавить `toml` и провалидировать матрицу данных end-to-end на реальных датасетах через S3.

**Проблема:**
- `toml` НЕ реализован: в коде фигурирует только как намеренно-неподдерживаемый кейс в тесте. В `SUPPORTED` отсутствует и на вход, и на выход.
- Матрица данных (csv/json/xml/yaml/toml) из матрицы форматов `ROADMAP.md` (справочные данные) не провалидирована end-to-end на реальных датасетах через S3.

**Влияние:**
Без `toml` заявленный формат не работает. Без валидации — конвертации данных могут не работать на реальных файлах.

**Контекст (уже сделано в коде):**
- Streams-consumer + S3 I/O — уже подключены (`stream_consumer.py`); старый Redis-LISTS транспорт и `base_worker.py`/`keydb_client.py` удалены. Это done-контекст, не задача.

**Решение:**
- **Добавить `toml` в `SUPPORTED`** (вход+выход) наравне с csv/json/xml/yaml: парсинг `tomllib` (stdlib 3.11+), запись `tomli-w`.
- Провалидировать матрицу csv/json/xml/yaml/toml на реальных датасетах с S3 in/out.

**Зависимости:**
- Runtime-валидация через S3 блокируется [[finish-worker-compose-wiring]]: `worker-data` сейчас на сети `backend` (`internal:true`, без NAT) и без S3_*-env — в коде мигрирован, но до S3 в рантайме не дотягивается.

**Критерии приёмки:**
- `toml` поддержан на вход и выход; матрица csv/json/xml/yaml/toml провалидирована на реальных датасетах через S3.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- Миграция Redis-LISTS → KeyDB Streams + S3 — **уже сделана в коде** (снято из scope при ре-груминге 2026-06-20).
- **`toml` — добавить полную поддержку (вход+выход)** (решение пользователя 2026-06-20); входит в MVP (Стадия 1).
- Unit-тесты data-воркера (полная матрица + round-trip + malformed) покрываются отдельно в [[worker-conversion-tests]]; здесь — `toml` + валидация на реальных датасетах.

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-libreoffice-worker]] · [[validate-ai-worker]]
