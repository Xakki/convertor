---
name: e2e-ws-transport-stack
description: >-
  Как поднять и отладить полный e2e для WS-worker-transport в convertor:
  `make test`/`make TEST=1 test-e2e`, изолированный ТЕСТ-СТЕНД (отдельный
  compose-проект xakki-convertor-test из .env.test), ws-gateway в docker-compose,
  app-symfony/.env.test, seed-подход (Conversion + XADD в conv.<type>), изоляция
  тест-БД convertor-test + KeyDB DB-index + S3 test_ префикс, тест-токены
  WORKER_API_TOKEN/GATEWAY_INTERNAL_TOKEN. Ключевые грабли: тест-таргеты требуют
  TEST=1 (иначе смотрят в dev), PHP читает REDIS_DSN/DATABASE_URL через Symfony
  Dotenv (не Docker-env), gateway УДАЛЯЕТ conv:status при XACK (двухфазный polling
  + DB-оракул), детерминированный S3 result-key, образы воркеров общие (IMAGE_NS).
  Триггеры: e2e падает/пустой, воркеры крашатся с GATEWAY_WS_URL пуст / close 1008,
  conv:status не появляется, тест не видит XADD, make up сломался после правки .env.
---

# E2E WS-transport stack (convertor)

Полный сквозной e2e транспортного слоя: **seed → gateway → WS → worker → Symfony
relay → S3**, наблюдаемый через `conv:status` (KeyDB) + DB-оракул. Один читатель
KeyDB Streams — `ws-gateway`; воркеры только WS-клиенты (см. проектный CLAUDE.md
«Queue Architecture»).

## Когда сюда

- Пишешь/чинишь `workers/tests/test_workers_e2e.py` или таргет `make test-e2e`.
- e2e пустой/красный, гоняет стейл-код, воркеры не подхватывают задачу.
- Воркеры крашатся: `GATEWAY_WS_URL пуст` или gateway рвёт WS `close 1008`.
- `conv:status` не появляется / тест не видит результат XADD.
- `make up` сломался после правки `.env`/`.env.test`/Makefile.

## Модель окружения (основа всего)

Корневой `Makefile` инклудит env-файлы в порядке `.env` → `.env.local` →
`.env.test` (последний — только при `TEST=1`) и экспортирует результат. Тест-стенд
включается **пере-входом в make**: `$(MAKE) TEST=1 <target>` — sub-make перечитывает
Makefile уже с `.env.test`. Присваивания в makefile сильнее унаследованного
окружения, поэтому `--env-file` / `env -u` / `unexport` НЕ нужны (были до 2026-07-30
и удалены вместе с `E2E_CLEAN_ENV`, `COMPOSE_TEST`, `docker/docker-compose.e2e.yml`,
`create-test-db.sh`, `test-db-setup`, `test-php-live`).

Тест-стенд — **отдельный compose-проект** `xakki-convertor-test`: свои контейнеры,
тома, сеть, порты 110xx, БД `convertor-test` (создаёт штатный `MARIADB_DATABASE`).
Dev-стенд при этом не трогается — оба живут параллельно, восстанавливать ничего
не нужно. Образы воркеров общие: `image:` в compose берёт `${IMAGE_NS}`, который
в `.env.test` НЕ переопределяется.

## Happy-path

1. Гонять **из корня проекта**, НЕ `make -C workers` (workers/Makefile — фрагмент,
   иначе пустой image-tag → `invalid reference format`).
2. `make test` = поднять тест-стенд (`up` + `migrate`) → PHPUnit → pytest воркеров →
   drift-guard. Отдельные таргеты — только с `TEST=1`:
   `make TEST=1 test-e2e` / `test-api-integration` / `test-gateway` / `test-php`.
   Без `TEST=1` они падают с явной подсказкой (guard `REQUIRE_TEST`).
3. `make test-down` — снести тест-стенд вместе с томами (тест-БД обнуляется).
4. Токены: dev — реальные из `.env.local`; тест — закоммиченные `test-worker-token`
   / `test-internal-token` в `.env.test` + `app-symfony/.env.test`.
5. Изоляция: БД `convertor-test`, KeyDB **DB-index 3**, S3-префикс `test_`.
   Артефакты — self-clean по TTL + teardown теста.

## ⚠ Главная грабля: тест-таргет без `TEST=1`

Без `TEST=1` `$(COMPOSE_PROJECT_NAME)`/`$(PHP_CONT)`/`$(REDIS_QUEUE_DB)` разворачиваются в
**dev**-значения → тест пошёл бы в dev-контейнеры и dev-БД. Поэтому у всех
стенд-зависимых тест-таргетов стоит guard `REQUIRE_TEST`, а `test_routing_drift.py`
берёт имя php-контейнера из **env** `COMPOSE_PROJECT_NAME` (не из файла `.env`).
Добавляешь новый тест-таргет, которому нужен стенд — добавь `$(REQUIRE_TEST)`
первой строкой рецепта.

## Остальные грабли и контракт → см. reference.md

Читать [reference.md](reference.md) при работе с самим тестом/контрактом — там:
- PHP читает `REDIS_DSN`/`DATABASE_URL` через **Symfony Dotenv (`app-symfony/.env.test`)**,
  НЕ Docker-env → изоляция KeyDB/БД для PHP идёт туда, не в compose env.
- gateway **УДАЛЯЕТ** `conv:status:{id}` при XACK (не пишет `completed`) →
  **двухфазный polling** (ждать появления → ждать исчезновения) + DB-оракул
  `conversions.status=='completed'`.
- Детерминированный S3 result-key `{S3_PREFIX}results/{Y}/{m-d}/{conv_id}.json`
  (зеркало PHP `ResultKeyBuilder`), считать **после** терминала (midnight boundary).
- Seed-контракт (поля Conversion + форма XADD-фрейма `conv.<type>`), автомиграция
  тест-БД, полный список env.
