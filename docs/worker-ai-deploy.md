# Развёртывание AI-воркера (worker-ai) на удалённом хосте

Как собрать и запустить AI-воркер (STT/TTS/embedding/LLM-конвертации) на удалённом
GPU- или CPU-хосте. **Запуск образа не требует репозитория** — весь код уже вшит в
образ.

## Архитектура образов (двухслойная)

```
harbor.xakki.ru/convertor/worker-ai-base:latest   ← публикуется в Harbor
  │  FROM scratch — ТОЛЬКО код + requirements, без OS/Python (легковесный, ~0.5 МБ)
  │  содержит: workers/common/ + workers/ai/ + requirements-ai-*.txt
  ▼  COPY --from=aibase /app /app
xakki-convertor/worker-ai:cuda  (или :cpu)         ← собирается ЛОКАЛЬНО на хосте
     nvidia/cuda cuDNN runtime + Python + CUDA ML-стек (torch, faster-whisper, …)
     НЕ публикуется в Harbor (большой, привязан к GPU-архитектуре)
```

Ключевые следствия:

- **Весь прикладной код — в `worker-ai-base`** (в Harbor). Рабочий образ забирает его
  через `COPY --from=aibase /app /app`; из контекста сборки код НЕ берётся.
- **Сборке рабочего образа нужен только Dockerfile** — но это верно для ручной сборки
  без Makefile (см. 3b) с `AI_BASE_IMAGE` из Harbor. Через `make build-ai-cpu/cuda` (3a,
  обычный путь) репозиторий на хосте всё же нужен: эти таргеты сами пересобирают свежий
  `worker-ai-base` ЛОКАЛЬНО (`AI_BASE_LOCAL`) из исходников перед сборкой рабочего
  образа — см. «Сборка рабочего образа» ниже.
- **Запуску нужен только сам образ** — ни репозитория, ни bind-mount кода
  (`-v …/convertor:/app` НЕ используется в проде; он только для локальной разработки и
  маскирует отсутствие кода в образе, см. Траблшутинг).

Источники: `docker/workers/ai-base.Dockerfile`, `docker/workers/ai.cuda.Dockerfile`,
`docker/workers/ai.cpu.Dockerfile`, шапка `docker-compose.worker-ai.yml`.

## Предпосылки на хосте

- Docker (24+) с BuildKit.
- Для GPU: драйвер NVIDIA + `nvidia-container-toolkit` (флаг `--gpus all`).
- Доступ к Harbor: `docker login harbor.xakki.ru`.
- Сетевой доступ к публичному gateway (`wss://…/ws/worker/`) и к API
  (`https://convertor.xakki.pro`).

## Сборка рабочего образа

> Пересобирать при изменении кода/зависимостей. **Через `make build-ai-cpu`/
> `build-ai-cuda` (путь 3a) это происходит автоматически** — оба таргета зависят от
> `build-ai-base` и сначала пересобирают локальный `worker-ai-base:local` из текущих
> исходников, только потом собирают рабочий образ поверх него. Ручной `docker pull`
> Harbor-тега для этого пути НЕ нужен и на итоговый образ не влияет. `docker pull` нужен
> только для пути 3b (сборка без репозитория — там `AI_BASE_IMAGE` берётся из Harbor
> напрямую) и для распространения базы на хосты, которые сами не собирают её из
> исходников (см. «Обновление при изменении кода/зависимостей» ниже).

### 1. Подтянуть свежий base из Harbor (нужно только для пути 3b, без репозитория)

```bash
docker login harbor.xakki.ru
docker pull harbor.xakki.ru/convertor/worker-ai-base:latest
```

⚠️ Без явного `pull` локально закешированный устаревший `base:latest` будет использован
как есть (`FROM harbor…base:latest` берёт локальный образ, если он есть) — и рабочий
образ соберётся из старого кода. Актуально ТОЛЬКО для сборки без Makefile (3b); путь 3a
(`make build-ai-cpu/cuda`) от Harbor-тега не зависит — свежесть гарантирует
`build-ai-base` в самом Makefile.

### 2. Определить compute capability GPU

