# Развёртывание воркеров на remote-хосте (без основного стека)

Как поднять на отдельном (CPU) хосте только конвертационных воркеров —
5 non-AI (`worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video`,
`worker-image`, `worker-data`) + `worker-ai:cpu` — не разворачивая на нём
`php`/`mariadb`/`nginx`/`keydb`/`ws-gateway`/`metrics-exporter`. Все воркеры —
чистые WS-клиенты (см. `docs/queue-contract.md`): ни KeyDB, ни S3 напрямую не
трогают, только публичный `wss://…/ws/worker/` gateway'я на главном сервере и
Symfony API по HTTPS. `ws-gateway` и `metrics-exporter` остаются ТОЛЬКО на
главном сервере — их на remote-хосте поднимать не нужно и незачем.

Про сборку/запуск именно AI-воркера отдельно (GPU/CPU, двухслойная схема
`ai-base` → `ai.cuda`/`ai.cpu`) — см. `docs/worker-ai-deploy.md`; здесь эта
сборка используется как один из шести сервисов набора.

## Что переезжает, а что остаётся

| Компонент | Remote-хост | Главный сервер |
|---|---|---|
| worker-libreoffice, worker-ffmpeg-audio/video, worker-image, worker-data, worker-ai | ✅ | (свои экземпляры продолжают работать параллельно) |
| fluent-bit (сайдкар из `docker/fluent-log`) | ✅ (свой, локальный) | ✅ (свой, локальный) |
| ws-gateway | ❌ | ✅ (единственный читатель KeyDB Streams) |
| metrics-exporter | ❌ | ✅ |
| php / nginx / mariadb / keydb | ❌ | ✅ |

Remote-воркеры — ДОПОЛНИТЕЛЬНЫЕ consumer'ы тех же conv.* Streams, не замена
on-server воркерам: gateway балансирует задачи между всеми подключёнными WS-
клиентами независимо от того, на каком хосте они физически работают.

## Предпосылки

- Docker (24+) на remote-хосте.
- Клон репозитория `convertor` НА remote-хосте (нужны исходники для
  `docker build` — образы воркеров не публикуются в Harbor, кроме
  `worker-ai-base`, см. `docs/worker-ai-deploy.md`).
- **Инициализировать git-сабмодуль `docker/fluent-log`** — без него падает не
  только запуск, но и `docker compose config` (в `docker/fluent-logging.yml`
  есть `include: docker/fluent-log/docker-fluent.yml`, отсутствующий файл
  ломает валидацию compose-файла целиком):
  ```bash
  git submodule update --init docker/fluent-log
  ```
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
cp .env.local_example .env.local
```

Заполнить блок «Remote worker host» в конце файла (см. комментарии там же
для деталей каждой переменной):

| Переменная | Значение | Почему |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | своё уникальное, НЕ `xakki-convertor` | **Критично.** `WORKER_ID` каждого воркера = hostname контейнера = `${COMPOSE_PROJECT_NAME}-worker-*` (docker-compose.yml/docker-compose.worker-ai.yml), и ЭТО ЖЕ имя используется дословно как имя KeyDB-consumer'а в `XREADGROUP` (`workers/gateway/ws_server.py`). Одинаковый `COMPOSE_PROJECT_NAME` на двух хостах → два физически разных контейнера претендуют на одно имя consumer'а — gateway не различит их, reclaim/ack начнут путаться. |
| `WORKER_API_TOKEN` | реальный токен | Единственная обязательная переменная без дефолта — пустая строка блокирует старт воркера (`WsClientConfig.validate()`), это НАМЕРЕННО (без него был бы reconnect-storm против недоступного/неавторизованного gateway). |
| `GATEWAY_WS_URL` | `wss://convertor.xakki.pro/ws/worker/` | Публичный WS-эндпоинт. |
| `API_BASE_URL` | `https://convertor.xakki.pro` | Корень API, БЕЗ `/api`-суффикса (см. троубл-шутинг ниже — если забыть, воркер тихо падает на локальный дефолт). |
| `DOCKER_IMAGE_AI` | `xakki-convertor/worker-ai:cpu` | Локально собранный образ (см. «Сборка» ниже) — Harbor-тег из трекаемого `.env` сюда не подходит, рабочий образ не публикуется. |
| `GRAYLOG_HOST`/`GRAYLOG_PORT`/`GRAYLOG_URI` | `log.variantgood.com` / `443` / `/gelf` | Тот же публичный Graylog, что и у главного сервера — общий лог-путь. |
| `HOST_NAME`/`HOST_IP` | реальные host/IP remote-машины (или оставить как авто-вычисляет Makefile) | Лейблы fluent-bit-сайдкара — по ним в Graylog отличают remote-хост от главного сервера. |
| `EXT_FLUENT_PORT` | **явно пустое** (`EXT_FLUENT_PORT=`) | См. отдельный разбор ниже — это не «просто не упоминать переменную». |

### Почему `EXT_FLUENT_PORT=` должна быть явно пустой, а не просто отсутствовать

