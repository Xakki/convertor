### e2e-прогон не проходит зелёным на WS-ветке — нет ws-gateway в compose-стеке

**Критичность:** Medium (блокирует реальный зелёный `make test-e2e` на ветке `task/s1-ws-transport`)

**TAGS:**
- test
- e2e
- docker
- ws-transport

**Описание:**
После WS-transport миграции все воркеры — WS-клиенты gateway: при старте требуют
`GATEWAY_WS_URL` и живой ws-gateway-сервис (единственный читатель KeyDB Streams).
Но в `docker-compose.yml` **сервиса ws-gateway нет**, а в `.env` / `.env.test`
`GATEWAY_WS_URL=` **пуст**. Поэтому:
- новые контейнеры `worker-ffmpeg-audio/video`, `worker-data` при `make test-e2e`
  падают сразу с «GATEWAY_WS_URL пуст» — задачу обрабатывать некому;
- зелёным проходил только старый долгоживущий контейнер `xakki-convertor-worker-ffmpeg`
  (4-дневный, старая XREADGROUP-архитектура), который не пишет `conv:status` в новом
  формате → тест e2e всё равно timeout'ит.

Итог: **`make test-e2e` на этой ветке структурно не может стать зелёным**, пока в
compose-стек не добавлен ws-gateway и не проброшен `GATEWAY_WS_URL`. Найдено при
реализации `[[test-e2e-stale-worker-images]]` (сама правка prereq/deps — корректна,
но упёрлась в этот пробел).

**Что, вероятно, нужно (уточнить при groom):**
- Добавить сервис `ws-gateway` в `docker-compose.yml` (образ из `workers/gateway/`),
  сеть `common`/`<proj>-network`, зависимость от KeyDB.
- Проставить `GATEWAY_WS_URL` (внутренний, напр. `ws://ws-gateway:PORT/...`) в
  `.env.test` (и решить, нужен ли он в основном `.env`).
- Проверить, нужен ли e2e-тесту также Symfony API-контейнер (input/result relay:
  `GET /jobs/{id}/input`, `POST /jobs/{id}/result`, internal relay) — иначе inline/large
  result-путь воркера не замкнётся.
- Снести/не поднимать стейл-контейнер `xakki-convertor-worker-ffmpeg` старой архитектуры
  в e2e-профиле, чтобы он не «маскировал» результат.
- Обновить `workers/tests/test_workers_e2e.py`, если контракт статуса изменился.

**Decisions (2026-07-07, груминг с пользователем):**
1. **Объём стека — ПОЛНЫЙ, тот же прод-стек** (php+nginx+mariadb+keydb+minio+gateway+
   on-server workers). e2e гоняет реальный сквозной путь через Symfony relay, не стаб.
   **Тест-изоляция:**
   - **MariaDB:** отдельный тест-аккаунт + тестовая БД, создаётся init-скриптом
     `docker/mariadb/dev/init/create-test-db.sh` (и `prod/init/` симметрично). e2e
     подключается к тест-БД, прод-данные не трогает.
   - **KeyDB:** отдельный **DB-index** для теста (подтверждено: не префикс). Весь тест-стек
     (gateway+workers+metrics-exporter) через env `REDIS_DB=<тест-index>` в COMPOSE_TEST
     смотрит в отдельную базу KeyDB → полная изоляция стримов/`conv:status`/job-ключей,
     НОЛЬ правок в коде gateway; данные самоочищаются по TTL. (Префикс отклонён: gateway
     читает фиксированные `conv.<type>` — конфигурируемый namespace стримов = scope creep.)
   - **Создание задачи в e2e — СИД** (решение тимлида): тест вставляет строку conversion в
     тест-БД с корректным stream + S3-input-ключом и делает XADD в `conv.<type>` ровно как
     Messenger, затем наблюдает transport→relay→`conv:status=completed`→S3. Реальный
     user-upload НЕ гоняем: worker-facing путь (`/worker/jobs/{id}/input`,
     `/internal/worker/result`) на статичных worker/gateway bearer-токенах, не на user-JWT —
     auth/фронт в scope эпика (WS-транспорт) не входит. Сид не требует поднимать auth-слой.
