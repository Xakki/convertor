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

**Варианты (выбрать при груминге/реализации):**
1. `build-ai-cpu`/`build-ai-cuda` зависят от `build-ai-base` (локальный свежий base) и
   передают его как `--build-arg AI_BASE_IMAGE=<локальный-тег без registry-префикса>`,
   чтобы BuildKit гарантированно брал локальный, а не тянул registry-`latest`.
2. Либо добавить `--pull` в рецепты cpu/cuda (тянуть свежий base из Harbor) + сделать
   `push-ai-base` обязательным шагом (напр. `release-ai-base: build-ai-base push-ai-base`).
3. Расширить HEALTHCHECK в `ai.cpu.Dockerfile:73` / `ai.cuda.Dockerfile` с `import
   faster_whisper` до `import faster_whisper, webrtcvad`, чтобы устаревший base ловился
   как unhealthy сразу.

**Критерии приёмки:**
- Одной make-командой из чистого состояния собирается `worker-ai:cpu`, в котором
  `python3 -c "import webrtcvad"` проходит — БЕЗ ручного `push-ai-base` между шагами.
- Тег base без registry-префикса ИЛИ `--pull` — устаревший registry-`latest` больше не
  может незаметно попасть в рабочий образ.
- HEALTHCHECK ловит отсутствие `webrtcvad` (опционально, но желательно).
- Обновить комментарии в `docker-compose.worker-ai.yml` / Dockerfile-заголовках под
  новый порядок.

**Файлы:** `workers/Makefile` (таргеты `build-ai-*`), возможно
`docker/workers/ai.cpu.Dockerfile` + `ai.cuda.Dockerfile` (HEALTHCHECK), заголовки
`docker-compose.worker-ai.yml`.

**Найдено при:** задача `verify-webm-harness-rewrite` (2026-07-18), см.
[[stale-worker-ai-cpu-image-webrtcvad]].

**Status:** todo — scope ясен, вариант реализации выбрать по месту (рекоменд. — №1:
локальный base-тег как build-arg, самый герметичный).
