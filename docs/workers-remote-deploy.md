# Развёртывание воркеров на remote-хосте (без основного стека)

Как поднять на отдельном (CPU) хосте только конвертационных воркеров —
5 non-AI (`worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video`,
`worker-image`, `worker-data`) + `worker-ai:cpu` — не разворачивая на нём
`php`/`mariadb`/`nginx`/`keydb`/`ws-gateway`/`metrics-exporter`. Все воркеры —
чистые WS-клиенты (см. `docs/queue-contract.md`): ни KeyDB, ни S3 напрямую не
трогают, только публичный `wss://…/ws/worker/` gateway'я и Symfony API по
HTTPS. Трафик до главного сервера идёт не напрямую: `remote-хост → saNl
(nginx `stream`, SNI passthrough) → saFin:448 → nginx проекта` — подтверждено
`nginx -T` на saNl (`map $ssl_preread_server_name`: `xakki.pro`/`~*\.xakki\.pro$`
→ `upstream safin_448 { server 95.217.118.82:448; }`). `ws-gateway` и
`metrics-exporter` остаются ТОЛЬКО на главном сервере — их на remote-хосте
поднимать не нужно и незачем.

> **Публичный bootstrap без клона репо:** `deploy/` + gist (`curl | bash`) —
> см. `deploy/README.md`. Этот документ — полный путь через клон репозитория
> и Makefile (fluent-sidecar, GPU/CUDA и т.п.).

Про сборку/запуск именно AI-воркера отдельно (GPU/CPU, двухслойная схема
`ai-base` → `ai.cuda`/`ai.cpu`) — см. `docs/worker-ai-deploy.md`; здесь эта
сборка используется как один из шести сервисов набора.

## Что переезжает, а что остаётся

| Компонент | Remote-хост | Главный сервер |
|---|---|---|
| worker-libreoffice, worker-ffmpeg-audio/video, worker-image, worker-data, worker-ai | ✅ | (свои экземпляры продолжают работать параллельно) |
| fluent-bit | ✅ project sidecar из `docker/fluent-log` | ✅ host-level shared-fluent-bit (НЕ project sidecar) |
| ws-gateway | ❌ | ✅ (единственный читатель KeyDB Streams) |
| metrics-exporter | ❌ | ✅ |
| php / nginx / mariadb / keydb | ❌ | ✅ |

Remote-воркеры — ДОПОЛНИТЕЛЬНЫЕ consumer'ы тех же conv.* Streams, не замена
on-server воркерам: gateway балансирует задачи между всеми подключёнными WS-
клиентами независимо от того, на каком хосте они физически работают.

## Предпосылки

- Docker (24+) на remote-хосте.
- Клон репозитория `convertor` НА remote-хосте — нужен `docker-compose.yml` +
  Makefile-таргеты (`pull`, `workers-recreate`). Сами образы собирать не
  нужно: 5 обычных воркеров + `ws-gateway` + `metrics-exporter` +
  `worker-ai:cpu` публикуются в Harbor (`harbor-published-worker-images`, см.
  `docs/worker-ai-deploy.md`) — happy path тянет их готовыми. Локальная
  сборка из исходников остаётся фолбэком для свежего хоста без доступа к
  Harbor или разработки (см. «Получение образов» ниже).
- **Инициализировать git-сабмодуль `docker/fluent-log`** — remote `.env.local`
  (из `.env.local_worker_example`) добавляет `docker/fluent-log/docker-fluent.yml`
  в `COMPOSE_FILE`; без инициализированного сабмодуля compose не найдёт файл
  и упадёт. Сам `docker/fluent-logging.yml` только настраивает logging-driver
  (fluentd → `${EXT_FLUENT_PORT}`), `include:` сайдкара в нём нет:
  ```bash
  git submodule update --init docker/fluent-log
  ```

