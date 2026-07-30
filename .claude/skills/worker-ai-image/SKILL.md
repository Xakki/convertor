---
name: worker-ai-image
description: >-
  Как собирать и деплоить Docker-образ AI-воркера convertor (worker-ai, обычный сервис
  в docker-compose.yml): двухслойная схема worker-ai-base (Harbor, весь код) → рабочий
  образ worker-ai:latest-cuda/:latest-cpu (собирается локально из базы; :latest-cpu с
  2026-07-30 также публикуется в Harbor через release-workers, :latest-cuda остаётся
  только локальным). Триггеры: build-ai-base/build-ai-cpu/build-ai-cuda, push-ai-base,
  release-workers, workers-recreate, запуск AI-воркера на GPU/CPU-хосте, обновить образ
  воркера. КРИТИЧНЫЕ грабли: ModuleNotFoundError workers.common (крэш-луп),
  ModuleNotFoundError webrtcvad, устаревший worker-ai-base в Harbor, образ работает с
  bind-mount но падает без него. Источники: docker/workers/ai-base.Dockerfile,
  ai.cpu.Dockerfile, ai.cuda.Dockerfile, workers/Makefile, docs/worker-ai-deploy.md.
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
- **Рабочий образ** `worker-ai:latest-cuda` / `worker-ai:latest-cpu` (`ai.cuda.Dockerfile` /
  `ai.cpu.Dockerfile`) — собирается **локально** на хосте, тянет код через
  `COPY --from=aibase /app /app` + ставит OS/Python/ML-стек. **CPU-вариант с
  `harbor-published-worker-images` публикуется в Harbor** (`release-workers` →
  `worker-ai:<ver>-cpu` + `:latest-cpu`) — remote CPU-хосты теперь пуллят его готовым.
  **CUDA-вариант в Harbor НЕ публикуется** (GPU-архитектура хоста-специфична) — остаётся
  локальной сборкой на GPU-хосте по этому же флоу.
- Код в рабочий образ приходит ТОЛЬКО из базы; из контекста сборки — ничего. Значит
  сборке нужен лишь Dockerfile (не репозиторий), запуску — только образ (без bind-mount кода).

Make-таргеты (`workers/Makefile`): `build-ai-base` (тегирует ОБА тега — Harbor
`AI_BASE_IMAGE` и локальный `AI_BASE_LOCAL` — за один build), `push-ai-base` (только push
`AI_BASE_IMAGE` в Harbor — для раздачи на другие хосты), `build-ai-cpu`, `build-ai-cuda
[CUDA_ARCH=86 TORCH_CUDA_ARCH=8.6 WITH_LLAMACPP=0]` (оба зависят от `build-ai-base` и
передают `--build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL)`). worker-ai — обычный сервис
`docker-compose.yml`, пересоздаётся `workers-recreate` (все 6 воркеров) или напрямую
`docker compose up -d --force-recreate --no-deps worker-ai` (только он один).

## ⚠️ ВАЖНО: запускать AI-таргеты ТОЛЬКО из корня репо

Таргеты для AI-воркера — **`build-ai-base`, `push-ai-base`, `build-ai-cpu`, `build-ai-cuda`, `workers-recreate`** — ОБЯЗАТЕЛЬНО запускаются из корня репо:
```bash
make push-ai-base        # ✓ правильно
make -C workers push-ai-base  # ✗ НЕПРАВИЛЬНО — Dockerfile-пути не разрешатся
```

Причина: `workers/Makefile` инклюдится в корневой `Makefile` (строка 1); при запуске с `-C workers` пути вроде `docker/workers/ai-base.Dockerfile` разрешаются относительно `workers/`, файл не найдётся, сборка упадёт. Из корня пути правильные.

## КРИТИЧНЫЕ грабли и гейты (грабли 2–3 всплыли 2026-07-18, грабля 1 закрыта
`makefile-ai-base-freshness` — см. ниже)

1. **(ЗАКРЫТО автоматизацией) Устаревший base в Harbor → рабочий образ из старого кода.**
   Раньше `ai.cpu/cuda.Dockerfile` тянули `FROM harbor…/worker-ai-base:latest` напрямую, и
   протухший Harbor-тег (или его локальный кеш) молча давал образ из старого
   `requirements`/кода — так 2026-07-18 пропал `webrtcvad`. Теперь `build-ai-cpu` и
   `build-ai-cuda` **сами зависят от `build-ai-base`** и всегда сначала пересобирают
   `AI_BASE_LOCAL` (`worker-ai-base:local`, БЕЗ registry-префикса, только что из исходников)
   — Harbor-тег в рабочую сборку вообще не участвует. `push-ai-base` остаётся отдельным шагом
   и нужен ТОЛЬКО чтобы раздать свежую базу на другие хосты (remote-воркеры тянут
   `AI_BASE_IMAGE` из Harbor через `docker pull`).

