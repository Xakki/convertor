# E2E WS-transport — контракт и детали

Дополнение к [SKILL.md](SKILL.md). Здесь то, что нужно при работе с самим тестом и
транспортным контрактом. Все имена/пути — verbatim, проверять в коде перед правкой.

## Состав e2e-стека

Полный прод-стек (решение по груму e2e-ws-gateway-compose-stack): `php` + `nginx` +
`mariadb` + `keydb` + `minio` + `ws-gateway` + on-server воркеры (`worker-libreoffice`,
`worker-ffmpeg-audio`, `worker-ffmpeg-video`, `worker-image`, `worker-data`). Задачу в
e2e создаём **сидом**, не user-upload/auth (транспорт не требует user-JWT — worker-facing
путь на статичных bearer-токенах).

## Grабля №2: PHP читает REDIS_DSN / DATABASE_URL через Symfony Dotenv, не Docker-env

`docker-compose.yml` НАМЕРЕННО не инжектит `REDIS_DSN`/`DATABASE_URL` в контейнер `php`
(в env только отдельные `DB_*`). PHP при `APP_ENV=test` грузит `app-symfony/.env.test`
через Symfony Dotenv, и тамошние **литеральные** `REDIS_DSN`/`DATABASE_URL` побеждают
(в реальном env контейнера их нет — нечего шэдоуить).

Следствие: изоляция для PHP-стороны идёт в `app-symfony/.env.test`, НЕ в compose:
```
REDIS_DSN=redis://keydb:6379?dbindex=3
DATABASE_URL="mysql://convertor-test:123456@mariadb:3306/convertor-test?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
```
А для воркеров/gateway (они читают Docker-env) — `REDIS_DB=3`/`REDIS_QUEUE_DB=3` в `.env.test`.

## Grабля №3: gateway УДАЛЯЕТ conv:status при XACK

Терминально gateway делает `DEL conv:status:{convId}` (не пишет `state=completed`).
Поэтому тест — **двухфазный**:
1. Ждать появления ключа (`state=processing`) — задачу подхватили.
2. Ждать **исчезновения** ключа — gateway отрелеил + XACK'нул.
3. Финальный оракул — SQL: `conversions.status == 'completed'` в тест-БД.

## Grабля №4: детерминированный S3 result-key (midnight boundary)

Result-key НЕ лежит в KeyDB для чтения — предсказывается зеркалом PHP `ResultKeyBuilder::build`:
```
{S3_PREFIX}results/{Y}/{m-d}/{conv_id}.json
```
PHP строит дату в момент **persist'а** (relay, +30–90s от seed). Поэтому в тесте считать
ключ **после** `_wait_terminal` (не при seed), иначе пересечение полуночи UTC → неверный
ключ → `NoSuchKey`. И `output_key` присваивать **до** DB-assert, чтобы `finally` убрал
объект даже при провале проверки.

## Seed-контракт

- Вставить строку в `conversions` (тест-БД) с корректными source/target форматами +
  S3-input-ключом + статусом, который пайплайн ждёт на dispatch. Поля — сверять с
  `src/Entity/Conversion.php`.
- Залить input-фикстуру в S3 inputs-бакет под `test_` префиксом.
- `XADD` в стрим `conv.<type>` (KeyDB DB 3) — форма фрейма ровно как у Symfony Messenger
  dispatch (поля `conversionId`, `sourceFormat`, `targetFormat`, `inputKey`, флаги).
  Маппинг type→стрим — `streamFor()`.
- Тест-раннер (`docker run`) обязан иметь `-e REDIS_DB=3` — иначе XADD уйдёт в DB 0,
  gateway на DB 3 не увидит.

## Symfony relay-эндпоинты (готовы, воркер их дёргает)

- `WorkerController` (`/api/v1/worker`, статичный bearer `WORKER_API_TOKEN`):
  `GET /jobs/{id}/input` (стрим из S3), `POST /jobs/{id}/result` (large multipart).
- `InternalWorkerController` (`/api/v1/internal/worker`, firewall `internal_api`, bearer
  `GATEWAY_INTERNAL_TOKEN`): `POST /result` (inline small от gateway), `POST /fail`.
  XACK делает gateway, не эти эндпоинты.

## Токены и env-раскладка

| Переменная | dev (`make up`) | e2e (`make test-e2e`) |
|---|---|---|
| `WORKER_API_TOKEN` | `.env.local` (реальный), экспортится | `.env.test` `test-worker-token` (через `env -u`+`--env-file`) |
| `GATEWAY_INTERNAL_TOKEN` | `.env.local` | `.env.test` `test-internal-token` |
| `API_BASE_URL` | `.env.local` (публичный) | overlay `docker-compose.e2e.yml` → `http://nginx` (литерал per-worker) |
| `GATEWAY_WS_URL` | `.env` = `ws://ws-gateway:8091/ws/worker/` | то же |
| `SYMFONY_INTERNAL_URL` | `.env` = `http://nginx` (gateway→relay) | то же |
| `REDIS_DB`/`REDIS_QUEUE_DB` | из `.env` (2) | `.env.test` = 3 |
| `APP_ENV` | `dev` (в `.env`) | `test` (в `.env.test`; глоб. `unexport APP_ENV`) |

Токены обязаны быть **непустыми и согласованными** у gateway + всех воркеров + PHP,
иначе gateway рвёт WS `close 1008` / relay 401. Тест-токены в tracked — не прод-секреты,
а фикстуры изолированного стека (ратифицировано); прод-секреты — только `.env.local`.

## Overlay docker-compose.e2e.yml

Ставит `API_BASE_URL: http://nginx` **литералом** каждому воркеру. Должен быть **последним**
в цепочке `COMPOSE_FILE` (в `.env.test`), чтобы победить базовый `API_BASE_URL: ${API_BASE_URL}`.
ws-gateway использует `SYMFONY_INTERNAL_URL` (не `API_BASE_URL`) → overlay его не трогает.

## Автомиграция тест-БД

`convertor-test` создаётся dev init-скриптом `docker/mariadb/dev/init/create-test-db.sh`
ПУСТОЙ (без таблиц). Таргет `test-e2e` после `--wait` (php healthy) гонит:
```
docker exec -e DATABASE_URL="mysql://convertor-test:123456@mariadb:3306/convertor-test?..." \
    $(PHP_CONT) php bin/console doctrine:migrations:migrate --no-interaction
```
Идемпотентно (Doctrine трекает применённые). Пароль тест-БД — `DB_TEST_PASS` в `.env.test`
(единый источник для Makefile-стороны; `app-symfony/.env.test` держит свой литерал в DSN).

## Связанные карточки

`e2e-ws-gateway-compose-stack`, `test-e2e-stale-worker-images`, `ws-onserver-compose-wiring`,
эпик `s1-ws-worker-transport`.
