# Развёртывание AI-воркера (worker-ai) на удалённом хосте

Как запустить и (при необходимости) собрать AI-воркер (STT/TTS/embedding/LLM-конвертации) на удалённом GPU- или CPU-хосте.

## Быстрый старт

**CPU-вариант публикуется в Harbor** — happy path его просто пуллит, собирать не
нужно:

```bash
docker pull harbor.xakki.ru/convertor/worker-ai:latest-cpu
```

**CUDA-вариант в Harbor НЕ публикуется** — образ `worker-ai:latest-cuda` существует
только как ЛОКАЛЬНАЯ сборка на GPU-хосте (см. «Сборка образа» ниже), даже несмотря
на то, что его имя выглядит как Harbor-путь (`harbor.xakki.ru/convertor/...` — это
просто значение `IMAGE_NS` по умолчанию, `docker pull` этого тега из реального
registry ничего не найдёт).

```bash
docker rm -f worker-ai 2>/dev/null || true    # если уже запущен старый

docker run -d --name worker-ai --hostname worker-ai --restart unless-stopped --gpus all \
  -e WORKER_API_TOKEN=<ТОКЕН> \
  -v ~/.cache/huggingface:/home/app/.cache/huggingface \
  harbor.xakki.ru/convertor/worker-ai:latest-cuda
```

`WORKER_API_TOKEN` — единственная ОБЯЗАТЕЛЬНАЯ переменная (секрет, дефолта нет и быть не
может). Всё остальное (`GATEWAY_WS_URL`, `API_BASE_URL`, `WORKER_TYPE`, `WORKER_ID`,
`WHISPER_*`, `LLM_BACKEND`) имеет прод-дефолт или автодетект — см. «Переменные окружения».
`--hostname worker-ai` пиннит стабильный `WORKER_ID` между пересозданиями контейнера.
Кэш HuggingFace (`-v …/.cache/huggingface`) — опционален, но избавляет от повторного
скачивания весов моделей.

CPU-хост: тот же вызов с образом `harbor.xakki.ru/convertor/worker-ai:latest-cpu` и БЕЗ
`--gpus all` — `WHISPER_DEVICE`/`WHISPER_COMPUTE_TYPE` автоопределятся в `cpu`/`int8` сами.

**Проверка:**

```bash
docker logs -f worker-ai   # ожидаем: подключение к gateway, "type: ai", устройство (cuda/cpu), без CRITICAL/traceback
docker inspect worker-ai --format '{{.State.Status}} / {{.State.Health.Status}}'   # running / healthy
docker exec worker-ai python3 -c "import workers.ai.worker, webrtcvad; print('LIVE OK')"
```
Healthcheck внутри образа = `import faster_whisper, webrtcvad, workers.common` (start-period 60s).

## Сборка образа

> Для CUDA — единственный путь получить образ (в Harbor не публикуется). Для CPU —
> нужна только как фолбэк для разработки/фикса на этом же хосте: happy path — просто
> `docker pull` (см. «Быстрый старт» выше).

### Двухслойная схема

```
harbor.xakki.ru/convertor/worker-ai-base:latest   ← публикуется в Harbor
  │  FROM scratch — ТОЛЬКО код + requirements, без OS/Python (легковесный, ~0.5 МБ)
  │  содержит: workers/common/ + workers/ai/ + requirements-ai-*.txt
  ▼  COPY --from=aibase /app /app
  ├─ worker-ai:latest-cpu   ← собирается через build-ai-cpu, И публикуется в Harbor
  │    Python + CPU ML-стек (faster-whisper int8, llama.cpp без CUDA)   (release-workers)
  │
  └─ worker-ai:latest-cuda  ← собирается через build-ai-cuda, остаётся ТОЛЬКО ЛОКАЛЬНО
       nvidia/cuda cuDNN runtime + Python + CUDA ML-стек (torch, faster-whisper, …)
       НЕ публикуется в Harbor (большой, привязан к GPU-архитектуре)
```

Весь прикладной код — в `worker-ai-base`; рабочий образ забирает его через
`COPY --from=aibase /app /app`, из контекста сборки код НЕ берётся. Запуску нужен только
сам образ — ни репозитория, ни bind-mount кода (`-v …/convertor:/app` НЕ используется в
проде; он только для локальной разработки и маскирует отсутствие кода в образе, см.
Траблшутинг).

Источники: `docker/workers/ai-base.Dockerfile`, `docker/workers/ai.cuda.Dockerfile`,
`docker/workers/ai.cpu.Dockerfile`, сервис `worker-ai` в `docker-compose.yml`.