Трекаемый `.env` (общий для всех хостов, включая remote) уже задаёт
`EXT_FLUENT_PORT=127.0.0.1:10094` — это порт per-project fluent-bit-сайдкара
**на главном сервере**, специально уведённый с 24224 на 10094, потому что на
главном сервере порт 127.0.0.1:24224 уже занят ДРУГИМ, не относящимся к
`convertor`, хостовым fluent-bit-шиппером. `-include .env.local` в корневом
`Makefile` идёт ПОСЛЕ `include .env` — если `.env.local` просто не упоминает
`EXT_FLUENT_PORT`, значение 10094 из трекаемого `.env` остаётся в силе
НЕИЗМЕННЫМ (переменную нужно перезаписать явно, «отсутствие строки» ничего не
«развключает»).

Одновременно `worker-ai` логирует не через `${EXT_FLUENT_PORT}`, а в свой
ЗАХАРДКОЖЕННЫЙ адрес `127.0.0.1:24224` (`docker-compose.worker-ai.yml`,
инлайновый `x-logging`-блок, не через общий `docker/fluent-logging.yml`). На
remote-хосте конфликтующего стороннего шиппера на 24224 нет — если оставить
`EXT_FLUENT_PORT=10094` (унаследованное из трекаемого `.env`), fluent-bit-
сайдкар remote-хоста поднимется НЕ на 24224, а `worker-ai` продолжит слать
логи на хардкод 24224 — мимо сайдкара, в никуда (либо в чужой процесс, если
он там случайно слушает).

Явная `EXT_FLUENT_PORT=` (пустая строка) чинит оба конца сразу:
- порт самого сайдкара — `docker/fluent-log/docker-fluent.yml` использует
  `${EXT_FLUENT_PORT:-127.0.0.1:24224}` — пустая строка триггерит `:-`-фолбэк
  так же, как отсутствие переменной → сайдкар слушает `127.0.0.1:24224`;
- адрес, на который шлют логи 5 non-AI воркеров — `docker/fluent-logging.yml`
  использует `${EXT_FLUENT_PORT}` БЕЗ дефолта → рендерится в пустую строку,
  а docker'овский `fluentd`-log-driver трактует пустой `fluentd-address`
  так же, как отсутствие опции — фолбэк на встроенный дефолт драйвера
  `localhost:24224` (проверено эмпирически: `docker run --log-opt
  fluentd-address=` не падает и ведёт себя как omitted-опция).

Итог — все 6 воркеров и сайдкар remote-хоста сходятся на одном и том же
`:24224`, без правки самих compose-файлов.

## Сборка образов

```bash
make build-workers-remote
```

Собирает: `worker-libreoffice`, `worker-ffmpeg` (общий образ для
worker-ffmpeg-audio/video), `worker-image`, `worker-data` (обычные
`build-*`-таргеты) + `worker-ai:cpu` (через `build-ai-cpu`, который сам
сначала пересобирает свежий `worker-ai-base` локально — см.
`docs/worker-ai-deploy.md`, «Двухслойная схема»). GPU на remote-хосте не
предполагается — если он есть, собрать `build-ai-cuda` отдельно и
переопределить `DOCKER_IMAGE_AI=xakki-convertor/worker-ai:cuda` в
`.env.local` (см. `docs/worker-ai-deploy.md`).

## Запуск

```bash
make remote-workers-up
```

Поднимает ТОЛЬКО 6 сервисов-воркеров + `fluent-bit` (явно перечислены в
таргете — `--no-deps` иначе не завёл бы сайдкар для `worker-ai`, у которого
нет `depends_on: fluent-bit`, см. комментарий в `workers/Makefile`).
`logrotate` из того же сабмодуля НЕ поднимается — он ротирует файловые логи
(mysql slowlog + JSON-volume), а воркеры на remote-хосте пишут только в
stdout через `fluentd`-driver, ротировать нечего.

Обновление после пересборки образов:
```bash
make build-workers-remote
make remote-workers-recreate
```

Остановка / логи:
```bash
make remote-workers-down
make remote-workers-logs
```

## Верификация после запуска

1. **Config валиден** (запускать на remote-хосте после `.env.local`):
   ```bash
   make docker-check
   make docker-check-worker-ai   # тот же 4-файловый набор, что используют remote-таргеты
   ```
2. **Handshake прошёл** — в логах `ws-gateway` (на ГЛАВНОМ сервере) должен
   появиться `ready`-фрейм с `workerId` remote-воркера на каждый из 6 типов
   (`${COMPOSE_PROJECT_NAME}-worker-libreoffice` и т.д. — с ВАШИМ
   `COMPOSE_PROJECT_NAME`, отличным от главного сервера):
   ```bash
   # на главном сервере
   make -C workers gateway-logs | grep -i ready
   ```
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
| Логи воркеров не доходят до Graylog (в интерфейсе Graylog пусто по `HOST_NAME` remote-хоста) | Чаще всего — `EXT_FLUENT_PORT` унаследован из трекаемого `.env` (10094) вместо явно пустого; сайдкар и `worker-ai` слушают/пишут на разные порты (см. раздел выше). Реже — сабмодуль `docker/fluent-log` не инициализирован, или нет исходящей связности до `log.variantgood.com:443`. | Проверить `EXT_FLUENT_PORT=` (пустая строка!) в `.env.local`; `docker compose logs fluent-bit` на предмет ошибок исходящего соединения; preflight-curl №3 выше. |

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
- `workers/Makefile` — исходники таргетов `build-workers-remote` /
  `remote-workers-{up,recreate,down,logs}`.