```bash
nvidia-smi --query-gpu=compute_cap --format=csv,noheader   # напр. "8.6"
```
`CUDA_ARCH` = это число без точки: `8.6 → 86`, `7.5 → 75`, `8.9 → 89`.

### 3a. Собрать — если репозиторий есть на хосте (проще)

```bash
cd /path/to/convertor
make build-ai-cuda CUDA_ARCH=86
#   не 86 → задать и TORCH_CUDA_ARCH (точечно): make build-ai-cuda CUDA_ARCH=75 TORCH_CUDA_ARCH=7.5
#   встроенный llama.cpp GGUF → WITH_LLAMACPP=1
#   CPU-хост без GPU: make build-ai-cpu
```
Таргет сам сначала выполнит `build-ai-base` (свежий `worker-ai-base:local` из текущих
исходников на хосте), затем соберёт рабочий образ поверх него — шаг 1 (pull) для этого
пути не нужен.

### 3b. Собрать — без репозитория (нужен только Dockerfile)

Скопировать на хост один файл `ai.cuda.Dockerfile` (из
`docker/workers/ai.cuda.Dockerfile`), затем — с ПУСТЫМ контекстом сборки:

```bash
docker build -t xakki-convertor/worker-ai:cuda \
  --build-arg AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:latest \
  --build-arg CUDA_ARCH=86 \
  --build-arg TORCH_CUDA_ARCH=8.6 \
  --build-arg WITH_LLAMACPP=0 \
  -f ai.cuda.Dockerfile .
```
(`.` как контекст безвреден — Dockerfile из контекста ничего не копирует. Аналогично
`ai.cpu.Dockerfile` для CPU-хоста.)

### 4. Гейт после сборки — образ грузится standalone (обязательно)

Проверка БЕЗ bind-mount — зеркалит прод (ловит отсутствие кода/зависимостей в образе):

```bash
docker run --rm --entrypoint python3 xakki-convertor/worker-ai:cuda \
  -c "import workers.ai.config, workers.ai.worker, webrtcvad, av, faster_whisper; print('STANDALONE BOOT OK')"
```
Печатает `STANDALONE BOOT OK` → образ валиден. Падает с `ModuleNotFoundError` → база
устарела/битая, вернуться к шагу 1.

## Запуск (без репозитория, без bind-mount кода)

Единственный опциональный том — кэш HuggingFace (данные модели, не код), чтобы не
скачивать веса заново:

```bash
docker rm -f worker-ai 2>/dev/null || true    # если уже запущен старый

docker run -d --name worker-ai --hostname worker-ai --restart unless-stopped --gpus all \
  -e WORKER_API_TOKEN=<ТОКЕН> \
  -v ~/.cache/huggingface:/home/app/.cache/huggingface \
  xakki-convertor/worker-ai:cuda
  # Опциональные переопределения (по умолчанию — прод-дефолты/автодетект, см. таблицы ниже):
  #   -e GATEWAY_WS_URL=wss://<другой-gateway>/ws/worker/ \
  #   -e API_BASE_URL=https://<другой-api> \
  #   -e WHISPER_DEVICE=cuda -e WHISPER_COMPUTE_TYPE=float16 \
  #   -e LLM_BACKEND=llamacpp \
```

`WORKER_API_TOKEN` — единственная ОБЯЗАТЕЛЬНАЯ переменная (секрет, дефолта нет и быть не
может — guard в `WsClientConfig.validate()` не даёт стартовать без токена). Остальное
(`GATEWAY_WS_URL`, `API_BASE_URL`, `WHISPER_*`) теперь имеет прод-дефолт/автодетект —
задавать вручную нужно только для переопределения (см. «Больше НЕ обязательные» ниже).

CPU-хост: образ `:cpu`, без `--gpus all` — `WHISPER_DEVICE`/`WHISPER_COMPUTE_TYPE`
автоопределятся в `cpu`/`int8` сами (torch не увидит GPU).

`--hostname worker-ai` пиннит стабильный `WORKER_ID` (см. ниже) между пересозданиями
контейнера. Без пиннинга hostname'а (или явного `-e WORKER_ID=…`) каждое пересоздание
контейнера получает НОВОЕ имя KeyDB-consumer'а — по одному «утёкшему» consumer'у на
пересоздание (не ломает работу, но засоряет consumer group).

