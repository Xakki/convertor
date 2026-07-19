### Makefile: гарантировать свежесть ai-base при сборке worker-ai (cpu/cuda)

**Критичность:** Medium (класс багов «registry-база отстаёт от кода» → пропавший
`webrtcvad`, разъехавшиеся образы)

**TAGS:**
- ai-worker
- docker
- devops
- makefile

**Контекст / зачем:**
2026-07-18 потеряли час на диагностику: `worker-ai:cpu` собирался без `webrtcvad`,
т.к. `ai.cpu.Dockerfile`/`ai.cuda.Dockerfile` берут `FROM
harbor.xakki.ru/convertor/worker-ai-base:latest`, а в Harbor лежал УСТАРЕВШИЙ base
(собран до добавления `webrtcvad-wheels` в `requirements-ai-ml.txt:10`). Полный разбор —
grooming-карточка `stale-worker-ai-cpu-image-webrtcvad.md`. Текущий Makefile-порядок —
ручной трёхшаговый (`build-ai-base` → `push-ai-base` → `build-ai-cpu`), и пропуск
`push-ai-base` тихо приводит к сборке cpu/cuda из устаревшей registry-базы.

**Задача:** сделать так, чтобы сборка рабочего образа НЕ могла молча взять устаревший
base.

**Decisions (груминг 2026-07-19):** выбран комбинированный подход (build-time + runtime):
- **Build-time (герметично):** `build-ai-cpu`/`build-ai-cuda` зависят от `build-ai-base`
  (локальный свежий base) и передают его как
  `--build-arg AI_BASE_IMAGE=<локальный-тег БЕЗ registry-префикса>` — BuildKit
  гарантированно берёт локальный образ, а не тянет устаревший registry-`latest`.
  (`push-ai-base` остаётся отдельным явным шагом для распространения базы на другие хосты.)
- **Runtime (guard):** расширить HEALTHCHECK в `ai.cpu.Dockerfile:73` и
  `ai.cuda.Dockerfile` с `import faster_whisper` до
  `import faster_whisper, webrtcvad, workers.common` — устаревший/битый base ловится как
  unhealthy на старте, а не при ручном прогоне.
- **Пин зависимости:** запиннить `webrtcvad-wheels==2.0.14` в `requirements-ai-ml.txt`
  (сейчас без пина) — воспроизводимость сборки.

**Рассмотренные и отклонённые:** только `--pull` + обязательный `push-ai-base` (требует
сети/Harbor на каждый build) — отклонён в пользу локального base-тега; только HEALTHCHECK
без build-time фикса — не предотвращает сборку из старой базы.

**Критерии приёмки:**
- Одной make-командой из чистого состояния собирается `worker-ai:cpu` (и `:cuda`), в
  котором standalone-импорт `python3 -c "import webrtcvad, workers.common, faster_whisper"`
  проходит — БЕЗ ручного `push-ai-base` между шагами.
- `build-ai-cpu`/`build-ai-cuda` передают base локальным тегом без registry-префикса →
  устаревший registry-`latest` не может незаметно попасть в рабочий образ.
- HEALTHCHECK в `ai.cpu.Dockerfile` + `ai.cuda.Dockerfile` = `import faster_whisper,
  webrtcvad, workers.common` (битый/устаревший base → unhealthy на старте).
- `webrtcvad-wheels==2.0.14` запиннен в `requirements-ai-ml.txt`.
- Обновить комментарии в `docker-compose.worker-ai.yml` / Dockerfile-заголовках + скилл
  [[worker-ai-image]] под новый порядок.

**Файлы:** `workers/Makefile` (таргеты `build-ai-*`), `docker/workers/ai.cpu.Dockerfile`
+ `ai.cuda.Dockerfile` (HEALTHCHECK), `docker/workers/requirements-ai-ml.txt` (пин),
заголовки `docker-compose.worker-ai.yml`, скилл `.claude/skills/worker-ai-image/`.

**Найдено при:** задача `verify-webm-harness-rewrite` (2026-07-18), см.
[[stale-worker-ai-cpu-image-webrtcvad]].

**Status:** ready — реализовано (ветка `task/makefile-ai-base-freshness`, коммит `f3dcab4`).
Сюда сведены форвард-пункты закрытых находок [[stale-worker-ai-cpu-image-webrtcvad]]
и [[ai-base-missing-workers-common]].

**Execution log (2026-07-19):**
- `workers/Makefile`: добавлен `AI_BASE_LOCAL ?= worker-ai-base:local` (без registry-префикса);
  `build-ai-base` тегает и Harbor-тег, и локальный; `build-ai-cpu`/`build-ai-cuda` теперь
  зависят от `build-ai-base` и передают `--build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL)`.
  `push-ai-base` остался отдельным шагом (рассылка базы на другие хосты), не зависимость cpu/cuda.
- HEALTHCHECK в `ai.cpu.Dockerfile` + `ai.cuda.Dockerfile` → `import faster_whisper, webrtcvad, workers.common`.
- `requirements-ai-ml.txt`: `webrtcvad-wheels==2.0.14` (запиннен).
- Docs: заголовки `docker-compose.worker-ai.yml`, `docs/worker-ai-deploy.md`, скилл `worker-ai-image`.
- Проверка (статическая): `make -C workers -n build-ai-cpu/cuda` → base собирается первым,
  build-arg = локальный тег; `make worker-ai-check` + `make docker-check` → exit 0; HEALTHCHECK
  guard валиден (WORKDIR /app, `workers.common` уже импортируется рантаймом `python3 -m workers.ai`).

**Остаточный gap (не блокер приёмки этой карты):** AC «одной make-командой собирается образ,
импорт проходит» проверен на уровне wiring, но НЕ реальной ML-сборкой (torch/CUDA-пул тут не
поднять). Рантайм-прогон `make build-ai-cpu`/`build-ai-cuda` + `worker-ai-recreate` с проверкой
healthy — на GPU/CPU-хосте, покрывается картой [[cuda-worker-ai-rebuild-gpu-host]].
