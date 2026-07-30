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
А для воркеров/gateway (они читают Docker-env `REDIS_DB`) — `REDIS_QUEUE_DB=3` в `.env.test`.

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

| Переменная | dev (`make up`) | тест-стенд (`make test`, TEST=1) |
|---|---|---|
| `WORKER_API_TOKEN` | `.env.local` (реальный) | `.env.test` `test-worker-token` |
| `GATEWAY_INTERNAL_TOKEN` | `.env.local` | `.env.test` `test-internal-token` |
| `API_BASE_URL` | `.env` (публичный) | `.env.test` → `http://nginx` (внутри сети стенда) |
| `GATEWAY_WS_URL` | `.env` = `ws://ws-gateway:8091/ws/worker/` | то же |
| `SYMFONY_INTERNAL_URL` | `.env` = `http://nginx` (gateway→relay) | то же |
| `REDIS_QUEUE_DB` (в контейнере `REDIS_DB`) | `.env` = 2 | `.env.test` = 3 |
| `APP_ENV` | `dev` (в `.env`) | `test` (в `.env.test`) |

Токены обязаны быть **непустыми и согласованными** у gateway + всех воркеров + PHP,
иначе gateway рвёт WS `close 1008` / relay 401. Тест-токены в tracked — не прод-секреты,
а фикстуры изолированного стека (ратифицировано); прод-секреты — только `.env.local`.

## Тест-БД

`convertor-test` создаёт **штатный entrypoint mariadb** тест-стенда: `.env.test` задаёт
`MARIADB_DATABASE`/`MARIADB_USER`/`MARIADB_PASSWORD` = `convertor-test`/`convertor-test`/`123456`
— ровно то, что захардкожено в `DATABASE_URL` внутри `app-symfony/.env.test` (менять
парой). Отдельный init-скрипт с GRANT'ами больше не нужен.

Схему накатывает `make test-up` (`up` + `migrate`, идемпотентно — Doctrine трекает
применённые); `composer deploy` в php-контейнере мигрирует и сам при старте.

## Связанные карточки

`e2e-ws-gateway-compose-stack`, `test-e2e-stale-worker-images`, `ws-onserver-compose-wiring`,
эпик `s1-ws-worker-transport`.