### Compose-альтернатива

```bash
cp .env.worker-ai.example .env.worker-ai      # заполнить GATEWAY_WS_URL + WORKER_API_TOKEN + API_BASE_URL
docker compose -f docker-compose.worker-ai.yml --env-file .env.worker-ai up -d
```
Для GPU — раскомментировать блок `deploy.resources.devices` в
`docker-compose.worker-ai.yml` и выставить `WHISPER_DEVICE=cuda`.

## Переменные окружения

Читаются в `workers/ai/config.py` (весь `os.getenv` — там) и
`workers/common/ws_client.py`.

### Обязательные (prod worker-режим — подключение к gateway)

| Переменная | Назначение |
|---|---|
| `WORKER_API_TOKEN` | Bearer для WS-upgrade (auth) и прямого HTTP к API. Секрет, дефолта нет и быть не может. Пусто → воркер не стартует (guard в `WsClientConfig.validate()`, без reconnect-storm). |

### Больше НЕ обязательные

| Переменная | Дефолт | Примечание |
|---|---|---|
| `GATEWAY_WS_URL` | `wss://convertor.xakki.pro/ws/worker/` (только worker-режим) | Прод-дефолт передаётся ТОЛЬКО из `workers/ai/worker.py` (worker-режим, `run()` → `_run_with_signals()` → `WsClientConfig.from_env(default_gateway_ws_url=…)`). Общий `WsClientConfig.from_env()` (`workers/common/ws_client.py`) сам по себе дефолта НЕ несёт — это намеренно: devserver-путь (`workers/ai/devserver/ws_runner.py`) вызывает `from_env()` без него, иначе on-server devserver-контейнер начал бы САМ подключаться к прод-gateway. Переопределяется `-e GATEWAY_WS_URL=…`. |
| `API_BASE_URL` | `https://convertor.xakki.pro` (только worker-режим) | Аналогично `GATEWAY_WS_URL` — прод-дефолт только в worker-режиме, без path-компонента. Переопределяется `-e API_BASE_URL=…`. |
| `WORKER_TYPE` | `ai` | Запечён в образ (`ai.cpu.Dockerfile`/`ai.cuda.Dockerfile`, `ENV WORKER_TYPE=ai`) — передавать вручную больше не нужно, но можно переопределить `-e`/compose. |
| `WORKER_ID` | hostname контейнера | Стабильное имя KeyDB-consumer'а. Если не задан явно, `ws_client.py` берёт `socket.gethostname()`. Для стабильности между пересозданиями контейнера пиньте hostname (`--hostname worker-ai` в `docker run` выше) или задайте `WORKER_ID` явно — иначе каждое пересоздание получает новое имя consumer'а (см. заметку после команды запуска). |

### Whisper (STT) / устройство

`WHISPER_DEVICE`/`WHISPER_COMPUTE_TYPE` теперь АВТООПРЕДЕЛЯЮТСЯ в `workers/ai/config.py`
(`_autodetect_device`, ленивый `torch.cuda.is_available()`): `cuda`+`float16`, если GPU
виден, иначе `cpu`+`int8`. Самоисцеление: образ `:cuda`, запущенный БЕЗ `--gpus all`,
автоматически падает обратно на `cpu`/`int8` (torch не увидит GPU) вместо падения/ошибки.

| Переменная | Дефолт | Примечание |
|---|---|---|
| `WHISPER_DEVICE` | автодетект (`cuda`/`cpu`) | Переопределить явно — `-e WHISPER_DEVICE=cuda\|cpu`. |
| `WHISPER_COMPUTE_TYPE` | автодетект (`float16`/`int8`) | Следует за `WHISPER_DEVICE`, если не задан явно. |
| `WHISPER_MODEL` | `base` | Размер модели faster-whisper. |
| `EMBEDDING_MODEL` | `Qwen/Qwen3-Embedding-0.6B` | |
| `EMBEDDING_DEVICE` | = `WHISPER_DEVICE` (после автодетекта) | |

### LLM (text→text)

