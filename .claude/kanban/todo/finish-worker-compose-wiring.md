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
