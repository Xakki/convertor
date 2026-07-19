### Устаревший base в Harbor → worker-ai:cpu собирается без webrtcvad

**Критичность:** Medium (dev/prod AI-стрим не поднимется на таком образе)

**TAGS:**
- ai-worker
- docker
- devops

**КОРНЕВАЯ ПРИЧИНА (установлена 2026-07-18, не просто «образ старый»):**
`docker/workers/ai.cpu.Dockerfile` (и `ai.cuda`) берут базу через
`FROM harbor.xakki.ru/convertor/worker-ai-base:latest`. BuildKit подтягивает этот
**registry-квалифицированный** образ ИЗ Harbor — там лежит УСТАРЕВШИЙ `worker-ai-base`
(собран до добавления `webrtcvad-wheels` в `requirements-ai-ml.txt:10`; requirements
пекутся именно в base — `ai-base.Dockerfile` COPY). Поэтому:
- `make build-ai-cpu` САМ ПО СЕБЕ НЕ помогает: он тянет старый base из Harbor и ставит
  ML-стек по устаревшему `/app/requirements-ai-ml.txt` (без webrtcvad), даже с
  `--no-cache`.
- `make build-ai-base` локально обновляет `base:latest`, но cpu-сборка всё равно уходит
  в реестр за старым → локальный свежий base игнорируется.
- Эмпирически подтверждено: cpu, собранный `FROM` **локального** тега без registry-
  префикса (`convertor-ai-base:local`), ставит `webrtcvad-wheels-2.0.14`, `import
  webrtcvad` OK, харнесс → exit 0. Тот же cpu `FROM harbor…base:latest` → без webrtcvad.

**Правильный порядок пересборки (ключевой вывод):**
```
make build-ai-base        # пересобрать base с актуальными requirements
make push-ai-base         # ЗАПУШИТЬ свежий base в Harbor  ← без этого cpu берёт старый
make build-ai-cpu         # теперь FROM harbor…base:latest = свежий
```
(либо разово — cpu `--build-arg AI_BASE_IMAGE=<локальный-тег>` в обход реестра.)

**Описание:**
Локально собранный образ `xakki-convertor/worker-ai:cpu` (image id `240cdb97`)
не содержал модуль `webrtcvad`, хотя зависимость `webrtcvad-wheels` уже объявлена в
`docker/workers/requirements-ai-ml.txt:10`. Первопричина — устаревший `worker-ai-base`
в Harbor (см. выше), а не только сам cpu-образ.

**Последствия:** любой код, использующий `VadChunker`
(`workers/ai/devserver/vad_chunker.py`, `import webrtcvad`), падает в этом образе с
`ModuleNotFoundError: No module named 'webrtcvad'`. Это стрим-путь AI dev-server
(`routes_stream.py`) и харнесс `verify_webm_partial.py`. Т.е. `python -m workers.ai
devserver` + WS-стрим на текущем образе НЕ работают.

**Найдено при:** валидации задачи `verify-webm-harness-rewrite` (2026-07-18) — первый
прогон харнесса дал exit 1 из-за отсутствия `webrtcvad`; после инлайн-`pip install
webrtcvad-wheels` тот же харнесс → exit 0 / SAFE. Значит требования корректны, устарел
именно образ.

**Сделано (2026-07-18):**
- ✅ **Свежий base запушен в Harbor** — `harbor.xakki.ru/convertor/worker-ai-base:latest`
  = digest `sha256:db483ccb…` (содержит `webrtcvad-wheels`). Подтверждено `--pull` из
  реестра: `grep -c webrtcvad /app/requirements-ai-ml.txt` = 1. Теперь `make build-ai-cpu`
  / `build-ai-cuda` на любом хосте получат корректную базу.
- ✅ CPU-образ на saFin пересобран (`webrtcvad-wheels-2.0.14`, `import webrtcvad` OK,
  харнесс exit 0).
- ⚠️ NB: base собирался/пушился **`--no-cache` напрямую** (не через `make push-ai-base`),
  т.к. `build-ai-base` без `--no-cache` ранее давал устаревший COPY-слой. Это подтверждает
  дефект таргета — см. остаток ниже.

**Остаток:**
- **CUDA-флейвор на GPU-хосте** — пересобрать `make build-ai-cuda` (там реально крутится
  AI-воркер; base в Harbor уже свежий, так что достаточно ребилда на хосте).
- **Автоматизировать порядок в Makefile:** сделать `build-ai-cpu`/`build-ai-cuda`
  зависимыми от свежего base (или добавить `--no-cache`/`--pull` в рецепты), чтобы
  registry-база не отставала от кода и `make push-ai-base` не пушил устаревший слой.

**Открытые вопросы:**
- CUDA-флейвор на GPU-хосте почти наверняка страдает тем же (тот же `FROM harbor…base`)
  → нужен `push-ai-base` + `build-ai-cuda` там.
- Нужен ли CI-guard (healthcheck уже есть на `import faster_whisper` в
  `ai.cpu.Dockerfile:73` — расширить до `import webrtcvad`), чтобы устаревший base ловился
  автоматически, а не при ручном прогоне харнесса.
- Стоит ли пиннить `webrtcvad-wheels` по версии (сейчас без пина; поставилось `2.0.14`).

**Status:** DONE (груминг 2026-07-19) — корневая причина установлена, свежий base
перезалит в Harbor, CPU-образ пересобран (healthy). Форвард-пункты вынесены: CUDA-ребилд →
[[cuda-worker-ai-rebuild-gpu-host]]; автоматизация порядка + `--pull`/healthcheck + пин
webrtcvad → [[makefile-ai-base-freshness]].
