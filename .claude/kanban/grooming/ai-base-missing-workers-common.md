### ai-base.Dockerfile не бандлил workers/common → свежие AI-образы крэш-лупят

**Критичность:** High (любой свежесобранный образ worker-ai падает на старте)

**TAGS:**
- ai-worker
- docker
- devops

**Суть:**
`docker/workers/ai-base.Dockerfile` копировал только `COPY workers/ai/ /app/workers/ai/`,
но текущий код `workers/ai` импортит `workers.common` в нескольких местах
(`config.py:20` → `from workers.common.env`, `worker.py:23` → `workers.common.ws_client`,
`devserver/ws_runner.py:56`). Пакет `workers/common/` в образ НЕ попадал → контейнер
падал на старте циклически:
```
ModuleNotFoundError: No module named 'workers.common'
  File "/app/workers/ai/__main__.py", line 18, in <module>
    from workers.ai.config import load_config
```
Соседние worker-образы (`gateway.Dockerfile:29`, `ffmpeg.Dockerfile:34`) давно делают
`COPY --chown=app:app workers/common/ /app/workers/common/` — в ai-base этого не было.

**Почему не всплывало раньше:** все прогоны/тесты AI-воркера шли с bind-mount
`-v /home/xakki/convertor:/app`, который подсовывал `workers/common` с хоста и маскировал
пробел. Прод-контейнер — ПЕРВОЕ, что стартует чисто из вшитого `/app` без mount, поэтому
крэш вылез только там. Старый прод-образ (`240cdb97`) работал, т.к. собран до того, как
`workers/ai` начал импортить `workers.common`.

**Фикс (сделан 2026-07-18, ветка `task/verify-webm-harness`):**
- `ai-base.Dockerfile`: добавлен `COPY workers/common/ /app/workers/common/` перед
  `COPY workers/ai/` (без `--chown` — образ `FROM scratch`, нет юзера).
- Проверено: base содержит `/app/workers/common/env.py`; standalone boot-импорт
  (`workers.ai.config` + `worker` + `devserver.app`) БЕЗ bind-mount проходит.

**Остаток / уроки:**
- **CUDA-образ на GPU-хосте** обязан быть пересобран с ЭТИМ фиксом (не только webrtcvad).
- **Гейт на будущее:** проверять образ AI-воркера STANDALONE (без `-v …:/app`) —
  `docker run --entrypoint python3 <img> -c "import workers.ai.devserver.app"` — прежде
  чем считать сборку валидной. Кандидат в HEALTHCHECK / CI (см. todo
  [[makefile-ai-base-freshness]]).
- Base в Harbor перезалит с фиксом (прежний `db483ccb` был без `workers/common`).

**Найдено при:** пересборка/редеплой прода в задаче
[[verify-webm-harness-rewrite]] (2026-07-18). Связано с
[[stale-worker-ai-cpu-image-webrtcvad]].

**Status:** grooming — код-фикс применён и проверен; остаётся CUDA-ребилд на GPU-хосте
+ решение по standalone-гейту (свести с [[makefile-ai-base-freshness]]).