2. **ws-gateway — постоянный сервис в основном `docker-compose.yml`** (прод-компонент,
   единственный читатель KeyDB Streams). Собирается из `docker/workers/gateway.Dockerfile`,
   слушает `WS_PORT` (8091), сеть `${COMPOSE_PROJECT_NAME}-network`, `depends_on: keydb`.
   Нужны Makefile-таргеты `build-gateway` (+ включить в `build-workers`) и подъём в `up`.
3. **`GATEWAY_WS_URL` в основном `.env`** → внутренний `ws://ws-gateway:8091/ws/worker/`
   для dev+e2e (воркеры сейчас жёстко падают без него). Публичный `wss://…` (для remote-AI)
   — только оверрайд в `.env.local`.
4. **Приоритет:** делаем в рамках эпика `s1-ws-worker-transport` сейчас (единственный
   слепой гейт эпика).

**Дополнительно найдено при груминге (recon explore-stack):**
- **e2e-тест `workers/tests/test_workers_e2e.py` устарел** — написан под старую прямую-KeyDB
  архитектуру (воркер сам XREADGROUP + пишет S3 + HSET `conv:status`). Под новым WS-транспортом
  его надо **переписать** под сквозной поток: создать conversion (через API/сид БД+S3) →
  gateway XREADGROUP `conv.<type>` → dispatch по WS → worker `GET /worker/jobs/{id}/input` →
  конверсия → inline/large result → gateway relay в Symfony (`/internal/worker/result` или
  `/worker/jobs/{id}/result`) → gateway XACK + пишет `conv:status` → тест ждёт `completed` +
  валидирует результат в S3.
- Symfony relay-эндпоинты уже готовы: `InternalWorkerController` (`POST /api/v1/internal/worker/result|fail`,
  firewall `internal_api`, токен `GATEWAY_INTERNAL_TOKEN`), `WorkerController`
  (`GET /api/v1/worker/jobs/{id}/input`, `POST /api/v1/worker/jobs/{id}/result`).

**План (фазами):**
- **Фаза 1 — инфра (devops):** ws-gateway в compose + Makefile build/run-таргеты + `GATEWAY_WS_URL`
  в `.env` + mariadb test-db init-скрипт + KeyDB тест-префикс/DB-index. AC: `make up` поднимает
  gateway, воркеры подключаются (нет краша по пустому URL), `make docker-check` зелён.
- **Фаза 2 — тест (test-engineer):** переписать `test_workers_e2e.py` под новый сквозной поток
  на тест-изоляции из Фазы 1. AC: `make test-e2e` зелёный на свежих образах.

**Связано:** `[[test-e2e-stale-worker-images]]`, `[[ws-onserver-compose-wiring]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** progress — Фаза 1 выполнена, Фаза 2 (переписать test_workers_e2e.py) ожидает test-engineer.

## Execution Log — Фаза 1 (2026-07-07)

**Коммит:** infra: add ws-gateway to compose stack (phase 1)
**Ветка:** task/s1-ws-transport

### Файлы изменены

| Файл | Изменение |
|---|---|
| `docker-compose.yml` | Добавлен сервис `ws-gateway` (после `worker-data`, перед `metrics-exporter`) |
| `docker/fluent-logging.yml` | Добавлена запись `ws-gateway` (tier=worker, log_format=auto, depends_on fluent-bit) |
| `.env` | `GATEWAY_WS_URL=ws://ws-gateway:8091/ws/worker/` (был пуст — воркеры крашились) |
| `.env.test` | Добавлены `REDIS_DB=3`, `REDIS_QUEUE_DB=3`, `GATEWAY_WS_URL=ws://ws-gateway:8091/ws/worker/` |
| `workers/Makefile` | `build-gateway` добавлен в `build-workers`; `ws-gateway` добавлен в `test-e2e` (up + restore) |
| `docker/mariadb/prod/init/create-test-db.sh` | ~~Создан~~ → **удалён** (решение тимлида + security HIGH): тест-БД только в dev; e2e использует `DOCKER_ENV=dev` → `docker/mariadb/dev/init/` где скрипт уже есть. Слабые креды `123456`/`@'%'` на проде — ненужная атакуемая поверхность. |