**Архитектура логирования (осознанное отклонение):** на saFin (главный сервер)
логи идут в общий host-wide fluent-bit; на remote-хосте — project sidecar из
сабмодуля `docker/fluent-log`, поднимаемый вместе со стеком через `COMPOSE_FILE`.
Это намеренное отличие от кросс-проектного стандарта shared-fluent на saFin.
- Сетевой доступ с remote-хоста к главному серверу (`wss://` gateway) и к
  Graylog (`https://log.variantgood.com/gelf`) — см. проверку связности ниже.
  Никакого входящего порта на remote-хосте открывать не нужно — все
  соединения исходящие (WS-клиент → gateway, fluentd-driver → localhost-
  сайдкар → Graylog). `docker network create common` тоже не требуется:
  все 6 воркеров + `fluent-bit` сидят только в дефолтной сети проекта
  (`${COMPOSE_PROJECT_NAME}-network`), к `backend`/`common` (нужны только
  `ws-gateway`/`keydb`/`metrics-exporter`) не подключаются — проверено
  `docker compose config` (см. «Верификация» в конце документа).

### Preflight: проверить доступность транспорта ДО деплоя

```bash
# 1. Публичный WS-эндпоинт gateway — ожидаем 426 (Upgrade Required, здоровое
#    состояние: сервер жив, просто это не WS-handshake). ВАЖНО: именно GET
#    (curl -s -o /dev/null -w), НЕ curl -I/HEAD — `websockets`-либа на
#    сервере парсит только GET и на HEAD ложно отвечает 502.
curl -s -o /dev/null -w '%{http_code}\n' --max-time 5 https://convertor.xakki.pro/ws/worker/
# ожидаем: 426

# 2. Symfony API — ожидаем 200.
curl -s -o /dev/null -w '%{http_code}\n' --max-time 5 https://convertor.xakki.pro/api/v1/formats
# ожидаем: 200

# 3. Graylog GELF HTTP-эндпоинт — ожидаем 405 (Method Not Allowed на GET;
#    эндпоинт принимает только POST, 405 = живой и слушает).
curl -sS -o /dev/null -w '%{http_code}\n' --max-time 5 https://log.variantgood.com/gelf
# ожидаем: 405
```

Любой из трёх кодов кроме ожидаемого (таймаут, 000, 5xx кроме упомянутых) —
СТОП, чинить сеть/firewall ДО запуска воркеров, иначе получите бесконечный
reconnect-loop без диагностики в логах.

## Настройка `.env.local`

```bash
cp .env.local_worker_example .env.local
```

⚠ Именно `.env.local_worker_example` (не `.env.local_example` — тот для главного
сервера). Он задаёт `COMPOSE_PROFILES` так, что `make up` на этом хосте поднимает
ТОЛЬКО воркеров + fluent-bit: серверная часть (php/cron/mariadb/keydb/nginx/
ws-gateway) сидит под профилем `server`, который здесь не активируется, а
metrics-exporter — под `monitoring` (он к тому же требует внешнюю сеть `common`
с главного сервера). Заполнить нужно (см. комментарии в самом файле):

