### S1-12 — Compose + nginx + env + версия воркера

**Критичность:** High

**TAGS:**
- transport
- deploy
- infra

**Описание:**
Развёртывание нового сервиса WS-Gateway и вся инфра-обвязка (§7/§8 spec).

**Compose/сеть:** новый сервис `ws-gateway` (async Python) в сетях **`backend`** (достучаться до keydb) + **`default`** (публичный фронт + egress к Symfony), `depends_on: keydb`. НЕ `common`. Воркеры после миграции **уходят с сети `backend`** и **лишаются S3-кред** (остаются на `default` для доступа к `nginx`/Symfony API).

**nginx (локальный сервис репо, на `default`):** новый `location /ws/worker/` — терминация TLS + заголовки `Upgrade`/`Connection` для WS-handshake + проксирование в контейнер gateway. WS read-timeout ОБЯЗАН превышать самую долгую конвертацию (video ~600 s), при этом `ping`-keepalive воркера — ниже таймаута (§10).

**Makefile:** таргеты build/up/down/logs для gateway; `make docker-check` (= `docker compose config -q`) проходит с подключённым сервисом. Образ gateway публикуется в Harbor (проект `convertor`).

**Версия воркера (§4/§8):** полная `version` = `APP_VER` (`.env`, напр. `0.1`) + build-счётчик из gitignored `.i` в корне репо → `0.1.6`; **запекается в образ при build**; воркер репортит в `ready` (не ходит за версией в KeyDB). Инкремент `.i` — забота Makefile/CI (одна строка).

**Env (секреты ПУСТЫЕ в трекаемых файлах):**
- НОВЫЕ: `GATEWAY_INTERNAL_TOKEN` (пусто в трекаемом), `WORKER_ID`, `GATEWAY_WS_URL`, `WS_RESULT_INLINE_MAX`=`262144`, `RECLAIM_IDLE_MS_<TYPE>` (map: document `300000`/image `120000`/audio `300000`/video `600000`/data `180000`/ai `300000`), `RECLAIM_INTERVAL_S`=`60`, `WS_BLOCK_MS`, `APP_VER`, `WS_PING_INTERVAL_S`, `WS_PROGRESS_INTERVAL_S`=`1`, `WS_LIVENESS_MISSED_PINGS`, `WS_RECONNECT_BACKOFF_*`.
- ПЕРЕИСПОЛЬЗУЕТСЯ: `WORKER_API_TOKEN`.
- УДАЛЯЕТСЯ: `POLL_INTERVAL`, `PULL_ENABLED`, `RECLAIM_MIN_IDLE_MS` (единое значение → per-type map), S3-креды у on-server-воркеров.

**Файлы:**
- Изменить: `docker-compose.yml` (сервис `ws-gateway`; сети воркеров — убрать `backend`/S3-креды), `docker/limits.yml` (лимиты gateway).
- Изменить: nginx-конфиг репо (`location /ws/worker/`, WS-таймауты).
- Изменить: `Makefile` (таргеты gateway build/up/down/logs; инкремент `.i`; запекание `WORKER_VERSION`).
- Изменить: `.env`, `.env.local_example` (новые переменные пустыми; убрать pull-env).
- Изменить: `.gitignore` (`.i`).

**Критерии приёмки:**
- `make docker-check` проходит с сервисом `ws-gateway`.
- `ws-gateway` на сетях `backend`+`default` (не `common`), `depends_on: keydb`; воркеры не на `backend` и без S3-кред (проверка config).
- `location /ws/worker/` проксирует WS с `Upgrade`/`Connection`; read-timeout > 600 s.
- Трекаемые `.env`/`.env.local_example` держат новые секреты пустыми; `POLL_INTERVAL`/`PULL_ENABLED`/`RECLAIM_MIN_IDLE_MS` удалены.
- Образ воркера несёт запечённую `version` = `APP_VER`+`.i`, воркер репортит её в `ready`.
- `make` таргеты gateway (build/up/down/logs) работают; образ пушится в Harbor.