### Judgement calls

- **`REDIS_DB` НЕ добавлен в основной `.env`** — он экспортируется Makefile и заглушил бы
  `REDIS_DB=3` из `.env.test` при `docker compose --env-file .env.test`. Gateway в compose
  использует inline default `${REDIS_DB:-2}`.
- **`REDIS_HOST`/`REDIS_PORT` НЕ добавлены в `.env`** — достаточно inline defaults в compose-сервисе.
- **Gateway — только сеть `default`** (не `backend`): `keydb` и `nginx` доступны через неё;
  воркеры на `default` могут достучаться до gateway.
- **`fluent-logging.yml` добавлена запись** для ws-gateway — он единственный читатель KeyDB Streams,
  его логи важны; все воркеры уже покрыты.
- **`limits.yml`** — ws-gateway НЕ добавлен: файл покрывает только php/cron/mariadb/nginx/
  metrics-exporter, не worker-сервисы. Добавлять лимиты позже при необходимости.
- **prod `create-test-db.sh` удалён** (security HIGH + решение тимлида, коммит после 1eef5f1):
  тест-БД существует только в dev (`docker/mariadb/dev/init/`). Слабые креды `123456`/`@'%'`
  на проде — ненужная атакуемая поверхность; `123456`-concern к проду больше не применяется.
- **`docker/mariadb/prod/init/.gitignore` возвращён** к оригинальному `*` + `!.gitignore`;
  `create-exporter-user.sh` остался untracked (конвенция репо: prod init-скрипты вне git).

### Gate outputs (статические)

- `make docker-check` → exit 0 ✓
- `make -n build-workers` → включает `build-gateway` ✓
- `make -n test-e2e` → `COMPOSE_TEST up ... ws-gateway ...` ✓ (и в restore строке) ✓

Живое подключение воркеров к gateway **не запускалось** — это статические проверки. Runtime-connect
зависит от токенов ниже (Phase 2).

### Handoff для Фазы 2 (test-engineer)

| Параметр | Значение |
|---|---|
| Тестовая БД MariaDB | `convertor-test` |
| Тестовый user MariaDB | `convertor-test` / `123456` |
| KeyDB test index | `3` (prod=2) |
| `GATEWAY_WS_URL` (внутренний) | `ws://ws-gateway:8091/ws/worker/` |
| Internal relay URL | `http://nginx` + `/api/v1/internal/worker/result\|fail` |
| **⚠ Критично:** тест-раннер (`docker run`) | Нужен `-e REDIS_DB=3` — иначе `XADD` в DB 0, gateway на DB 3 не увидит |
| **⚠ Критично:** `WORKER_API_TOKEN` | Обязан быть непустым и одинаковым у gateway + всех воркеров. В tracked `.env` пуст → реальный только в `.env.local`. Пустой = gateway отклоняет любое WS-подключение (close 1008). |
| **⚠ Критично:** `GATEWAY_INTERNAL_TOKEN` | Bearer для relay gateway→Symfony (`/api/v1/internal/worker/*`). Пустой в tracked `.env`; реальный в `.env.local`. Без него relay-нога (XACK + result/fail) не работает. |

## Execution Log — Фаза 2 (2026-07-07)

**Ветка:** task/s1-ws-transport

### Файлы изменены

| Файл | Изменение |
|---|---|
| `workers/tests/test_workers_e2e.py` | **Переписан** под WS-transport: seed DB+S3 → XADD → двухфазный polling conv:status → DB oracle → S3 validate |
| `workers/requirements-test.txt` | Добавлен `PyMySQL>=1.1` для DB-сидинга |
| `workers/Makefile` test-e2e | `php nginx` добавлены в COMPOSE_TEST up и restore; `-e REDIS_DB=3`; `-e DB_HOST/DB_NAME/DB_USER/DB_PASS` |
| `.env.test` | Добавлены `WORKER_API_TOKEN=test-worker-token`, `GATEWAY_INTERNAL_TOKEN=test-internal-token` |
| `app-symfony/.env.test` | Добавлены `REDIS_DSN=redis://keydb:6379?dbindex=3`, `DATABASE_URL` → `convertor-test` |