| Переменная | Значение | Почему |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | своё уникальное, НЕ `xakki-convertor` | **Критично.** `WORKER_ID` каждого воркера = hostname контейнера = `${COMPOSE_PROJECT_NAME}-worker-*` (`docker-compose.yml`), и ЭТО ЖЕ имя используется дословно как имя KeyDB-consumer'а в `XREADGROUP` (`workers/gateway/ws_server.py`). Одинаковый `COMPOSE_PROJECT_NAME` на двух хостах → два физически разных контейнера претендуют на одно имя consumer'а — gateway не различит их, reclaim/ack начнут путаться. |
| `WORKER_API_TOKEN` | реальный токен | Единственная обязательная переменная без дефолта — пустая строка блокирует старт воркера (`WsClientConfig.validate()`), это НАМЕРЕННО (без него был бы reconnect-storm против недоступного/неавторизованного gateway). |
| `COMPOSE_PROFILES` | `ai` (или пусто на CPU-хосте без worker-ai) | НЕ добавлять `server`/`monitoring` — иначе `make up` потянет серверную часть, которой здесь нет. |
| `WORKER_PULL_POLICY` | `missing` | Happy path: `make pull`/`make up` тянет готовые образы из Harbor вместо сборки; если тега ещё нет (свежий хост до первого релиза) — падает обратно на `build:`-секцию. Это и дефолт `docker-compose.yml`, `.env.local_worker_example` просто фиксирует его явно. |
| `AI_PULL_POLICY` | `always` на CPU-хосте / `build` на GPU-хосте | Отдельная политика для `worker-ai`: `worker-ai:latest-cpu` публикуется в Harbor (`always` безопасен), `worker-ai:latest-cuda` — НЕТ (GPU-хост обязан ставить `build`: `missing` спасает только `make up`, явный `make pull` при `missing` всё равно пытается запросить несуществующий тег и падает exit 2; `always` хардфейлит «pull access denied» сразу). |
| `IMAGE_TAG` | `latest` (или запиненная версия релиза, напр. `0.1-a1b2c3d`) | Какой тег пуллить/использовать в compose; пиновка версии — только на remote, главный сервер всегда на `latest`. |
| `COMPOSE_FILE` | из шаблона (+ `docker/fluent-log/docker-fluent.yml`) | Свой fluent-bit-сайдкар поднимается вместе со стеком: общего host-wide сборщика на remote-хосте нет. |
| `EXT_FLUENT_PORT` | `127.0.0.1:24224` (loopback) | Intake сайдкара — только localhost: docker logging-driver на хосте шлёт логи в sidecar. Не переопределять на `0.0.0.0` — сборщик не должен слушать снаружи. |
| `GATEWAY_WS_URL` / `API_BASE_URL` | дефолты из трекаемого `.env` подходят (`wss://convertor.xakki.pro/ws/worker/`, `https://convertor.xakki.pro`) | Переопределять только для нестандартного стенда; `API_BASE_URL` — корень API, БЕЗ `/api`-суффикса. |
| `AI_VARIANT`/`AI_RUNTIME` | опционально: `cuda`/`nvidia` на GPU-хосте | По умолчанию `cpu`/`runc` (см. `docker-compose.yml` сервис `worker-ai`) — задавать нужно только на GPU-хосте. |

Почему `missing`, а не `always`: под `pull_policy: always` Compose хардфейлит
`make up`, если пулл не удался, вместо фолбэка на `build:`-секцию — это ломает
и свежий хост (образ ещё не долетел до Harbor), и любой хост до первого
релиза. Обновления и так приходят через явный `make pull` (уже в happy path:
`git pull && make pull && make workers-recreate`) — `always` тут ничего не
даёт, а фолбэк ломает. Проверено эмпирически.

`GRAYLOG_HOST`/`GRAYLOG_PORT`/`GRAYLOG_URI`/`HOST_NAME`/`HOST_IP` **на remote
настраивать не нужно** — трекаемый `.env` уже задаёт верные значения.
`EXT_FLUENT_PORT` тоже: дефолт в трекаемом `.env` — loopback `127.0.0.1:24224`;
не переопределять на `0.0.0.0`. На remote **обязательно** задать `COMPOSE_FILE`
из шаблона (с `docker/fluent-log/docker-fluent.yml`), иначе sidecar не войдёт в
стек при `make up` — логи некуда слать. С 2026-07 все 6 воркеров шлют логи через
общий `x-logging` (`docker/fluent-logging.yml`) в `${EXT_FLUENT_PORT}`.
`HOST_NAME`/`HOST_IP` корневой `Makefile` сам вычисляет через
`hostname`/`hostname -I`. Переопределять их в `.env.local` имеет смысл только
если авто-значение неинформативно (напр. generic-имя VM).

### `WORKER_HOST` — явный host/node-идентификатор воркера (registry-08)

До registry-08 не было ни одного явного поля, отличающего физический хост
инстанса — только соглашение по именованию `WORKER_ID`/`COMPOSE_PROJECT_NAME`
(таблица выше). Теперь каждый воркер репортит `host` в register-payload'е
(`workers/common/ws_client.py::_worker_host()`), и он виден отдельной колонкой
на `/admin` → Воркеры.

Источник — **`HOST_NAME`**, автовычисляемый корневым `Makefile` (`hostname`) —
`docker-compose.yml` прокидывает его каждому воркеру, включая `worker-ai`, как
`WORKER_HOST`. Отдельную переменную под это заводить не нужно и переопределять
для fluent-bit-лейблов/`WORKER_HOST` тоже — один и тот же авто-`HOST_NAME`
кормит обе цели.

