### Публичный запуск воркеров — deploy-репо (compose + Makefile + .env) + публикация всех remote-capable образов (EPIC)

**Criticality:** Medium

**TAGS:**
- epic
- deploy
- docker
- workers
- dx

**Цель:**
Поднять любой remote-capable воркер (`ai:cpu`/`ai:cuda`, `data`, `image`, `ffmpeg`, `libreoffice`) на чужом хосте БЕЗ клона основного (приватного) репозитория — только `.env` с токеном + одна make-команда.

**Решения (обсуждено с пользователем 2026-07-20):**
- Реестр образов — остаёмся на ТЕКУЩЕМ Harbor `harbor.xakki.ru/convertor/*` (проект уже публичный, anonymous pull). Отдельный GHCR/Docker Hub НЕ заводим. Надо лишь дотолкать туда остальные образы + подтвердить, что anonymous pull реально включён.
- Артефакты быстрого запуска — ОТДЕЛЬНЫЙ публичный git-репо (рабочее имя `convertor-workers-deploy`): compose + Makefile + `.env.example`, без исходников.
- Охват — ВСЕ remote-capable воркеры.

**Состав работ:**
1. Публикация образов в Harbor:
   - Добавить push-таргеты для `data`, `image`, `ffmpeg`, `libreoffice`, `ai:cpu` (сейчас в `workers/Makefile` пушатся только `push-gateway` → `$(HARBOR_NS)/ws-gateway:latest` и `push-ai-base` → `$(AI_BASE_IMAGE)`; build-таргеты `build-data`, `build-image`, `build-ffmpeg`, `build-libreoffice`, `build-ai-cpu` есть, push-эквивалентов у них нет — подтверждено).
   - `ai:cuda` — арх-специфичный, остаётся ЛОКАЛЬНОЙ сборкой из публичного `worker-ai-base` (см. `build-ai-cuda`); в deploy-репо — только инструкция сборки, не готовый образ.
   - Единая схема тегов (`:latest` + версия/дата).
   - Проверить/включить anonymous pull у Harbor-проекта (настройка проекта = root-операция → отдать пользователю командой, не делать самим).
2. Публичный deploy-репо `convertor-workers-deploy`:
   - `docker-compose.yml` — сервис на каждый remote-capable воркер (образ из Harbor, env из `.env`). Рассмотреть один compose с профилями vs отдельные файлы.
   - `Makefile` — быстрый запуск: `make up WORKER=<type>` / `make up-all` / `make logs` / `make down`.
   - `.env.example` — `WORKER_API_TOKEN=` (единственный обязательный секрет, без дефолта — по образцу `.env.worker-ai.example`/`.env.local_example`) + опциональные override (`GATEWAY_WS_URL`, `WHISPER_*`, …). Секреты не коммитим (пустые плейсхолдеры).
   - `README` — быстрый старт, матрица воркеров, требования (Docker; для `ai:cuda` — nvidia-container-toolkit + `--gpus all`).
   - Синхронизация deploy-артефактов: как выкатывать обновления (ручной push vs git subtree/split из основного репо) — решить в grooming.
3. Флоу выдачи токена: где внешний оператор берёт `WORKER_API_TOKEN` (генерация/выдача) — описать или дать ссылку (возможно отдельная подзадача).
4. Обновить `docs/worker-ai-deploy.md` — добавить ссылку на публичный deploy-репо как рекомендованный путь для внешних хостов.
5. Вынести docker-реестр в `.env` (конфигурируемый, не хардкод):
   - Сейчас `HARBOR_NS ?= harbor.xakki.ru/convertor` захардкожен в `workers/Makefile` (плюс производные `AI_BASE_IMAGE`, теги push-таргетов). Хотя `?=` уже позволяет переопределить, а корневой Makefile делает `include .env` — сделать это явным контрактом: завести переменную реестра в `.env`/`.env.example` (напр. `DOCKER_REGISTRY` / `HARBOR_NS`) и во всех местах Makefile брать её оттуда.
   - В deploy-репо: образы в `docker-compose.yml` тоже параметризовать этой переменной (`${DOCKER_REGISTRY}/worker-<type>:latest`), чтобы можно было указать другой реестр без правки файлов.

**Безопасность:**
Публичные образы НЕ содержат секретов (токен только через env на деплое) — проверить отсутствие вшитых креденшелов; воркеры S3/KeyDB напрямую не трогают (только gateway), поэтому публичный образ безопасен по данным.

**Открытые вопросы:**
- Имя публичного репо.
- Схема тегирования.
- Синхронизация артефактов (subtree vs ручной).
- Один compose с профилями vs файл-на-воркер.
- Токен единый на все воркеры или per-worker.

**Acceptance:**
На чистом хосте с Docker: `git clone <deploy-repo> && cp .env.example .env` + прописать токен + `make up WORKER=data` → воркер поднялся и подключился к gateway (виден consumer в `conv.data`); аналогично для каждого remote-capable типа.

**Status:** grooming.