### Предпосылки на хосте

- Docker (24+) с BuildKit.
- Для GPU: драйвер NVIDIA + `nvidia-container-toolkit` (флаг `--gpus all`).
- Доступ к Harbor: `docker login harbor.xakki.ru` (нужен только для пути 2b и раздачи базы).
- Сетевой доступ к публичному gateway (`wss://…/ws/worker/`) и к API
  (`https://convertor.xakki.pro`).

### 1. Определить compute capability GPU

```bash
nvidia-smi --query-gpu=compute_cap --format=csv,noheader   # напр. "8.6"
```
`CUDA_ARCH` = это число без точки: `8.6 → 86`, `7.5 → 75`, `8.9 → 89`.

### 2a. Собрать — если репозиторий есть на хосте (проще, обычный путь)

```bash
cd /path/to/convertor
make build-ai-cuda CUDA_ARCH=86
#   не 86 → задать и TORCH_CUDA_ARCH (точечно): make build-ai-cuda CUDA_ARCH=75 TORCH_CUDA_ARCH=7.5
#   встроенный llama.cpp GGUF → WITH_LLAMACPP=1
#   CPU-хост без GPU: make build-ai-cpu
```
Таргет сам сначала выполнит `build-ai-base` (свежий `worker-ai-base:local` из текущих
исходников на хосте), затем соберёт рабочий образ поверх него — ручной `docker pull`
Harbor-тега для этого пути не нужен, свежесть гарантирует сам Makefile.

### 2b. Собрать — без репозитория (нужен только Dockerfile)

Сначала подтянуть свежий base из Harbor:

```bash
docker login harbor.xakki.ru
docker pull harbor.xakki.ru/convertor/worker-ai-base:latest
```
⚠️ Без явного `pull` локально закешированный устаревший `base:latest` будет использован
как есть (`FROM harbor…base:latest` берёт локальный образ, если он есть) — рабочий образ
соберётся из старого кода. Актуально только для этого пути (2b); путь 2a от
Harbor-тега не зависит.

Скопировать на хост один файл `ai.cuda.Dockerfile` (из
`docker/workers/ai.cuda.Dockerfile`), затем — с ПУСТЫМ контекстом сборки:

```bash
docker build -t harbor.xakki.ru/convertor/worker-ai:latest-cuda -f ai.cuda.Dockerfile .
```
(`.` как контекст безвреден — Dockerfile из контекста ничего не копирует. ARG'и
`AI_BASE_IMAGE`/`CUDA_ARCH`/`TORCH_CUDA_ARCH`/`WITH_LLAMACPP` по умолчанию уже равны
`harbor.xakki.ru/convertor/worker-ai-base:latest`/`86`/`8.6`/`0` — для другой GPU-архитектуры
переопределить явно, напр. `--build-arg CUDA_ARCH=75 --build-arg TORCH_CUDA_ARCH=7.5`.
Аналогично `ai.cpu.Dockerfile` для CPU-хоста.)

### 3. Гейт после сборки — образ грузится standalone (обязательно)

Проверка БЕЗ bind-mount — зеркалит прод (ловит отсутствие кода/зависимостей в образе):

```bash
docker run --rm --entrypoint python3 harbor.xakki.ru/convertor/worker-ai:latest-cuda \
  -c "import workers.ai.config, workers.ai.worker, webrtcvad, av, faster_whisper; print('STANDALONE BOOT OK')"
```
Печатает `STANDALONE BOOT OK` → образ валиден. Падает с `ModuleNotFoundError` → база
устарела/битая, вернуться к шагу 2b (`docker pull`).

### Compose-альтернатива запуску

worker-ai — обычный сервис в основном `docker-compose.yml` (не отдельный файл):
заполнить `WORKER_API_TOKEN`/`GATEWAY_WS_URL`/`API_BASE_URL` в `.env.local`
(см. `docs/workers-remote-deploy.md`, блок «Remote worker host») и поднять:
```bash
docker compose up -d --no-deps worker-ai
```
Для GPU — задать `AI_VARIANT=cuda` + `AI_RUNTIME=nvidia` в `.env.local` (образ
переключается на `:cuda`-тег, runtime — на `nvidia`) и раскомментировать блок
`deploy.resources.devices` у сервиса `worker-ai` в `docker-compose.yml`, затем
выставить `WHISPER_DEVICE=cuda`.

