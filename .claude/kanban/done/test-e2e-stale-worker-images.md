### `make test-e2e` гоняет стейл-образы воркеров (не билдит перед прогоном)

**Критичность:** Low (CI/DX-подвох, не рантайм-баг)

**TAGS:**
- test
- dx
- docker

**Описание:**
Таргет `make test-e2e` делает `up --force-recreate` контейнеров, но **не пересобирает** образы воркеров. Если исходники воркера менялись, а образ не пересобран (`make build-workers` / `build-<worker>`), e2e гоняет **старый код** внутри стейл-образа.

**Проблема:**
Найдено при s1-01 (single-JSON контракт): первый `make test-e2e` упал —
`test_worker_s3_roundtrip[video.3gp-3gp-mp4-…]` timeout, entry ушёл в `conv.dead`
с `reason: parse_error`, т.к. в running-образе `worker-ffmpeg` жил старый
double-decode `_parse_entry`. Data-кейс проходил только потому, что его образ
был пересобран ранее (`make test-python-data`). После `make build-ffmpeg build-data`
— `2 passed`. Итог: зелёный/красный e2e зависит от того, что случайно лежит в
образах, а не от текущего кода → ложные PASS.

**Задача (решение — build-workers как зависимость):**
- Сделать таргет `make test-e2e` зависимым от `build-workers` (prerequisite в Makefile), чтобы e2e всегда гонял свежий код. Docker layer-cache смягчает цену пересборки (пересобираются только изменённые слои).
- Проверить, что `build-workers` покрывает все 4 on-server образа (после s1-10 split — libreoffice/image/data/ffmpeg; ffmpeg-audio/-video используют один образ `worker-ffmpeg`).
- AC: `make test-e2e` на изменённом коде воркера падает/проходит по НОВОМУ коду, без ручного `make build-workers`.

**Decisions (2026-07-07):**
- **`test-e2e` зависит от `build-workers`** (correctness-first). Не отдельный `test-e2e-fresh` и не только-доки — ложные PASS из-за стейл-образов недопустимы, надёжность важнее скорости прогона.

**Связанная уборка (находка ревью bb5e944):**
- `test-e2e` (workers/Makefile ~L228) всё ещё делает inline `pip install --user pytest` на прогон,
  тогда как `test-python-*`/`test-gateway` уже переехали на пред-бейкнутые `:test`-образы
  (`[[workers-test-deps-requirements-file]]`). При правке этого таргета заодно перевести его на
  пред-бейкнутый deps-паттерн (или задокументировать, почему e2e-прогон против живого compose-стека
  требует иного подхода). Мелочь, но убирает последний островок ручного pip-списка.

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** done (gates partial — см. Execution Log).

## Execution Log (2026-07-07)

**Изменения:**
- `workers/Makefile`: `test-e2e: build-workers build-ffmpeg-test` — добавлен prerequisite;
  сервисы исправлены `worker-ffmpeg` → `worker-ffmpeg-audio worker-ffmpeg-video` (pre-existing bug после s1-10 split);
  inline `pip install pytest` заменён на `docker run … worker-ffmpeg:test` (pre-baked pattern).
- `workers/requirements-test.txt`: добавлен `boto3>=1.34` — e2e-тест импортирует `workers.common.s3`,
  boto3 убран из worker runtime при WS-transport миграции, но нужен test-runner'у.

**Part-2 решение:** Мигрировано на pre-baked `:test` image (`docker run --network xakki-convertor-network … worker-ffmpeg:test`).
Env-vars S3/Redis наследуются через Make `export` (`-e REDIS_PASSWORD -e S3_ENDPOINT` и др.).
`S3_PREFIX=test_` прописан явно (зеркалит `.env.test`).

**Gate `make docker-check`:** exit 0 ✓

**Gate `make -n test-e2e` (dry-run):** показывает правильный порядок —
libreoffice → ffmpeg → image → data → metrics-exporter → ffmpeg:test → compose up → docker run. ✓

**Gate `make test-e2e` (live):** 2 FAILED (timeout 120s) — **pre-existing issue, не от этого PR.**
Причина: на ветке `task/s1-ws-transport` воркеры — WS-клиенты, требуют `GATEWAY_WS_URL`.
В `.env` и `.env.test` `GATEWAY_WS_URL=` пуст, ws-gateway-сервиса нет в docker-compose.yml.
Новые контейнеры `worker-ffmpeg-audio/video/data` падают сразу с `GATEWAY_WS_URL пуст`.
Старый контейнер `xakki-convertor-worker-ffmpeg` (4 дня, старая XREADGROUP-архитектура) обрабатывает
видео-задачи, но не пишет `conv:status` в формате, который ожидает тест.
Итог: e2e-тест требует отдельного PR — добавить ws-gateway в compose + `GATEWAY_WS_URL` в `.env.test`.

**Sanity `make -n test-python` / `make -n test-gateway`:** pre-baked `:test` images без изменений. ✓

**Review (fc42fa5):** APPROVE. 5/5 проверок пройдены (Make-переменные/сеть/тег образа,
env-passthrough, покрытие build-workers, boto3, отсутствие регрессии). Нит (двойной
`build-ffmpeg` в транзитивном графе prereq) — безвреден, менять не нужно.

**Блокер live-green (вынесен отдельно):** реальный зелёный `make test-e2e` на ветке
`task/s1-ws-transport` невозможен, пока в compose-стек не добавлен ws-gateway +
`GATEWAY_WS_URL` — см. `[[e2e-ws-gateway-compose-stack]]` (grooming). Scope этой карточки
(prereq свежих образов + миграция на pre-baked deps) выполнен и подтверждён docker-check +
dry-run + ревью; финальный live-green проверяется в рамках ws-gateway-карточки.

**Status:** ready — review OK, docker-check зелён, live-green отложен до ws-gateway-стека.