| Переменная | Дефолт | Примечание |
|---|---|---|
| `LLM_BACKEND` | `llamacpp` | Встроенный llama.cpp по GGUF (самодостаточно) или `ollama`. |
| `OLLAMA_URL` | `http://localhost:11434` | Для `LLM_BACKEND=ollama`; из контейнера — `http://host.docker.internal:11434`. |
| `LLM_MODEL_REPO` / `LLM_MODEL_FILE` | Qwen2.5-0.5B-Instruct Q4_K_M | GGUF для llamacpp. |

Остальные (`STREAM_*`, `VAD_*`, `TTS_ENGINE`, `LLM_MAX_TOKENS`, …) — см.
`workers/ai/config.py`, дефолтов достаточно.

## Проверка после запуска

```bash
docker inspect worker-ai --format '{{.State.Status}} / {{.State.Health.Status}}'   # running / healthy
docker logs -f worker-ai                                                            # старт + подключение к gateway, без traceback
docker exec worker-ai python3 -c "import workers.ai.worker, webrtcvad; print('LIVE OK')"
```
Healthcheck внутри образа = `import faster_whisper, webrtcvad, workers.common`
(start-period 60s).

## Обновление при изменении кода/зависимостей

На хосте с репозиторием (путь 3a, обычный случай):
1. `make build-ai-cpu` / `make build-ai-cuda CUDA_ARCH=<cc>` — сам пересобирает свежий
   `worker-ai-base:local` из текущих исходников, отдельный шаг для базы не нужен.
2. Гейт (шаг 4 выше).
3. Пересоздать контейнер (`docker rm -f worker-ai` + `docker run …`, или
   `--force-recreate` через compose / `make worker-ai-recreate`).

На хосте без репозитория (путь 3b) или чтобы раздать свежую базу на другой хост:
1. С хоста, где есть исходники: `make push-ai-base` (пересобирает и пушит
   `worker-ai-base` в Harbor).
2. На целевом хосте: `docker pull harbor.xakki.ru/convertor/worker-ai-base:latest`.
3. Пересобрать рабочий образ вручную (шаг 3b) + гейт (шаг 4).
4. Пересоздать контейнер.

## Траблшутинг

| Симптом | Причина | Решение |
|---|---|---|
| `ModuleNotFoundError: No module named 'workers.common'` (крэш-луп) | Устаревший `worker-ai-base` без `workers/common/` — путь 3a (`make build-ai-cpu/cuda`) сам чинит это пересборкой базы; на пути 3b (без репозитория) — не сделан `docker pull` базы. | Путь 3a: пересобрать (`make build-ai-cpu/cuda` снова). Путь 3b: `docker pull` свежей базы (шаг 1) → пересборка. Гейт шага 4 / HEALTHCHECK ловит это ДО/во время запуска. |
| `ModuleNotFoundError: No module named 'webrtcvad'` | Устаревшая база без `webrtcvad-wheels` (был в старых образах, инцидент 2026-07-18). | Путь 3a теперь исключает это структурно (свежая база при каждой сборке); путь 3b — свежая база + пересборка. |
| `CRITICAL ws-client misconfigured, refusing to start` | Пуст `WORKER_API_TOKEN` (единственная обязательная переменная без дефолта). | Задать `-e WORKER_API_TOKEN=<ТОКЕН>`. |
| Образ «работает» с `-v …/convertor:/app`, но падает без него | Кода нет в образе — bind-mount подсовывал его с хоста, маскируя пробел. | Никогда не полагаться на bind-mount кода в проде; прогонять гейт шага 4. |
| GPU не виден в контейнере | Нет `nvidia-container-toolkit` или флага `--gpus all`. | Установить toolkit; проверить `docker run --rm --gpus all nvidia/cuda:12.8.0-base-ubuntu24.04 nvidia-smi`. |

Связанные разборы (kanban): устаревший Harbor-base
(`stale-worker-ai-cpu-image-webrtcvad`), пропуск `workers/common`
(`ai-base-missing-workers-common`), автоматизация свежести базы
(`makefile-ai-base-freshness`).

## См. также

- `docs/queue-contract.md`, `docs/queue-streams.md` — контракт очередей и WS-транспорт
  (воркер — WS-клиент gateway, не трогает KeyDB/S3 напрямую).
- `docker-compose.worker-ai.yml` — эталонные инструкции сборки/запуска в шапке.
