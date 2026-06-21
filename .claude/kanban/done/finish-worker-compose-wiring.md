### Дотянуть compose-wiring воркеров ffmpeg/data/ai на S3 + egress

**Критичность:** Critical

**TAGS:**
- bug
- tech-debt

**Описание:**
Воркеры ffmpeg/data/ai уже мигрированы на KeyDB Streams + S3 в КОДЕ (через `workers/common/stream_consumer.py`: XREADGROUP, группа `convertor`, вход/выход в S3), но в `docker-compose.yml` миграция не доведена до конца. Только `worker-image` получил `S3_*`-env и egress-сеть `default`. Остальные воркеры остались на старой сетевой конфигурации.

**Проблема:**
- `worker-ffmpeg` / `worker-data` / `worker-ai` сидят на сети `backend` (`internal: true`, без NAT) и без `S3_*`-env → в рантайме НЕ достучатся до S3 → их конвертации сломаны.
- Compose-комментарий прямо говорит «Other workers get `default` too when migrated (Phase 4)» — недоделка миграции.
- Для `worker-ai` `internal: true` блокирует и первую загрузку whisper-модели с HuggingFace (egress наружу).

**Влияние:**
Все конвертации через ffmpeg/data/ai молча падают в рантайме — код мигрирован, но инфраструктура compose до S3 не дотягивается. Блокирует валидацию Стадии 1.

**Решение:**
- Дать `worker-ffmpeg` / `worker-data` / `worker-ai` те же `S3_*`-env + сеть `default`, что и у `worker-image`.
- Для `worker-ai` дополнительно учесть, что `internal: true` блокирует первую загрузку whisper-модели с HuggingFace — связать с [[validate-ai-worker]] (там pre-bake модели / egress-вопрос).

**Критерии приёмки:**
- `worker-ffmpeg` / `worker-data` / `worker-ai` имеют `S3_*`-env + сеть `default`.
- Реальная задача на каждый воркер проходит S3 in/out end-to-end.
- `make docker-check` проходит.
- Блокирует Стадию 1: [[validate-data-worker]], [[validate-ffmpeg-worker]].

**Decisions:**
- Выявлено при аудите кода + uncommitted working tree 2026-06-20: миграция Streams+S3 сделана в коде, но compose-wiring доведён только для image.

Siblings: [[validate-data-worker]] · [[validate-ffmpeg-worker]] · [[validate-ai-worker]]

**Execution Log:**
- Wiring (commit `7bb0a2b`): `worker-ffmpeg` / `worker-data` / `worker-ai` получили
  `S3_*`-env + `REDIS_PORT` + сеть `default` (паритет с `worker-image`). Комментарий
  worker-image «Phase 4» обновлён; worker-ai помечен egress для S3 + первого pull
  whisper-модели (pre-bake остаётся за [[validate-ai-worker]]). `make docker-check` ✓.
- `limits.yml` / `fluent-logging.yml` воркер-сетей не содержат → паритет там не нужен.
- Тестовое окружение (commit `01a1e29`, по образцу `proxy-service/.env.test`):
  `.env.test` (APP_ENV=test, живой S3 не шадоулится — e2e нужен реальный),
  `make test-e2e` (pytest внутри контейнера worker-ffmpeg на сетях default+backend),
  `workers/tests/test_workers_e2e.py` (поток: upload→S3 → XADD `conv.<stream>` →
  живой воркер → S3 results → assert + cleanup), фикстура `data.csv`, маркер `e2e`.
- **E2E прогон зелёный** (реальный S3 `apis3.xakki.ru`, бакеты `convertor-dev-*`):
  `ffmpeg 3gp→mp4` ✓ и `data csv→json` ✓ — `2 passed`. Дефолтный набор: `77 passed`.
- worker-ai: только wiring; полный whisper-E2E (audio→text, ~140MB модель) делегирован
  [[validate-ai-worker]] — он за `profiles:["ai"]` и вне дефолтного compose-набора.

**Критерии приёмки — статус:**
- ✅ `worker-ffmpeg`/`worker-data`/`worker-ai`: `S3_*`-env + сеть `default`.
- ✅ Реальная задача S3 in/out end-to-end: ffmpeg + data зелёные; ai — wiring (egress готов), прогон в [[validate-ai-worker]].
- ✅ `make docker-check` проходит.

**Rework (фидбек 2026-06-21, выравнивание под `proxy-service`):**
- `S3_PREFIX`-изоляция тест-объектов (как proxy `config.py prefix`): `stream_consumer`
  применяет `S3_PREFIX` (default `""` = прод no-op) к СВОИМ output-ключам; input-ключи
  не префиксуются (принадлежат продюсеру — как proxy `base.py`). Тест префиксует свои
  input-объекты сам + ассерт `output_key.startswith(S3_PREFIX)`.
- e2e-overlay `docker/docker-compose.e2e.yml` подмешивает `S3_PREFIX` в воркеры;
  `.env.test` добавляет overlay в `COMPOSE_FILE`; Makefile `unexport COMPOSE_FILE` +
  `COMPOSE_TEST=docker compose --env-file .env.test` (паттерн proxy).
- **Запуск НЕ от root** (фидбек): `--user $(PUID):$(PGID)` + `pip install --user`
  (было `--user root`). `test-e2e`: пересоздать воркеры с overlay → pytest →
  восстановить воркеры в base (rc сохраняется).
- Прогон: `2 passed` от uid 1000; восстановленные воркеры без `S3_PREFIX` (прод-safe);
  дефолт `77 passed`. Ревью rework: **APPROVE-WITH-NITS**.