Приоритет источника в самом Python-коде (на случай запуска БЕЗ compose,
напр. `docker run` из quickstart-секции `docs/worker-ai-deploy.md`):
`WORKER_HOST` env → `NODE_NAME` env (алиас) → hostname контейнера (фолбэк,
нестабилен между пересозданиями контейнера, если он не запиннен
`hostname:`-директивой). Без `WORKER_HOST` worker-ai's
`hostname: "${COMPOSE_PROJECT_NAME}-worker-ai"` уже даёт стабильный фолбэк —
но `WORKER_HOST` явнее и не зависит от этого пиннинга.

## Получение образов

**Happy path — образы уже в Harbor, собирать ничего не нужно:**

```bash
git pull && make pull && make workers-recreate
```

`make pull` (с `WORKER_PULL_POLICY=missing` + `AI_PULL_POLICY=always` в
`.env.local`, см. таблицу выше) тянет готовые `worker-libreoffice`,
`worker-ffmpeg`, `worker-image`, `worker-data`, `worker-ai:latest-cpu` из
`harbor.xakki.ru/convertor` — килобайты кода на обычный релиз, не пересборка.
GPU на remote-хосте не предполагается: `worker-ai:cuda` в Harbor не
публикуется (см. `docs/worker-ai-deploy.md`), если он есть — воркер остаётся
на локальной сборке (`build-ai-cuda`) с `AI_VARIANT=cuda` + `AI_RUNTIME=nvidia`
+ `AI_PULL_POLICY=build` в `.env.local` (`build`, а не `missing` — иначе
именно этот `make pull` падает на несуществующем `worker-ai:latest-cuda`).

### Фолбэк: локальная сборка (свежий хост без доступа к Harbor / разработка)

```bash
make build-workers   # 5 обычных воркеров + metrics-exporter + ws-gateway
make build-ai-cpu    # worker-ai (двухступенчато: ai-base → :cpu); на GPU — build-ai-cuda
```

Собирает все 6 worker-образов из исходников: `worker-libreoffice`,
`worker-ffmpeg` (общий образ для worker-ffmpeg-audio/video), `worker-image`,
`worker-data` (обычные `build-*`-таргеты) + `worker-ai:cpu` (через
`build-ai-cpu`, который сам сначала пересобирает свежий `worker-ai-base`
локально — см. `docs/worker-ai-deploy.md`, «Двухслойная схема»).

## Запуск

```bash
make up
```

С `.env.local` из `.env.local_worker_example` это поднимает ровно 6 воркеров +
`fluent-bit` + `logrotate` — серверная часть отфильтрована профилями. Проверить
состав до запуска: `docker compose config --services`. `make down` симметрично
гасит только их.

`logrotate` из сабмодуля поднимается заодно (он в том же compose-файле) и
безвреден: ротировать ему на remote-хосте нечего — воркеры пишут только в
stdout через `fluentd`-driver.

Точечно, без полного `up`: `make workers-recreate` (пересоздать 6 воркеров из
свежих образов, `--no-deps`) и `make fluent-up` (только сайдкар).

Обновление (happy path — pull, без сборки):
```bash
git pull && make pull && make workers-recreate
```

Обновление после локальной пересборки (фолбэк, см. «Получение образов»):
```bash
make build-workers && make build-ai-cpu
make workers-recreate
```

Логи:
```bash
make worker-logs   # все 6 воркеров, включая worker-ai
make fluent-logs   # сайдкар fluent-bit
```

Остановка (на remote-хосте, кроме воркеров + fluent-bit, ничего и не
поднималось):
```bash
docker compose stop worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai fluent-bit
```

## Верификация после запуска

1. **Config валиден** (запускать на remote-хосте после `.env.local`):
   ```bash
   make docker-check
   ```
2. **Handshake прошёл** — в логах `ws-gateway` (на ГЛАВНОМ сервере) должен
   появиться `ready`-фрейм с `workerId` remote-воркера на каждый из 6 типов
   (`${COMPOSE_PROJECT_NAME}-worker-libreoffice` и т.д. — с ВАШИМ
   `COMPOSE_PROJECT_NAME`, отличным от главного сервера):
   ```bash
   # на главном сервере
   make -C workers gateway-logs | grep -i ready
   ```
   Дополнительно: `/admin` → Воркеры на главном сервере — колонка `Host`
   у всех 6 remote-инстансов должна показывать значение `HOST_NAME` вашего
   remote-хоста (registry-08, см. раздел «`WORKER_HOST`» выше), не прочерк.