**Зависит от:** `[[s1-04-result-relay-ack]]`, `[[s1-09-ai-worker-migrate]]`, `[[s1-11-onserver-workers-migrate]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** progress

---

## Execution Log (s1-12-infra)

Ветка `task/s1-ws-transport`. Много обвязки уже сделали предыдущие сабкарты (s1-11 мигрировал
воркеров с `backend`/S3; ws-gateway-сервис, fluent-logging для gateway, build-gateway,
`.i` в .gitignore, GATEWAY_INTERNAL_TOKEN пустой в трекаемых уже были). Доделал остаток s1-12:

- **docker-compose.yml** — `ws-gateway`: добавил сеть `backend` (теперь `backend`+`default`,
  не `common`; `depends_on: keydb service_healthy`). Воркеры уже без `backend`/S3 (проверено
  по `docker compose config`).
- **docker/limits.yml** — блок `ws-gateway` (cpus 1 / mem 256M, `deploy.mode: global`).
- **nginx (dev+prod `default.conf`)** — `location ^~ /ws/worker/` → `ws-gateway:8091`:
  variable-upstream + `resolver 127.0.0.11` (рестарт gateway не роняет nginx), `include
  proxy_params` (http1.1 + Upgrade/Connection), затем override `proxy_read/send_timeout 3600s`
  (ЗАВЕДОМО > video ~600 s; override ПОСЛЕ include, иначе 600s из proxy_params затрёт).
- **Makefile (workers/Makefile)** — `bump-i` (инкремент gitignored `.i`, prereq у 4 воркер-
  build'ов → раз за make); `build_img` пробрасывает `--build-arg APP_VER --build-arg
  WORKER_BUILD`. Добавил `gateway-up/down/logs` + `push-gateway` (Harbor `convertor`, не запускал).
- **Worker Dockerfile'ы (data/ffmpeg/image/libreoffice)** — `ARG APP_VER/WORKER_BUILD` В stage
  worker (иначе не видны в multi-stage) → `ENV APP_VER` + `echo WORKER_BUILD > /app/.i`
  (ws_client читает `os.getenv(APP_VER)` + файл `/app/.i`).
- **.env** — добавил `RECLAIM_INTERVAL_S=60`, `RECLAIM_BATCH=10`, per-type `RECLAIM_IDLE_MS_*`.
  Ретайр-переменных (`POLL_INTERVAL`/`PULL_ENABLED`/`RECLAIM_MIN_IDLE_MS`) в трекаемом `.env`
  нет. `.env.local_example` — `GATEWAY_INTERNAL_TOKEN`/`WORKER_API_TOKEN` = плейсхолдеры.

**Верификация:**
- `make docker-check` — зелёный с подключённым `ws-gateway`.
- `docker compose config`: ws-gateway на `backend`+`default`, `depends_on: keydb`; воркеры без
  `backend` и без `S3_*`; `GATEWAY_INTERNAL_TOKEN=""`.
- Версия: `make build-data` → `.i`=1, `docker run … _compose_version()` → **`0.1.1`**
  (APP_VER запечён в ENV, /app/.i прочитан) без runtime-env.
- nginx `/ws/worker/`: Upgrade/Connection (из proxy_params) + read/send-timeout 3600s > 600 s.

**Deferred / заметки тимлиду:**
- Harbor-push не выполнял (нет деплоя в задаче) — только таргет `make push-gateway`.
- `docker-compose.worker-ai.yml` / `.env.worker-ai.example` НЕ трогал: ретайр `PULL_ENABLED`/
  `POLL_INTERVAL` для remote-AI — scope s1-09 (в `ready/`), но эти файлы всё ещё несут vars —
  возможная нестыковка, пусть s1-09 проверит.

**Review nit (закрыт):** по решению тимлида убрал ретайр-переменные `PULL_ENABLED`/`POLL_INTERVAL`
из `docker-compose.worker-ai.yml` (env-блок + 3 упоминания в комментах) и `.env.worker-ai.example`
(2 блока + коммент к `WORKER_API_TOKEN`). `grep PULL_ENABLED|POLL_INTERVAL` по обоим файлам —
пусто. `make worker-ai-check` rc=0, `make docker-check` — зелёный.
