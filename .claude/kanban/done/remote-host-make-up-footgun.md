### `make up`/`make down` на remote worker хосте валят полный стек и роняют все воркеры

**Criticality:** High

**TAGS:**
- tech-debt
- makefile
- remote-workers
- footgun

**Description:**
Верифицировано 2026-07-29 на хосте uBook (`COMPOSE_PROJECT_NAME=convertor-remote-xbook`). `make up`
пытается поднять ПОЛНЫЙ стек (php/mariadb/nginx/keydb/ws-gateway/metrics-exporter), потому что
`COMPOSE_FILE` в трекаемом `.env` — это compose полного стека. Падает с ошибкой `network common
declared as external, but could not be found` — внешняя сеть `common` существует только на главном
сервере (saFin).

Перед этим `make down` УЖЕ снёс все 6 воркеров → хост остался без единого работающего воркера до
ручного восстановления.

Корректная последовательность на remote-хосте — `make build-workers` + `make workers-recreate`
(задокументировано в `docs/workers-remote-deploy.md`, где явно сказано, что `docker network create
common` не нужен, т.к. на remote-хосте стартуют только 6 воркеров).

**Problem:**
`up`/`down` без явного предупреждения в help-тексте выглядят как безопасные универсальные команды,
но на remote worker хосте они (а) гарантированно падают на `up` из-за отсутствующей внешней сети
`common` и (б) на `down` реально останавливают и удаляют все работающие воркеры без какого-либо
подтверждения или заметного предупреждения.

**Impact:**
- Полный простой remote-воркеров до ручного восстановления (`make build-workers` +
  `make workers-recreate`).
- Усугубляющий фактор: воркеры uBook уже были `disconnected` в `worker_capabilities` 6 дней
  (с 2026-07-23) и это не вызвало алерта — отдельный повод завести liveness-алерт (см. ниже).
- Отдельно замечено: в `worker_capabilities` накопилось 8 устаревших строк с `host=NULL,
  status=disconnected` от до-`uBook`-префиксной схемы `instance_id` — без механизма очистки.

**Recommendation (кандидаты, без решения):**
a. Более явный `##` help-текст на `up`/`down`, предупреждающий про remote-хосты (уже применено —
   см. правку `Makefile` в этом же груминге, часть 3 связанной работы).
b. Явный fail-fast guard в `up`/`down` для remote-хостов (например, детект отсутствия сети `common`
   или отдельного маркера remote-конфигурации → отказ с понятной подсказкой вместо `docker compose
   up`/`down` вслепую).
c. Уборка устаревших `worker_capabilities` строк (8 pre-prefix orphan-записей) + алерт на
   `disconnected`-воркера дольше N часов (мотивация — 6-дневный простой uBook остался незамеченным).

**Acceptance Criteria:**
- Выбрано и реализовано направление (или комбинация) из recommendation.
- Если guard (b) — remote-хост даёт понятную ошибку до попытки поднять/уронить полный стек, а не
  generic `network ... could not be found`.
- Если cleanup+alert (c) — устаревшие orphan-строки убраны, алерт на затянувшийся `disconnected`
  заведён и протестирован.
- QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest.

**Контекст:** найдено 2026-07-29 на хосте uBook в ходе диагностики простоя воркеров.

**Update 2026-07-30 (рефакторинг Makefile/env): решено.** Серверная часть
(php/cron/mariadb/keydb/nginx/ws-gateway) убрана под compose-профиль `server`,
metrics-exporter — под `monitoring`. Remote worker-хост активирует только `ai`
(шаблон `.env.local_worker_example`, туда же переехали `COMPOSE_FILE` с
fluent-сабмодулем и `EXT_FLUENT_PORT`), поэтому `make up` там поднимает ровно
6 воркеров + fluent-bit + logrotate, а `make down` гасит только их. Проверено
симуляцией: `docker compose config --services` для worker-профиля даёт
`fluent-bit logrotate worker-ai worker-data worker-ffmpeg-audio
worker-ffmpeg-video worker-image worker-libreoffice`.

⚠ Остаточное действие: на uBook нужно пересоздать `.env.local` из нового
шаблона — со СТАРЫМ `.env.local` (без `COMPOSE_PROFILES`) `make up` по-прежнему
потянет полный стек и упадёт на внешней сети `common`.

**Decisions:**
- Закрыто как уже исправленное в коде (2026-08-01 / фикс 2026-07-30): footgun `make up`/`down`
  на remote закрыт compose-профилями (`server`/`monitoring` vs worker). Остаток — только ops
  (пересоздать `.env.local` на uBook из шаблона); отдельная инженерная карточка не нужна.

**Status:** done.
