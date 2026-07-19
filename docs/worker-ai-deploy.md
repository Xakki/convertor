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
- **Сборке рабочего образа нужен только Dockerfile**, не весь репозиторий (контекст
  сборки не используется для кода).
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

> Пересобирать при изменении кода/зависимостей. Код обновляется через **пересборку и
> перезалив `worker-ai-base` в Harbor** (обычно с сервера-сборщика/CI), затем на хосте —
> `docker pull` базы + локальная пересборка рабочего образа.

### 1. Подтянуть свежий base из Harbor (КРИТИЧНО)

```bash
docker login harbor.xakki.ru
docker pull harbor.xakki.ru/convertor/worker-ai-base:latest
```

⚠️ Без явного `pull` локально закешированный устаревший `base:latest` будет использован
как есть (`FROM harbor…base:latest` берёт локальный образ, если он есть) — и рабочий
образ соберётся из старого кода. Это ровно тот класс багов, что описан в Траблшутинге.

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

docker run -d --name worker-ai --restart unless-stopped --gpus all \
  -e GATEWAY_WS_URL=wss://<gateway-host>/ws/worker/ \
  -e WORKER_API_TOKEN=<ТОКЕН> \
  -e API_BASE_URL=https://convertor.xakki.pro \
  -e WHISPER_DEVICE=cuda -e WHISPER_COMPUTE_TYPE=float16 \
  -e LLM_BACKEND=llamacpp \
  -v ~/.cache/huggingface:/home/app/.cache/huggingface \
  xakki-convertor/worker-ai:cuda
```

CPU-хост: образ `:cpu`, без `--gpus all`, `WHISPER_DEVICE=cpu WHISPER_COMPUTE_TYPE=int8`.

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
| `GATEWAY_WS_URL` | Публичный WS gateway: `wss://…/ws/worker/`. Пусто → воркер не стартует (guard, без reconnect-storm). |
| `WORKER_API_TOKEN` | Bearer для WS-upgrade (auth) и прямого HTTP к API. Секрет. |
| `API_BASE_URL` | База Symfony API (GET входного файла / POST крупного результата), напр. `https://convertor.xakki.pro`. Без path-компонента. |

### Whisper (STT) / устройство

| Переменная | Дефолт | Примечание |
|---|---|---|
| `WHISPER_DEVICE` | `cpu` | В cuda-образе выставлен `cuda`. |
| `WHISPER_COMPUTE_TYPE` | `int8` | В cuda-образе `float16`. |
| `WHISPER_MODEL` | `base` | Размер модели faster-whisper. |
| `EMBEDDING_MODEL` | `Qwen/Qwen3-Embedding-0.6B` | |
| `EMBEDDING_DEVICE` | = `WHISPER_DEVICE` | |

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
Healthcheck внутри образа = `import faster_whisper` (start-period 60s).

## Обновление при изменении кода/зависимостей

1. Пересобрать и **перезалить `worker-ai-base` в Harbor** (сборщик/CI):
   `make build-ai-base && make push-ai-base` (без `push-ai-base` хосты возьмут старую базу).
2. На хосте: `docker pull harbor.xakki.ru/convertor/worker-ai-base:latest`.
3. Пересобрать рабочий образ (шаг 3) + гейт (шаг 4).
4. Пересоздать контейнер (`docker rm -f worker-ai` + `docker run …`, или
   `--force-recreate` через compose).

## Траблшутинг

| Симптом | Причина | Решение |
|---|---|---|
| `ModuleNotFoundError: No module named 'workers.common'` (крэш-луп) | Устаревший `worker-ai-base` без `workers/common/` (образ собран до фикса, либо не сделан `docker pull` базы). | `docker pull` свежей базы (шаг 1) → пересборка. Гейт шага 4 ловит это ДО запуска. |
| `ModuleNotFoundError: No module named 'webrtcvad'` | Устаревшая база без `webrtcvad-wheels` (был в старых образах). | То же: свежая база + пересборка. |
| `CRITICAL ws-client misconfigured, refusing to start` | Пуст `GATEWAY_WS_URL` или `WORKER_API_TOKEN`. | Задать обе переменные (обязательные). |
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