### Архитектурные решения Фазы 2

**REDIS_DSN mismatch (ключевая находка):**
PHP (`WorkerStreamGateway`) читает `REDIS_DSN` через Symfony Dotenv, не через Docker env
(явно исключён из `x-app-env` в `docker-compose.yml`). `REDIS_DSN=redis://keydb:6379?dbindex=2`
в `app-symfony/.env` → PHP смотрел в KeyDB DB 2, а gateway записывал `worker:job:{jobId}` в
DB 3. Фикс: `REDIS_DSN=redis://keydb:6379?dbindex=3` в `app-symfony/.env.test` + перезапуск
`php nginx` с `$(COMPOSE_TEST)` (PHP получает `APP_ENV=test` → Dotenv загружает `.env.test`).

**Токены (WORKER_API_TOKEN / GATEWAY_INTERNAL_TOKEN):**
- `app-symfony/.env.test` уже имел тестовые значения (`test-worker-token`, `test-internal-token`)
  для PHP/Symfony.
- Но root `.env.test` (для docker compose) — не имел. Gateway и воркеры брали пустые значения
  из `.env` → WS-auth fail. Фикс: добавлены в `.env.test`.

**conv:status polling (смена контракта):**
Gateway **DEL**ит ключ `conv:status:{convId}` при XACK (терминал), НЕ пишет `state=completed`.
Тест использует двухфазный подход: ждать появления (`state=processing`) → ждать исчезновения
ключа. Финальный оракул — DB query `conversions.status == 'completed'`.

**S3 result key без KeyDB-lookup:**
Предсказывается детерминированно как `{S3_PREFIX}results/{Y}/{m-d}/{conv_id}.json`
(зеркало PHP `ResultKeyBuilder::build`). Избегает необходимости читать FileStorage из DB.

**DB isolation:**
`convertor-test` DB (создана init-скриптом `docker/mariadb/dev/init/create-test-db.sh`).
`S3_BUCKET_PREFIX` — injected Docker env → Symfony Dotenv не перезаписывает → оба (PHP + тест)
используют `convertor-inputs`/`convertor-results`. Только key prefix меняется (`test_`).

### Gate outputs (статические, до live-run)

- `make docker-check` → exit 0 ✓
- `make -n test-e2e` → `$(COMPOSE_TEST) up ... php nginx ws-gateway ...` ✓
  + docker run с `-e REDIS_DB=3` + `-e DB_HOST/DB_NAME/DB_USER/DB_PASS` ✓
  + restore `$(DC) up ... php nginx ws-gateway ...` ✓

---

## Execution Log — Live Run (2026-07-07)

**Ветка:** task/s1-ws-transport

### Дополнительные блокеры найдены и исправлены при live-прогоне

