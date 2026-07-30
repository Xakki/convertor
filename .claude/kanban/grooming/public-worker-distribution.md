### Публичный запуск воркеров — deploy-репо (compose + Makefile + .env) + публикация всех remote-capable образов (EPIC)

**Criticality:** Medium

**TAGS:**
- epic
- deploy
- docker
- workers
- dx
- bootstrap
- script

**Цель:**
Поднять любой remote-capable воркер (`ai:cpu`/`ai:cuda`, `data`, `image`, `ffmpeg`, `libreoffice`) на чужом хосте БЕЗ клона основного (приватного) репозитория — только `.env` с токеном + одна make-команда.

**Решения (обсуждено с пользователем 2026-07-20):**
- Реестр образов — остаёмся на ТЕКУЩЕМ Harbor `harbor.xakki.ru/convertor/*` (проект уже публичный, anonymous pull). Отдельный GHCR/Docker Hub НЕ заводим. Надо лишь дотолкать туда остальные образы + подтвердить, что anonymous pull реально включён.
- Артефакты быстрого запуска (compose, `.env.example`, install-скрипт) живут В ОСНОВНОМ (приватном) репо, в директории `deploy/` — единственный источник правды. Публикация в публичный канал — АВТОМАТИЧЕСКАЯ, тем же make-таргетом, что пушит образы (`make push-workers` и т.п.): пушит образы в Harbor И публикует содержимое `deploy/` в публичный GIST (`gh gist edit`). Gist — ПРОЕКЦИЯ, вручную не редактируется → никакого дублирования кода/артефактов. (Отменяет более раннее решение — отдельный публичный git-репо `convertor-workers-deploy`: теперь это fallback-вариант на случай, если gist окажется тесен, а не выбранный путь.)
- Основной UX запуска — автономный install-скрипт (`curl -fsSL <gist-raw-url> | bash`), а не `git clone` deploy-репо.
- Охват — ВСЕ remote-capable воркеры.

**Состав работ:**
1. Публикация образов в Harbor:
   - Добавить push-таргеты для `data`, `image`, `ffmpeg`, `libreoffice`, `ai:cpu` (сейчас в `workers/Makefile` пушатся только `release-workers` → `$(HARBOR_NS)/ws-gateway:latest` (и остальные RELEASE_IMAGES) и `push-ai-base` → `$(AI_BASE_IMAGE)`; build-таргеты `build-data`, `build-image`, `build-ffmpeg`, `build-libreoffice`, `build-ai-cpu` есть, push-эквивалентов у них нет — подтверждено).
   - `ai:cuda` — арх-специфичный, остаётся ЛОКАЛЬНОЙ сборкой из публичного `worker-ai-base` (см. `build-ai-cuda`); в `deploy/` — только инструкция/логика сборки (в т.ч. в install-скрипте), не готовый образ.
   - Единая схема тегов (`:latest` + версия/дата).
   - Проверить/включить anonymous pull у Harbor-проекта (настройка проекта = root-операция → отдать пользователю командой, не делать самим).
2. Deploy-артефакты в `deploy/` основного репо + автопубликация в gist из make-таргета пуша образов:
   - `deploy/docker-compose.yml` — сервис на каждый remote-capable воркер (образ из Harbor, env из `.env`). Рассмотреть один compose с профилями vs отдельные файлы.
   - `deploy/.env.example` — `WORKER_API_TOKEN=` (единственный обязательный секрет, без дефолта — по образцу `.env.worker-ai.example`/`.env.local_example`) + опциональные override (`GATEWAY_WS_URL`, `WHISPER_*`, …). Секреты не коммитим (пустые плейсхолдеры).
   - `deploy/README.md` — быстрый старт (команда `curl | bash`), матрица воркеров, требования (Docker; для `ai:cuda` — nvidia-container-toolkit + `--gpus all`).
   - Make-таргет пуша образов (`make push-workers` / расширить существующие `push-*`) ПОСЛЕ успешного push в Harbor публикует содержимое `deploy/` (compose, `.env.example`, install-скрипт, README) в публичный GIST через `gh gist edit`. Gist создаётся один раз заранее, его ID хранится в `.env`/Makefile-переменной.
   - Gist — read-only проекция `deploy/`; редактируется ТОЛЬКО этим make-таргетом, руками не трогаем.
