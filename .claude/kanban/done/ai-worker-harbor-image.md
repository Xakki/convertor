### AI-воркер — двухслойный Harbor-образ (CPU+CUDA) + запуск одной строкой docker run

> ⚠ **Переосмыслено** рефактором AI-воркора 2026-06-25: публикуем только лёгкий `ai-base`,
> `ai.cuda` собирается локально и НЕ публикуется (`FROM ai-base`). См. [[ai-worker-docker-images]].
> Эту карточку не закрывать автоматически — пользователь решает её судьбу.

**Критичность:** Medium

**TAGS:**
- feature
- devops

**Описание:**
Упростить выкатку AI-воркера на домашнюю машину (WSL + RTX 3080Ti). Сейчас запуск
через `docker-compose.worker-ai.yml` со сборкой образа на месте; тяжёлые CUDA-зависимости
дома собирать долго. Переходим на готовые образы в Harbor + запуск одной строкой
`docker run` (без compose, без локальной сборки, токен через `-e`).

**Решение (двухслойная схема, согласовано с пользователем 2026-06-24):**
- **Базовый образ** (тяжёлые депсы, редкая пересборка), 2 флейвора:
  - `harbor.xakki.ru/convertor/worker-ai-base:cpu` — `python:3.12-slim` + espeak-ng + ffmpeg
    + pip (faster-whisper, pyttsx3, httpx, ctranslate2 CPU).
  - `harbor.xakki.ru/convertor/worker-ai-base:cuda` — `nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04`
    + python 3.12 + espeak-ng + ffmpeg + pip (faster-whisper, ctranslate2 с CUDA). Свериться с
    требованиями `ctranslate2==4.8.0` (CUDA 12 + cuDNN 9) — тег базы должен давать cuDNN 9.
- **Образ воркера** = `FROM <base-флейвор>` + `COPY workers/ai/` + entrypoint. 2 тега:
  `worker-ai:cpu` / `worker-ai:cuda`. Пересобирается быстро при правке кода воркера.
- **Запуск дома — одна строка `docker run`**, без compose:
  - CUDA: `docker run -d --name worker-ai --restart unless-stopped --gpus all -e API_BASE_URL=... -e WORKER_API_TOKEN=xxx -e WHISPER_DEVICE=cuda -e WHISPER_COMPUTE_TYPE=float16 -v worker-ai-models:/home/app/.cache/huggingface harbor.xakki.ru/convertor/worker-ai:cuda`
  - CPU: то же без `--gpus all` и с `WHISPER_DEVICE=cpu` / `WHISPER_COMPUTE_TYPE=int8`.
- **Makefile-таргеты:**
  - `build-ai-base FLAVOR=cpu|cuda` / `push-ai-base FLAVOR=...` — база (редко).
  - `build-ai FLAVOR=cpu|cuda` / `push-ai FLAVOR=...` — код воркера (при каждой правке).
  - Сохранить `worker-ai-check` (валидация compose) или заменить, если compose уходит.
- **Документация запуска** (README/раздел в карточке или docs) — обе `docker run` строки,
  список env, требование nvidia-container-toolkit для CUDA. Harbor login упомянуть.

**Открытые/решённые вопросы (решено):**
- Схема — двухслойная (base+code). ✓
- CUDA-база — `nvidia/cuda 12.x cudnn runtime`. ✓
- Команда — be-dev + reviewer. ✓

**Критерии приёмки:**
- Есть Dockerfile базы (CPU и CUDA) и Dockerfile воркера (`FROM` базы, параметризован флейвором).
- Makefile-таргеты build/push для базы и для кода, оба флейвора; `make docker-check` зелёный.
- Готова одна `docker run`-строка на каждый флейвор (CPU/CUDA), токен через `-e`.
- `docker-compose.worker-ai.yml` — оставить как альтернативу ИЛИ удалить (решить при реализации,
  не ломая основной путь docker run).
- Инструкция запуска дома обновлена (docker run, env, nvidia-container-toolkit, harbor login).

**Зависимости:**
- Продолжение [[validate-ai-worker]] (сам воркер/pull-API готов, в ready/). Здесь — только транспорт образа.

**Decisions:**
- 2026-06-24: пользователь переразвилил — Harbor оставляем, но двухслойно (база отдельно,
  код тонким слоем); дома — одна строка `docker run`, без compose-сборки.
- Фактический `build+push` тяжёлой CUDA-базы (~3-4 GB) в Harbor — НЕ в этой сессии автоматом;
  выполняет пользователь/отдельный шаг по подтверждению (не грузить saFin сборкой без нужды).
