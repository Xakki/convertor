---
name: worker-ai-image
description: >-
  Как собирать и деплоить Docker-образ AI-воркера convertor (worker-ai): двухслойная
  схема worker-ai-base (Harbor, весь код) → рабочий образ :cuda/:cpu (собирается локально
  из базы). Триггеры: build-ai-base/build-ai-cpu/build-ai-cuda, push-ai-base,
  worker-ai-recreate, запуск AI-воркера на GPU/CPU-хосте, обновить образ воркера.
  КРИТИЧНЫЕ грабли: ModuleNotFoundError workers.common (крэш-луп), ModuleNotFoundError
  webrtcvad, устаревший worker-ai-base в Harbor, образ работает с bind-mount но падает
  без него. Источники: docker/workers/ai-base.Dockerfile, ai.cpu.Dockerfile,
  ai.cuda.Dockerfile, workers/Makefile, docs/worker-ai-deploy.md.
---

# Сборка и деплой образа AI-воркера (worker-ai)

Полная инструкция запуска на удалённом хосте — **`docs/worker-ai-deploy.md`** (env-таблицы,
compose, run-команды). Здесь — суть + грабли/гейты, чтобы сборка не выдала битый образ.

> Перед опорой на факт отсюда — сверься с источником (Dockerfile'ы + `workers/Makefile`);
> нашёл расхождение → правь скилл в том же изменении и сообщи team-lead.

## Суть: двухслойная схема

- **`worker-ai-base`** (`docker/workers/ai-base.Dockerfile`, `FROM scratch`) — публикуется в
  **Harbor** (`harbor.xakki.ru/convertor/worker-ai-base:latest`). Содержит ТОЛЬКО код
  (`workers/common/` + `workers/ai/`) + `requirements-ai-*.txt`. Лёгкий (~0.5 МБ).
- **Рабочий образ** `xakki-convertor/worker-ai:cuda|:cpu` (`ai.cuda.Dockerfile` /
  `ai.cpu.Dockerfile`) — собирается **локально** на хосте, тянет код через
  `COPY --from=aibase /app /app` + ставит OS/Python/ML-стек. В Harbor НЕ публикуется.
- Код в рабочий образ приходит ТОЛЬКО из базы; из контекста сборки — ничего. Значит
  сборке нужен лишь Dockerfile (не репозиторий), запуску — только образ (без bind-mount кода).

Make-таргеты (`workers/Makefile`): `build-ai-base`, `push-ai-base`, `build-ai-cpu`,
`build-ai-cuda [CUDA_ARCH=86 TORCH_CUDA_ARCH=8.6 WITH_LLAMACPP=0]`,
`worker-ai-up` / `worker-ai-recreate` / `worker-ai-down` (on-server CPU dev-server).

## КРИТИЧНЫЕ грабли и гейты (все всплыли 2026-07-18)

1. **Устаревший base в Harbor → рабочий образ из старого кода.** `ai.cpu/cuda.Dockerfile`
   берут `FROM harbor…/worker-ai-base:latest`. BuildKit использует локальный кеш этого
   тега (или тянет из Harbor) — если он устарел, образ соберётся из старого
   `requirements`/кода, ДАЖЕ с `--no-cache`. Признаки: пропал `webrtcvad`, старый
   `requirements-ai-ml.txt` в `/app`.
   **Правило:** менял код/requirements → `make build-ai-base && make push-ai-base` (перезалить
   в Harbor) ПЕРЕД `build-ai-cpu/cuda`. На хосте — `docker pull …worker-ai-base:latest` перед
   сборкой. Разово в обход реестра: `build-ai-cpu --build-arg AI_BASE_IMAGE=<локальный-тег
   без registry-префикса>`.

2. **`ai-base` обязан бандлить `workers/common/`.** `workers/ai` импортит `workers.common`
   (`config.py`, `worker.py`, `devserver/ws_runner.py`). `ai-base.Dockerfile` копирует и
   `COPY workers/common/ …`, и `COPY workers/ai/ …`. Без первого — контейнер крэш-лупит:
   `ModuleNotFoundError: No module named 'workers.common'`. (Паттерн — как в
   `gateway.Dockerfile` / `ffmpeg.Dockerfile`.)

3. **STANDALONE-гейт после сборки (обязателен).** Все прогоны/тесты с bind-mount
   `-v …/convertor:/app` подсовывают код с хоста и МАСКИРУЮТ его отсутствие в образе; прод
   стартует чисто из вшитого `/app` — там и вылезает крэш. После сборки, БЕЗ mount:
   ```bash
   docker run --rm --entrypoint python3 xakki-convertor/worker-ai:cpu \
     -c "import workers.ai.config, workers.ai.worker, webrtcvad, av, faster_whisper; print('OK')"
   ```
   Печатает `OK` → образ валиден. `ModuleNotFoundError` → база устарела/битая (грабли 1–2).

## On-server пересоздание (saFin CPU dev-server)

Прод-контейнер `xakki-convertor-worker-ai` использует 4 compose-файла (из `.env.local`
`COMPOSE_FILE`): base + worker-ai + fluent-logging + limits. `worker-ai-recreate` пересоздаёт
ТОЛЬКО worker-ai на свежий образ, сохраняя fluentd-логи + memory/cpu-лимиты:
```bash
make worker-ai-recreate        # $(WORKER_AI_DC) up -d --force-recreate --no-deps worker-ai
```
Лимиты/логи worker-ai — инлайн в `docker-compose.worker-ai.yml` (не в `docker/limits.yml`).

## Обновление образа (чек-лист)

1. `make build-ai-base && make push-ai-base` (перезалить базу — иначе хосты возьмут старую).
2. Хост: `docker pull …worker-ai-base:latest`.
3. `make build-ai-cpu` (saFin) / `make build-ai-cuda CUDA_ARCH=<cc>` (GPU-хост).
4. STANDALONE-гейт (см. выше).
5. Пересоздать: `make worker-ai-recreate` (saFin) или `docker rm -f worker-ai` + `docker run …`.

## Связанное

- `docs/worker-ai-deploy.md` — полный запуск (env, compose, GPU, траблшутинг).
- kanban-находки: `stale-worker-ai-cpu-image-webrtcvad`, `ai-base-missing-workers-common`,
  `makefile-ai-base-freshness` (todo — автоматизировать свежесть базы + standalone-гейт в CI/healthcheck).
- Роль воркера в транспорте — скиллы `backend-architecture`, `e2e-ws-transport-stack`,
  `docs/queue-contract.md` (воркер = WS-клиент gateway, не трогает KeyDB/S3 напрямую).