3. Автономный install-скрипт (главный UX запуска):
   - Единый самодостаточный bash-скрипт `deploy/install.sh`, публикуется в gist вместе с остальными артефактами (см. п.2). Запуск: `curl -fsSL <gist-raw-url> | bash`.
   - Интерактивный: запрашивает `WORKER_API_TOKEN` (скрытый ввод) и КАКИЕ воркеры запускать (меню: ai-cpu / ai-cuda / data / image / ffmpeg / libreoffice / all).
   - Автономный: сам пуллит публичные образы из Harbor, пишет локальный `.env` (chmod 600), поднимает контейнеры (`docker run` / `compose up -d`), выставляет `--hostname` для стабильного авто-`WORKER_ID`.
   - Update/recreate-режим: `curl … | bash -s -- update` (или флаг) → перепуллить свежие образы + `docker rm -f` + пересоздать выбранные воркеры. Идемпотентно (повторный запуск = апдейт, а не дублирование контейнеров).
   - Ветка `ai:cuda`: проверка nvidia-container-toolkit + `--gpus all`; либо тянуть готовый cuda-образ под compute-cap, либо собирать локально из публичного `worker-ai-base`.
   - Технические грабли — зафиксировать явно в скрипте/README:
     - под `curl | bash` stdin съедается пайпом → интерактивные промпты читать из `/dev/tty`, иначе не сработают;
     - токен вводится только в рантайме; НИКАКИХ секретов в скрипте/gist; `.env` — chmod 600;
     - скрипт идемпотентен (повторный запуск безопасен).
4. Флоу выдачи токена: где внешний оператор берёт `WORKER_API_TOKEN` (генерация/выдача) — описать или дать ссылку (возможно отдельная подзадача).
5. Обновить `docs/worker-ai-deploy.md` — добавить ссылку на gist / команду `curl | bash` как рекомендованный путь для внешних хостов.
6. Вынести docker-реестр в `.env` (конфигурируемый, не хардкод):
   - Сейчас `HARBOR_NS ?= harbor.xakki.ru/convertor` захардкожен в `workers/Makefile` (плюс производные `AI_BASE_IMAGE`, теги push-таргетов). Хотя `?=` уже позволяет переопределить, а корневой Makefile делает `include .env` — сделать это явным контрактом: завести переменную реестра в `.env`/`.env.example` (напр. `DOCKER_REGISTRY` / `HARBOR_NS`) и во всех местах Makefile брать её оттуда.
   - В `deploy/docker-compose.yml` и install-скрипте: образы тоже параметризовать этой переменной (`${DOCKER_REGISTRY}/worker-<type>:latest`), чтобы можно было указать другой реестр без правки файлов.

**Безопасность:**
Публичные образы НЕ содержат секретов (токен только через env на деплое) — проверить отсутствие вшитых креденшелов; воркеры S3/KeyDB напрямую не трогают (только gateway), поэтому публичный образ безопасен по данным.

**Открытые вопросы:**
- ID/имя gist (и имя fallback-репо на случай, если gist окажется тесен).
- Схема тегирования.
- Один compose с профилями vs файл-на-воркер.
- Токен единый на все воркеры или per-worker.
- Prebuilt cuda-образ per compute-cap vs локальная сборка внутри install-скрипта.
- Gist vs отдельный публичный git-репо, если gist окажется тесен (лимиты gist, приватность).

**Acceptance:**
На чистом хосте с Docker: `curl -fsSL <gist-raw-url> | bash` → скрипт спрашивает `WORKER_API_TOKEN` (скрытый ввод) и какие воркеры поднять → выбранные воркеры поднялись и подключились к gateway (виден consumer в `conv.<type>`); повторный запуск `curl -fsSL <gist-raw-url> | bash -s -- update` перепуллит свежие образы и пересоздаёт воркеры без дублирования контейнеров.

**Status:** grooming.