| Коммит | Проблема | Исправление |
|---|---|---|
| `93f36e3` | `API_BASE_URL` из `.env.local` (публичный HTTPS) утекал в воркеры — воркер делал `GET https://convertor.xakki.pro/...` вместо `http://nginx` | `unexport API_BASE_URL` в Makefile + `API_BASE_URL=http://nginx` в `.env.test` и e2e overlay |
| `9f65301` | belt-and-suspenders: явный `API_BASE_URL: http://nginx` в `docker/docker-compose.e2e.yml` для всех worker-сервисов | — |
| `9dcd684` | `APP_ENV=dev` из root `.env` утекал в shell (Makefile `export`), перекрывал `APP_ENV=test` из `--env-file .env.test`. PHP грузил `.env.local` с реальным `WORKER_API_TOKEN`, воркеры несли `test-worker-token` → 401 на каждом `/worker/jobs/{id}/input` | `unexport APP_ENV` в root Makefile |
| `35b6f71` | Doctrine `when@test: dbname_suffix: '_test%env(default::TEST_TOKEN)%'` добавлял суффикс `_test` → `convertor-test_test` (не существует) → `Access denied` при migrations | `dbname_suffix: ''` (суффикс был непереработанным шаблоном: `TEST_TOKEN` нигде не задан, `convertor-test_test` не создавалась) |
| `d588795` | **Регрессия make up:** глобальные `unexport WORKER_API_TOKEN/GATEWAY_INTERNAL_TOKEN` срывали dev-токены из `.env.local` при `make up` (пустые `.env` значения → gateway close 1008). Кроме того, migration шаг хардкодил `DATABASE_URL` | Убраны глобальные `unexport`. Токены скоупированы через `env -u` только на COMPOSE_TEST up. Migration переведён на `$(COMPOSE_TEST) exec -T php bin/console` (APP_ENV=test из контейнера → загружает `app-symfony/.env.test` → правильный DATABASE_URL автоматически) |

### PHPUnit regression check

`make test-php` после изменения `doctrine.yaml` → **76 tests, 281 assertions, OK** (PHPUnit не
трогает DB или работает через другой bootstrap — изменение безопасно).

### Финальный live-run результат

```
workers/tests/test_workers_e2e.py::test_worker_data_csv_to_json PASSED   [100%]
======================== 1 passed, 3 warnings in 2.72s =========================
```

Прогон воспроизводим (подтверждён дважды). Стек восстанавливается в dev-конфигурацию автоматически.

---

### Правка `make up` регрессии (`6756e72`)

Глобальный `unexport API_BASE_URL` (оставался в root Makefile) стрипал значение из `.env.local`
у ВСЕХ таргетов включая `make up` → воркеры получали пустой URL. Исправлено:

- Убран `unexport API_BASE_URL` из глобальной секции
- Добавлен `E2E_CLEAN_ENV = env -u WORKER_API_TOKEN -u GATEWAY_INTERNAL_TOKEN -u API_BASE_URL`
  рядом с `COMPOSE_TEST` в root Makefile
- В `workers/Makefile` `test-e2e`: инлайн `env -u ...` заменён на `$(E2E_CLEAN_ENV) $(COMPOSE_TEST) up ...`

**Итоговый unexport-блок root Makefile (только два глобальных):**
```makefile
unexport COMPOSE_FILE   # не переопределять COMPOSE_FILE из .env в recipe-шеллах
unexport APP_ENV        # не перебивать APP_ENV=test из --env-file .env.test (safe: .env=dev)
```
Остальные vars (WORKER_API_TOKEN, GATEWAY_INTERNAL_TOKEN, API_BASE_URL) — глобально НЕ unexporting'ятся;
скоупированы только в `$(E2E_CLEAN_ENV)` перед e2e stack-up.

**Proof A** (dev-путь): `make -p | grep -E 'WORKER_API_TOKEN|GATEWAY_INTERNAL_TOKEN|API_BASE_URL'`
показал все три непустыми (реальные dev-значения из `.env.local` — в карточку не пишем).

**Proof B** (e2e после правки):
```
1 passed, 3 warnings in 2.68s
```

---

### Review nits применены (`ca5d3cd`)

| Nit | Изменение |
|---|---|
| **NIT 1** (midnight boundary) | `_result_key(conv_id)` вычисляется ПОСЛЕ `_wait_terminal()` — дата = время persist'а PHP, не seed'а |
| **NIT 2** (cleanup guard) | `output_key = _result_key(conv_id)` ставится ДО `_check_db_completed` — `finally` убирает S3 объект даже при assert fail |
| **NIT 3** (dual cred) | `DB_TEST_PASS=123456` определён в `.env.test` и в `Makefile` (рядом с `E2E_CLEAN_ENV`); `workers/Makefile` ссылается на `$(DB_TEST_PASS)`, литерал `123456` убран из рецепта |

**Re-verified green после нитов:**
```
1 passed, 3 warnings in 3.91s
```
