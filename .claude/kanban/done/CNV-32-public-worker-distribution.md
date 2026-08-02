### Публичный запуск remote-воркеров (deploy/ + gist install)

**Criticality:** Medium
**Epic:** [[CNV-54]]

**TAGS:**
- deploy
- docker
- workers
- dx
- bootstrap
- script

**Description:**
Дать внешнему оператору поднять remote-capable воркеры на чужом хосте без клона
приватного репо — только токен + `curl | bash` из публичного gist.

**Problem:**
Сейчас внешний хост либо клонирует приватный репо, либо собирает образы локально.
Нужен публичный путь: готовые образы Harbor + deploy-артефакты + install-скрипт.

**Recommendation:**
Артефакты в `deploy/` основного репо (единственный источник правды); автопубликация
содержимого `deploy/` в публичный Gist из `make release-workers` (`gh gist edit`).
UX: `curl -fsSL <gist-raw-url> | bash`.

**Scope:**
1. `deploy/docker-compose.yml` — один compose с профилями (по воркеру), образы из Harbor.
2. `deploy/.env.example` — `WORKER_API_TOKEN=` (обязательный, без дефолта) + опциональные override.
3. `deploy/README.md` — быстрый старт (`curl | bash`), матрица воркеров, требования (Docker).
4. `deploy/install.sh` — автономный install (интерактив: токен + какие воркеры; update-режим
   идемпотентен). Публикуется в gist вместе с остальным.
5. Автопубликация gist из `make release-workers` после успешного push образов.
   Gist ID — в `.env`/Makefile-переменной; gist = read-only проекция `deploy/`.
6. Обновить `docs/worker-ai-deploy.md` — ссылка на gist / `curl | bash` как рекомендованный путь.

**Вне scope этой карточки:**
- Push-таргеты образов — уже сделаны через `make release-workers` (не добавлять заново).
- `ai:cuda` — НЕ в публичном пути (ни образ, ни ветка install). CUDA остаётся на
  карточке `cuda-worker-ai-rebuild-gpu-host` / локальной сборке на GPU-хосте.
- Вынос `DOCKER_REGISTRY` / Harbor login в `.env` — карточка
  `make-login-not-configured` (отдельно).

**Безопасность:**
Публичные образы без секретов; токен только через env на деплое; воркеры не трогают
S3/KeyDB напрямую (только gateway).

**Acceptance Criteria:**
- На чистом хосте с Docker: `curl -fsSL <gist-raw-url> | bash` → запрос
  `WORKER_API_TOKEN` (скрытый ввод) + выбор воркеров (без cuda) → выбранные
  воркеры поднялись и подключились к gateway (consumer в `conv.<type>`).
- Повторный запуск `… | bash -s -- update` перепуллит образы и пересоздаёт
  контейнеры без дублей.
- `make release-workers` после push обновляет gist содержимым `deploy/`.
- В публичном пути нет cuda.

**Decisions:**
- (2026-08-01, Q1=C) НЕ epic — одна задача, не дробить на субкарточки.
- Scope: `deploy/` (compose+profiles + `.env.example` + README + install.sh) +
  автообновление gist из `release-workers`; единый shared worker token на все воркеры.
- NO cuda в публичном пути (ни образ Harbor, ни ветка install-скрипта).
- Устаревший пункт «добавить push-таргеты» — вычеркнут: уже покрыто `release-workers`.
- DOCKER_REGISTRY / `.env` login — остаётся на `make-login-not-configured`, не здесь.
- Ранее (2026-07-20): Harbor `harbor.xakki.ru/convertor/*` (anonymous pull); gist как
  проекция `deploy/`, не отдельный публичный git-репо (fallback только если gist тесен).
- (2026-08-02) Publish через `deploy/publish-to-gist.sh` + `gh api PATCH` (не
  интерактивный `gh gist edit`); warn+skip если `DEPLOY_GIST_ID` пуст.

**Status:** progress — реализация в репо; gist ещё не создан (нужен ручной
`DEPLOY_GIST_ID` один раз).

**Execution Log:**
- 2026-08-02: добавлен `deploy/` (compose profiles, `.env.example`, `install.sh`,
  `README.md`, `publish-to-gist.sh`); `make publish-deploy-gist` в хвосте
  `release-workers`; плейсхолдеры `DEPLOY_GIST_*` в `.env`; обновлены
  `docs/worker-ai-deploy.md` + указатель в `docs/workers-remote-deploy.md`.
  Sanity: `bash -n`, `docker compose … config -q`, skip publish без ID.
- 2026-08-02: review nits — lowercase `COMPOSE_PROJECT_NAME` placeholder,
  `/deploy/.env` в `.gitignore`, безопасное quoting `WORKER_API_TOKEN` в install.sh.
