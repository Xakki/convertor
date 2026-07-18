### worker-ai compose-стаб ломает дефолтный/тест `COMPOSE_FILE` — вынести overlay в worker-ai.yml

**Criticality:** Medium

**TAGS:**
- bug-fix
- docker
- config

**Description:**
`docker/fluent-logging.yml` (файл, всегда входящий в `COMPOSE_FILE` и в трекаемом
`.env`, и в `.env.local`) безусловно определяет overlay-сервис `worker-ai:`
(строки ~138–142, только `logging`/`labels`/`depends_on`, без `image`/`build`).
Полное определение сервиса (`image`, `volumes` и т.д.) живёт в
`docker-compose.worker-ai.yml` — файле, который **намеренно НЕ входит** в
трекаемый `COMPOSE_FILE` (`.env`: `docker-compose.yml:docker/fluent-logging.yml:docker/limits.yml`),
т.к. worker-ai — отдельный remote-стек (см. комментарий в `docker-compose.yml`
строки 328–330).

**Problem:**
`docker compose config` / `make docker-check` в дефолтной конфигурации (без
локального добавления `docker-compose.worker-ai.yml` в `COMPOSE_FILE`) падает:
```
service "worker-ai" has neither an image nor a build context specified: invalid compose project
```
Voспроизводится даже при полностью пустом/отсутствующем `.env.local` — то есть
это баг базового набора файлов, а не локальной настройки конкретной машины.

**Impact:**
`make docker-check` не проходит "из коробки" на чистом чекауте — CI/новый
разработчик получит ложный красный без единого своего изменения. Обнаружено
случайно во время верификации hardening-02 (прокидка WORKER_API_TOKEN/
GATEWAY_INTERNAL_TOKEN в php/cron) — сам фикс не связан с этим багом, но без
воркэраунда `make docker-check` не проходит вообще ни при каком diff.

**ШИРЕ, чем docker-check (подтверждено на hardening-04, 2026-07-17):** тот же
`worker-ai:` стаб ломает ЛЮБОЙ `docker compose --env-file .env.test …`, т.к.
`.env.test` тоже тянет `docker/fluent-logging.yml`, но исключает
`docker-compose.worker-ai.yml`. Падают на этапе `up -d --wait keydb` таргеты
`make test-gateway` и, весьма вероятно, `make test-e2e` / `make test-api-integration`
(тот же `$(COMPOSE_TEST)`-паттерн). Регрессия введена коммитом `17a3fde`
("logging(fluent): ship worker-ai to shared fluent-bit"). **Это блокирует
интеграционный гейт эпика `hardening`** (рестарт стека + полный тест-сьют через
compose) — нужно починить ДО финального прогона эпика. Точечные тесты пока
воспроизводятся через прямой `docker run` на уже поднятой keydb.

**Decisions:** (подтверждено с @user 2026-07-18, subtask 10 эпика `hardening`)
Выбран вариант: **вынести overlay-блок `worker-ai:` из `docker/fluent-logging.yml`
в `docker-compose.worker-ai.yml`** — тогда overlay тянется только вместе с самим
определением сервиса, и дефолтный/тест-набор `COMPOSE_FILE` (без worker-ai.yml)
валиден. Логирование worker-ai не теряется: оно применяется, когда worker-ai.yml
в наборе (remote/standalone-стек). Альтернатива с условным include отклонена как
более хрупкая.

**Acceptance Criteria:**
- Overlay `worker-ai:` (logging/labels/depends_on) перенесён из
  `docker/fluent-logging.yml` в `docker-compose.worker-ai.yml`; в fluent-logging.yml
  его больше нет.
- `make docker-check` проходит зелёным на дефолтном `COMPOSE_FILE` (без
  `docker-compose.worker-ai.yml`) и при пустом `.env.local`.
- `docker compose --env-file .env.test config -q` валиден (тест-стек поднимается);
  `make test-gateway` доходит до pytest (не падает на `up -d --wait keydb`).
- Когда `docker-compose.worker-ai.yml` В наборе — worker-ai по-прежнему получает
  свой logging-конфиг (проверить `docker compose ... config` показывает
  logging-драйвер на worker-ai).
- Tests/QA green: `make docker-check`, `make phpstan`, `make cs-check`.

**Контекст:** найдено при верификации hardening-02-worker-secrets-desync
(2026-07-17), подтверждено шире на hardening-04. Регрессия из коммита `17a3fde`.
Разблокирует верификацию [[hardening-07-e2e-login]] / hardening-08 и
интеграционный гейт эпика.

**Итог реализации (2026-07-18, commit `d83cd2c2`):** overlay `worker-ai:` (+ его
анкор `x-logging-shared`) вынесен из `fluent-logging.yml`; `logging:`+`labels:`
(fluentd 127.0.0.1:24224) добавлены в сервис в `docker-compose.worker-ai.yml`.
Добавлен make-таргет `docker-check-worker-ai` для проверки набора с worker-ai.
Проверка: `make docker-check` exit 0 (и без `.env.local`), `make test-gateway`
поднимает тест-стек + **104 passed**, worker-ai сохраняет logging когда его файл
в наборе, phpstan/cs зелёные. Блокер снят.

**Status:** done.
