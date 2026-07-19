### Пересобрать + перезапустить worker-ai:cuda на GPU-хосте

**Критичность:** High (боевой AI-воркер на GPU-хосте крутится на старом образе — без
`webrtcvad` и без `workers/common` → стрим/старт сломаны при пересборке)

**TAGS:**
- ai-worker
- docker
- devops
- gpu

**Контекст:**
Harbor-base `worker-ai-base:latest` перезалит с обоими фиксами (`webrtcvad-wheels` +
`workers/common/`), CPU-образ на saFin уже пересобран и healthy. Но **рабочий CUDA-образ
на GPU-хосте не пересобирался** — там всё ещё старый `worker-ai:cuda`. Действие ops на
удалённом GPU-хосте, из saFin не автоматизируется → отдельная задача.

**Что сделать (по `docs/worker-ai-deploy.md`):**
1. На GPU-хосте: `docker login harbor.xakki.ru` + `docker pull
   harbor.xakki.ru/convertor/worker-ai-base:latest` (КРИТИЧНО — иначе локальный устаревший
   base).
2. `nvidia-smi --query-gpu=compute_cap --format=csv,noheader` → `CUDA_ARCH` (без точки).
3. `make build-ai-cuda CUDA_ARCH=<cc> [TORCH_CUDA_ARCH=<dotted>] [WITH_LLAMACPP=0|1]`
   (или сборка без репо — один Dockerfile + пустой контекст, см. docs §3b).
4. **STANDALONE-гейт (обязателен):** `docker run --rm --entrypoint python3
   xakki-convertor/worker-ai:cuda -c "import workers.ai.config, workers.ai.worker,
   webrtcvad, av, faster_whisper; print('OK')"`.
5. Пересоздать контейнер: `docker rm -f worker-ai` + `docker run … --gpus all …`
   (env — см. docs; обязательные `GATEWAY_WS_URL`/`WORKER_API_TOKEN`/`API_BASE_URL`).

**Критерии приёмки:**
- `worker-ai:cuda` на GPU-хосте пересобран из свежего Harbor-base; STANDALONE-гейт (п.4) →
  `OK` (webrtcvad + workers.common внутри образа).
- Контейнер `worker-ai` перезапущен, healthy, логи без `ModuleNotFoundError`; воркер
  подключился к gateway (`GATEWAY_WS_URL`).

**Зависит от:** свежий Harbor-base (сделано). Смежная задача — [[makefile-ai-base-freshness]]
(автоматизация свежести/гейта, чтобы этот класс не повторялся).

**Найдено при:** [[verify-webm-harness-rewrite]] (2026-07-18), находки
[[stale-worker-ai-cpu-image-webrtcvad]] + [[ai-base-missing-workers-common]].

**Status:** todo — scope ясен (ops-прогон на GPU-хосте по docs), нужен доступ к хосту.