`pull_policy` сервиса `worker-ai` управляется отдельной переменной
`AI_PULL_POLICY` (`docker-compose.yml`: `${AI_PULL_POLICY:-missing}`), не
общей `WORKER_PULL_POLICY` остальных пяти воркеров: CPU-хост ставит
`AI_PULL_POLICY=always` (`worker-ai:latest-cpu` публикуется в Harbor, см.
«Быстрый старт»), GPU-хост — ОБЯЗАТЕЛЬНО `AI_PULL_POLICY=build`
(`worker-ai:latest-cuda` в Harbor не публикуется, `always` хардфейлит
«pull access denied»; `missing` спасает только `make up` — фолбэк на build:
при отсутствии локального образа — но НЕ явный `docker compose pull`/
`make pull`: тот build-фолбэка не знает и падает с exit 2 «not found» на
несуществующем теге; `build` заставляет `pull` пропустить сервис, Skipped).
Шаблон `.env.local_worker_example` уже задаёт это верно для обоих случаев.

## Переменные окружения

Читаются в `workers/ai/config.py` (весь `os.getenv` — там) и
`workers/common/ws_client.py`.

### Обязательные (prod worker-режим — подключение к gateway)

| Переменная | Назначение |
|---|---|
| `WORKER_API_TOKEN` | Bearer для WS-upgrade (auth) и прямого HTTP к API. Секрет, дефолта нет и быть не может. Пусто → воркер не стартует (guard в `WsClientConfig.validate()`, без reconnect-storm). |

### Значения по умолчанию / автодетект