2. **`ai-base` обязан бандлить `workers/common/`.** `workers/ai` импортит `workers.common`
   (`config.py`, `worker.py`, `devserver/ws_runner.py`). `ai-base.Dockerfile` копирует и
   `COPY workers/common/ …`, и `COPY workers/ai/ …`. Без первого — контейнер крэш-лупит:
   `ModuleNotFoundError: No module named 'workers.common'`. (Паттерн — как в
   `gateway.Dockerfile` / `ffmpeg.Dockerfile`.)

3. **STANDALONE-гейт после сборки (обязателен).** Все прогоны/тесты с bind-mount
   `-v …/convertor:/app` подсовывают код с хоста и МАСКИРУЮТ его отсутствие в образе; прод
   стартует чисто из вшитого `/app` — там и вылезает крэш. После сборки, БЕЗ mount:
   ```bash
   docker run --rm --entrypoint python3 harbor.xakki.ru/convertor/worker-ai:latest-cpu \
     -c "import workers.ai.config, workers.ai.worker, webrtcvad, av, faster_whisper; print('OK')"
   ```
   Печатает `OK` → образ валиден. `ModuleNotFoundError` → база устарела/битая (грабли 1–2).
   Этот же набор (`faster_whisper, webrtcvad, workers.common`) теперь встроен и в
   `HEALTHCHECK` обоих Dockerfile'ов — контейнер сам уходит в `unhealthy`, если что-то из
   этого пропало из образа (доп. страховка сверх ручного гейта выше).

## On-server пересоздание (saFin CPU dev-server)

worker-ai — обычный сервис `docker-compose.yml`, поднимается тем же `COMPOSE_FILE`
(`docker-compose.yml:docker/fluent-logging.yml:docker/limits.yml`), что и остальные 5
воркеров: лимиты (`deploy.resources.limits`) — инлайн в самом сервисе, логи — через
общий `x-logging` в `docker/fluent-logging.yml` (`log_format: "auto"`, как у прочих
python-воркеров). Пересоздать ТОЛЬКО worker-ai на свежий образ:
```bash
docker compose up -d --force-recreate --no-deps worker-ai
```
Или все 6 воркеров разом (из корня репо — см. предупреждение выше):
```bash
make workers-recreate
```

## Обновление образа (чек-лист)

На локальном/on-server хосте (обычный случай — код уже здесь, свежую базу собирать
незачем тянуть из Harbor):
1. `make build-ai-cpu` (saFin) / `make build-ai-cuda CUDA_ARCH=<cc>` (GPU-хост) — сама
   пересоберёт свежий `AI_BASE_LOCAL` из текущих исходников (см. грабля 1) перед сборкой
   рабочего образа.
2. STANDALONE-гейт (см. выше).
3. Пересоздать: `docker compose up -d --force-recreate --no-deps worker-ai` (saFin, только он один; или `make workers-recreate` для всех 6) либо `docker rm -f worker-ai` + `docker run …`.

Чтобы раздать свежую базу на хост БЕЗ репозитория (ручная сборка Dockerfile'ом напрямую,
`AI_BASE_IMAGE=<Harbor-тег>`, без Makefile) — отдельно: `make push-ai-base` (пушит
`AI_BASE_IMAGE` в Harbor), затем на том хосте `docker pull …worker-ai-base:latest` перед
ручным `docker build`. Если на хосте ЕСТЬ репозиторий и он собирает через
`make build-ai-cpu/cuda` — `push-ai-base`/`docker pull` ему не нужны вообще: свежесть там
даёт `git pull` исходников, а Harbor-тег в эту сборку не участвует (см. грабля 1).

## Связанное

- `docs/worker-ai-deploy.md` — полный запуск (env, compose, GPU, траблшутинг).
- kanban-находки: `stale-worker-ai-cpu-image-webrtcvad`, `ai-base-missing-workers-common`
  (обе закрыты), `makefile-ai-base-freshness` (реализовано в `workers/Makefile`:
  `build-ai-cpu`/`build-ai-cuda` зависят от `build-ai-base` и всегда используют свежий
  `AI_BASE_LOCAL`; см. грабля 1 выше).
- Роль воркера в транспорте — скиллы `backend-architecture`, `e2e-ws-transport-stack`,
  `docs/queue-contract.md` (воркер = WS-клиент gateway, не трогает KeyDB/S3 напрямую).
