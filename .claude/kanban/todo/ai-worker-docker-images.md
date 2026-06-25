### AI-воркер — образы: лёгкий публикуемый ai-base + локальный ai.cuda (FROM ai-base) + ai.cpu, шаринг HF/Ollama моделей

**Критичность:** Medium

**TAGS:**
- devops
- feature

**Описание:**
Перестроить Docker-образы AI-воркера под новую схему: **в Harbor публикуем только
супер-лёгкий `ai-base`** (код + бизнес-логика, без тяжёлого ML-стека). Рабочий образ
**`ai.cuda` собирается локально на GPU-хосте и НЕ публикуется** — он `FROM ai-base` и
доставляет тяжёлые зависимости. `ai.cpu` оставляем как CPU-рабочий (`FROM ai-base` +
CPU ML) для прогонов/CI без GPU. Нужен шаринг уже существующих локальных весов
(HuggingFace-кэш и модели Ollama).

Зависит от финального списка зависимостей из [[ai-worker-refactor-core]] /
[[ai-worker-llm-text2text]]. Переосмысливает [[ai-worker-harbor-image]] (ready/).

**Проблема:**
Текущая двухслойная схема публикует тяжёлые base-образы в Harbor; новая цель —
публиковать только лёгкий код, а тяжёлый рабочий образ собирать локально, не
перекачивая модели (использовать уже скачанные локально HF/Ollama веса).

**Рекомендация:**
1. **`docker/workers/ai-base.Dockerfile`** (публикуется в Harbor, супер-лёгкий):
   `python:3.12-slim` + light pure-python deps (httpx, pyyaml, fastapi, uvicorn,
   python-multipart, websockets) + `COPY workers/ai/`. Сам воркер не запускает (нет ML),
   это база для рабочих образов.
2. **`docker/workers/ai.cuda.Dockerfile`** (локально, НЕ публикуется): `FROM ai-base` +
   CUDA/torch/ctranslate2/faster-whisper/sentence-transformers/llama-cpp-python +
   системные `ffmpeg`/`espeak-ng`. Рабочий образ на GPU-хосте.
   - **Сборка под выбранную GPU-архитектуру** (build-ARG, для универсальности):
     `CUDA_ARCH` (compute capability, напр. `86` для RTX 30xx) пробрасывается в сборку
     llama.cpp (`CMAKE_CUDA_ARCHITECTURES`) и в `TORCH_CUDA_ARCH_LIST` (напр. `8.6`).
     По умолчанию — разумный набор, но рекомендуется указывать свою арх под конкретную
     карту (меньше бинарь, быстрее сборка).
   - **`WITH_LLAMACPP`** (build-ARG, default `0`): ставить ли бинарь `llama-cpp-python`.
     Код-путь llama.cpp всегда есть (ленивый импорт в [[ai-worker-llm-text2text]]); бинарь
     ставится только при `WITH_LLAMACPP=1`. У кого дефолт-бэкенд Ollama — образ не растёт.
3. **`docker/workers/ai.cpu.Dockerfile`**: `FROM ai-base` + CPU-вариант ML-стека +
   `ffmpeg`/`espeak-ng`. Для CPU-only прогонов и CI.
4. **Шаринг локальных моделей** (сборка и `docker run`): пробрасывать уже существующие
   локальные volume, чтобы не качать заново и шарить с хостом:
   - HuggingFace-кэш: `-v ~/.cache/huggingface:/home/app/.cache/huggingface` (Whisper,
     embedding, GGUF для llama.cpp).
   - Модели Ollama: внешний Ollama на хосте (worker → `OLLAMA_URL`) или
     `-v ~/.ollama:/root/.ollama` для шаринга весов между хостом и контейнером.
   Описать в инструкции запуска обе строки (CUDA/CPU) + проброс volume.
5. **Makefile-таргеты:** `build-ai-base` / `push-ai-base` (лёгкий, в Harbor);
   `build-ai-cuda` / `build-ai-cpu` (локальная сборка рабочих образов, без push).
   `build-ai-cuda` принимает `CUDA_ARCH=<cc>` и `WITH_LLAMACPP=0|1` (пробрасывает в build-ARG).
   Сохранить/обновить `make docker-check`.
6. **Документация выбора архитектуры** (README/раздел): как выбрать `CUDA_ARCH` под свою
   видеокарту NVIDIA. Таблица модель → compute capability (примеры):

   | Серия / карта | Архитектура | compute capability (`CUDA_ARCH`) |
   |---|---|---|
   | GTX 10xx (1080/1070/1060) | Pascal | `61` |
   | RTX 20xx, T4 | Turing | `75` |
   | RTX 30xx (3060/3080/**3080Ti**/3090) | Ampere | `86` |
   | A100 | Ampere | `80` |
   | RTX 40xx (4070/4080/4090) | Ada Lovelace | `89` |
   | H100 | Hopper | `90` |

   Источник истины — `nvidia-smi --query-gpu=compute_cap --format=csv` на хосте. В доке
   указать команду + как подставить значение в `make build-ai-cuda CUDA_ARCH=86`.

**Влияние:**
Минимальный публикуемый артефакт; быстрый локальный ребилд рабочего образа; модели
не перекачиваются (шарятся с хостом).

**Критерии приёмки:**
- `ai-base.Dockerfile` — лёгкий (код + light deps), публикуется в Harbor.
- `ai.cuda.Dockerfile` — `FROM ai-base`, собирается локально, НЕ публикуется; рабочий на GPU.
- `ai.cpu.Dockerfile` — `FROM ai-base` + CPU ML, для прогонов/CI.
- `docker run` пробрасывает локальные HF-кэш и Ollama-модели (volume) — шаринг с хостом,
  без повторной загрузки; обе строки (CUDA/CPU) задокументированы.
- Сборка параметризована под GPU-архитектуру: `CUDA_ARCH` (build-ARG) уходит в llama.cpp
  (`CMAKE_CUDA_ARCHITECTURES`) и `TORCH_CUDA_ARCH_LIST`; `WITH_LLAMACPP=0|1` гейтит бинарь.
- Документация: таблица «модель видеокарты NVIDIA → compute capability» + команда
  `nvidia-smi --query-gpu=compute_cap` для определения своей арх.
- Makefile-таргеты build/push ai-base + локальная сборка ai.cuda/ai.cpu (с `CUDA_ARCH`/
  `WITH_LLAMACPP`); `make docker-check` зелёный.

**Decisions:**
- Публикуем ТОЛЬКО `ai-base` (лёгкий). `ai.cuda` — локальный, не публикуется. `ai.cpu` — оставляем.
- `ai.cuda` = `FROM ai-base` (код пишется один раз в base, рабочие образы доставляют deps поверх).
- Default LLM-бэкенд — внешний Ollama; веса HF/Ollama шарятся через bind локальных volume.
- Реальная сборка/пуш тяжёлых образов выполняется пользователем на GPU-хосте (не на saFin автоматом).
- llama.cpp оставляем: код всегда (ленивый импорт), бинарь — опционально через `WITH_LLAMACPP`.
- Сборка под конкретную GPU-арх через `CUDA_ARCH`; в доке — таблица карт NVIDIA → compute capability.