| Переменная | Дефолт | Примечание |
|---|---|---|
| `GATEWAY_WS_URL` | `wss://convertor.xakki.pro/ws/worker/` (только worker-режим) | Прод-дефолт передаётся ТОЛЬКО из `workers/ai/worker.py` (worker-режим, `run()` → `_run_with_signals()` → `WsClientConfig.from_env(default_gateway_ws_url=…)`). Общий `WsClientConfig.from_env()` (`workers/common/ws_client.py`) сам по себе дефолта НЕ несёт — это намеренно: devserver-путь (`workers/ai/devserver/ws_runner.py`) вызывает `from_env()` без него, иначе on-server devserver-контейнер начал бы САМ подключаться к прод-gateway. Переопределяется `-e GATEWAY_WS_URL=…`. |
| `API_BASE_URL` | `https://convertor.xakki.pro` (только worker-режим) | Аналогично `GATEWAY_WS_URL` — прод-дефолт только в worker-режиме, без path-компонента. Переопределяется `-e API_BASE_URL=…`. |
| `WORKER_TYPE` | `ai` | Запечён в образ (`ai.cpu.Dockerfile`/`ai.cuda.Dockerfile`, `ENV WORKER_TYPE=ai`) — передавать вручную не нужно, но можно переопределить `-e`/compose. |
| `WORKER_ID` | hostname контейнера | Стабильное имя KeyDB-consumer'а. Если не задан явно, `ws_client.py` берёт фолбэк `_default_worker_id()` (на базе `socket.gethostname()`). Для стабильности между пересозданиями контейнера пиньте hostname (`--hostname worker-ai` в `docker run`) или задайте `WORKER_ID` явно — иначе каждое пересоздание получает новое имя consumer'а (по одному «утёкшему» consumer'у на пересоздание — не ломает работу, но засоряет consumer group). |
| `WHISPER_DEVICE` / `WHISPER_COMPUTE_TYPE` | автодетект: `cuda`+`float16` если GPU виден, иначе `cpu`+`int8` | Автодетект в `workers/ai/config.py` (`_autodetect_device`, ленивый `torch.cuda.is_available()`). Самоисцеление: образ `:cuda` без `--gpus all` автоматически падает на `cpu`/`int8` вместо ошибки. Переопределить явно — `-e WHISPER_DEVICE=cuda\|cpu` (+`WHISPER_COMPUTE_TYPE`). |
| `LLM_BACKEND` | `llamacpp` | Встроенный llama.cpp по GGUF (самодостаточно) или `ollama`. |

### Whisper (STT) / embedding — остальные

| Переменная | Дефолт | Примечание |
|---|---|---|
| `WHISPER_MODEL` | `base` | Размер модели faster-whisper. |
| `EMBEDDING_MODEL` | `Qwen/Qwen3-Embedding-0.6B` | |
| `EMBEDDING_DEVICE` | = `WHISPER_DEVICE` (после автодетекта) | |

### LLM (text→text) — остальные

| Переменная | Дефолт | Примечание |
|---|---|---|
| `OLLAMA_URL` | `http://localhost:11434` | Для `LLM_BACKEND=ollama`; из контейнера — `http://host.docker.internal:11434`. |
| `LLM_MODEL_REPO` / `LLM_MODEL_FILE` | Qwen2.5-0.5B-Instruct Q4_K_M | GGUF для llamacpp. |

Остальные (`STREAM_*`, `VAD_*`, `TTS_ENGINE`, `LLM_MAX_TOKENS`, …) — см.
`workers/ai/config.py`, дефолтов достаточно.

## Обновление при изменении кода/зависимостей

**Remote CPU-хост (обычный случай, без сборки):**
```bash
git pull && make pull && make workers-recreate
```
`worker-ai:latest-cpu` тянется готовым из Harbor вместе с остальными 5 воркерами —
шаги ниже (2a/2b) не нужны на этом хосте. Они остаются актуальны для GPU-хоста
(`worker-ai:cuda` в Harbor не публикуется, только локальная сборка) и для машины,
где готовится релиз (`saFin`, см. `harbor-published-worker-images` §5).

На хосте с репозиторием (путь 2a, обычный случай):
1. `make build-ai-cpu` / `make build-ai-cuda CUDA_ARCH=<cc>` — сам пересобирает свежий
   `worker-ai-base:local` из текущих исходников, отдельный шаг для базы не нужен.
2. Гейт (шаг 3 выше).
3. Пересоздать контейнер (`docker rm -f worker-ai` + `docker run …`, или
   `docker compose up -d --force-recreate --no-deps worker-ai` / `make workers-recreate`
   для всех 6 воркеров сразу).

На хосте без репозитория (путь 2b) или чтобы раздать свежую базу на другой хост:
1. С хоста, где есть исходники: `make push-ai-base` (пересобирает и пушит
   `worker-ai-base` в Harbor).
2. На целевом хосте: `docker pull harbor.xakki.ru/convertor/worker-ai-base:latest`.
3. Пересобрать рабочий образ вручную (шаг 2b) + гейт (шаг 3).
4. Пересоздать контейнер.

## Траблшутинг

| Симптом | Причина | Решение |
|---|---|---|
| `ModuleNotFoundError: No module named 'workers.common'` (крэш-луп) | Устаревший `worker-ai-base` без `workers/common/` — путь 2a (`make build-ai-cpu/cuda`) сам чинит это пересборкой базы; на пути 2b (без репозитория) — не сделан `docker pull` базы. | Путь 2a: пересобрать (`make build-ai-cpu/cuda` снова). Путь 2b: `docker pull` свежей базы → пересборка. Гейт (шаг 3) / HEALTHCHECK ловит это ДО/во время запуска. |
| `ModuleNotFoundError: No module named 'webrtcvad'` | Устаревшая база без `webrtcvad-wheels` (был в старых образах, инцидент 2026-07-18). | Путь 2a теперь исключает это структурно (свежая база при каждой сборке); путь 2b — свежая база + пересборка. |
| `CRITICAL ws-client misconfigured, refusing to start` | Пуст `WORKER_API_TOKEN` (единственная обязательная переменная без дефолта). | Задать `-e WORKER_API_TOKEN=<ТОКЕН>`. |
| Образ «работает» с `-v …/convertor:/app`, но падает без него | Кода нет в образе — bind-mount подсовывал его с хоста, маскируя пробел. | Никогда не полагаться на bind-mount кода в проде; прогонять гейт (шаг 3). |
| GPU не виден в контейнере | Нет `nvidia-container-toolkit` или флага `--gpus all`. | Установить toolkit; проверить `docker run --rm --gpus all nvidia/cuda:12.8.0-base-ubuntu24.04 nvidia-smi`. |

Связанные разборы (kanban): устаревший Harbor-base
(`stale-worker-ai-cpu-image-webrtcvad`), пропуск `workers/common`
(`ai-base-missing-workers-common`), автоматизация свежести базы
(`makefile-ai-base-freshness`).

## См. также

- `docs/queue-contract.md`, `docs/queue-streams.md` — контракт очередей и WS-транспорт
  (воркер — WS-клиент gateway, не трогает KeyDB/S3 напрямую).
- `docker-compose.yml` (сервис `worker-ai`) — актуальный env/volumes/healthcheck.
- `docs/workers-remote-deploy.md` — запуск AI-воркера как части remote-хоста
  (все 6 воркеров разом).
