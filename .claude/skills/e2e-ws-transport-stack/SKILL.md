---
name: e2e-ws-transport-stack
description: >-
  Как поднять и отладить полный e2e для WS-worker-transport в convertor:
  `make test-e2e`, ws-gateway в docker-compose, .env.test / app-symfony/.env.test,
  docker-compose.e2e.yml, seed-подход (Conversion + XADD в conv.<type>), изоляция
  тест-БД convertor-test + KeyDB DB-index + S3 test_ префикс, тест-токены
  WORKER_API_TOKEN/GATEWAY_INTERNAL_TOKEN. Ключевые грабли: docker compose
  precedence (shell-env > --env-file → env -u, не глобальный unexport, иначе
  ломается make up), PHP читает REDIS_DSN/DATABASE_URL через Symfony Dotenv (не
  Docker-env), gateway УДАЛЯЕТ conv:status при XACK (двухфазный polling + DB-оракул),
  детерминированный S3 result-key. Триггеры: e2e падает/пустой, воркеры крашатся с
  GATEWAY_WS_URL пуст / close 1008, conv:status не появляется, тест не видит XADD,
  make up сломался после правки .env.
---

# E2E WS-transport stack (convertor)

Полный сквозной e2e транспортного слоя: **seed → gateway → WS → worker → Symfony
relay → S3**, наблюдаемый через `conv:status` (KeyDB) + DB-оракул. Один читатель
KeyDB Streams — `ws-gateway`; воркеры только WS-клиенты (см. проектный CLAUDE.md
«Queue Architecture»).

## Когда сюда

- Пишешь/чинишь `workers/tests/test_workers_e2e.py` или таргет `make test-e2e`.
- `make test-e2e` пустой/красный, e2e гоняет стейл-код, воркеры не подхватывают задачу.
- Воркеры крашатся: `GATEWAY_WS_URL пуст` или gateway рвёт WS `close 1008`.
- `conv:status` не появляется / тест не видит результат XADD.
- **`make up` (dev) сломался после правки `.env`/`.env.test`/Makefile** ← почти всегда
  грабля precedence ниже.

## Happy-path

1. Гейты гонять **из корня проекта** (`make test-e2e`), НЕ `make -C workers` — workers/Makefile
   включается в корневой (иначе пустой image-tag → `invalid reference format`).
2. `make test-e2e` (после Фазы e2e-ws-gateway): билдит свежие образы (`build-workers`
   prereq) → поднимает `php nginx ws-gateway worker-*` через `$(COMPOSE_TEST)` с
   `--env-file .env.test` → мигрирует схему тест-БД → гоняет pytest-контейнер → restore.
3. Токены для dev — в `.env.local` (непустые); для e2e — закоммиченные тест-значения
   `test-worker-token`/`test-internal-token` в `.env.test` + `app-symfony/.env.test`.
4. Изоляция: тест-БД `convertor-test` (dev init-скрипт), KeyDB **DB-index 3**, S3 `test_`
   префикс. Артефакты — self-clean по TTL + teardown теста.

## ⚠ Грабля №1 (главная): docker compose precedence ломает `make up`

Корневой `Makefile` делает `include .env` + `-include .env.local` + `export` → **все**
переменные уходят в shell-env рецептов. В docker compose **shell-env > `--env-file`**.
Значит для e2e, чтобы `.env.test` победил, переменную надо **убрать из shell-env только
для e2e-команды** — `env -u VAR ...`, а НЕ глобальный `unexport VAR`.

**Глобальный `unexport WORKER_API_TOKEN` (и т.п.) ломает dev `make up`:** реальное значение
в `.env.local`, в `.env` пусто → без экспорта воркеры получают пустой токен → `close 1008`.

Правильно (закреплено в Makefile):
```make
E2E_CLEAN_ENV = env -u WORKER_API_TOKEN -u GATEWAY_INTERNAL_TOKEN -u API_BASE_URL
# в test-e2e ТОЛЬКО перед stack-up:
$(E2E_CLEAN_ENV) $(COMPOSE_TEST) up -d --wait php nginx ws-gateway worker-...
# restore-строка — БЕЗ префикса (dev берёт .env.local):
$(DC) up -d --force-recreate --no-deps php nginx ws-gateway worker-...
```
Глобально `unexport` оставляем только `COMPOSE_FILE` (env-file подставляет свой) и
`APP_ENV` (в `.env` есть `=dev`, dev не ломается). `API_BASE_URL` для e2e-воркеров
даёт overlay литералом (`http://nginx`) → достаточно `env -u`, глобальный unexport не нужен.

## Остальные грабли и контракт → см. reference.md

Читать [reference.md](reference.md) при работе с самим тестом/контрактом — там:
- PHP читает `REDIS_DSN`/`DATABASE_URL` через **Symfony Dotenv (`app-symfony/.env.test`)**,
  НЕ Docker-env → изоляция KeyDB/БД для PHP идёт туда, не в compose env.
- gateway **УДАЛЯЕТ** `conv:status:{id}` при XACK (не пишет `completed`) →
  **двухфазный polling** (ждать появления → ждать исчезновения) + DB-оракул
  `conversions.status=='completed'`.
- Детерминированный S3 result-key `{S3_PREFIX}results/{Y}/{m-d}/{conv_id}.json`
  (зеркало PHP `ResultKeyBuilder`), считать **после** терминала (midnight boundary).
- Seed-контракт (поля Conversion + форма XADD-фрейма `conv.<type>`), overlay
  `docker-compose.e2e.yml`, автомиграция тест-БД, полный список env.