3. **Одна живая конвертация на категорию** — прогнать через публичный API по
   одному файлу на document/image/audio/video/data/ai и убедиться, что задачу
   забрал именно remote-воркер (по `workerId` в логах gateway/API, не
   on-server дубликат).
4. **Логи видны в Graylog** — отфильтровать по `HOST_NAME`/`HOST_IP`
   remote-хоста (лейблы, которые проставляет fluent-bit-сайдкар) и убедиться,
   что записи от всех 6 сервисов приходят, включая `worker-ai`.

## Троблшутинг

| Симптом | Причина | Решение |
|---|---|---|
| WS закрывается сразу с `close 1008 unauthorized` (см. лог воркера) | `WORKER_API_TOKEN` пуст/не совпадает с тем, что знает gateway/Symfony. | Сверить `WORKER_API_TOKEN` в `.env.local` remote-хоста с актуальным значением на главном сервере. |
| Воркер падает при старте: `GATEWAY_WS_URL пуст — некуда подключаться` | `GATEWAY_WS_URL` не задан/пуст И воркер запущен НЕ в prod worker-режиме (в котором есть встроенный прод-дефолт) — например, случайно собран/запущен devserver-путь. | Явно задать `GATEWAY_WS_URL=wss://convertor.xakki.pro/ws/worker/` в `.env.local`; убедиться, что `command` контейнера — `python3 -m workers.<type>`, не `--devserver`. |
| Воркер стартует, но HTTP-запросы к API уходят на `http://localhost:8080` | `API_BASE_URL` не задан — это дефолт из `WsClientConfig.from_env` (`workers/common/ws_client.py`), применяемый ТОЛЬКО когда прод-дефолт для конкретного воркера тоже не сработал (нештатный запуск). | Явно задать `API_BASE_URL=https://convertor.xakki.pro` (без пути) в `.env.local`. |
| Логи воркеров не доходят до Graylog (в интерфейсе Graylog пусто по `HOST_NAME` remote-хоста) | Сабмодуль `docker/fluent-log` не инициализирован; в `.env.local` нет `docker/fluent-log/docker-fluent.yml` в `COMPOSE_FILE` (sidecar не поднялся с `make up`); `fluent-bit` остановлен; `EXT_FLUENT_PORT` ошибочно переопределён на `0.0.0.0`; нет исходящей связности до `log.variantgood.com:443`. | `git submodule update --init docker/fluent-log`; проверить `COMPOSE_FILE` из шаблона; `make fluent-up` или полный `make up`; `EXT_FLUENT_PORT=127.0.0.1:24224`; `docker compose logs fluent-bit`; preflight-curl №3 выше. |

## AI на CPU — ограничение производительности

`worker-ai:cpu` (Whisper `int8`, `llama.cpp` без CUDA) заметно медленнее GPU-
сборки (`worker-ai:cuda`) — STT/LLM-инференс на CPU может занимать секунды-
десятки секунд там, где GPU укладывается в доли секунды. Для remote-хоста без
GPU это ожидаемо и штатно (пропускная способность гейтится `WS_SLOTS`/
конкуренцией с другими типами задач на том же хосте), но не рассчитывайте на
production-SLA для `conv.ai` без хотя бы одного GPU-воркера в пуле — детали
автодетекта device/compute-type и способы явно задать `cuda` — см.
`docs/worker-ai-deploy.md`.

## См. также

- `docs/worker-ai-deploy.md` — сборка/запуск/траблшутинг именно AI-воркера
  (GPU/CPU, двухслойная `ai-base` схема).
- `docs/queue-contract.md`, `docs/queue-streams.md` — контракт очередей и
  WS-транспорт (воркер — WS-клиент gateway, не трогает KeyDB/S3 напрямую).
- `workers/Makefile` — исходники таргетов `build-workers`, `workers-recreate`,
  `worker-logs`, `fluent-up`/`fluent-restart`/`fluent-logs`.
