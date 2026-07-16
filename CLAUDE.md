# Convertor Service — Project Rules for Claude Code

## Project Overview
SaaS-сервис конвертации файлов всех форматов. PHP 8.5 + Symfony 7 backend, Alpine.js + HTMX + Tailwind frontend, Python воркеры, KeyDB очереди.

## Язык
Вся документация (`docs/`, `README`, kanban-карточки, spec'и) и комментарии в коде — **на русском**. Технические идентификаторы (имена файлов, символов, env-переменных, команд) остаются как есть.

## Architecture Reference
Backend по образцу **https://github.com/Xakki/ExRate** (Registry для конверторов, Manager для оркестрации, Symfony Messenger, DTO между слоями, AbstractBase для воркеров). Карта классов, реальные пути и поток запроса — skill `backend-architecture`.

## Tech Stack
- **Backend**: PHP 8.5 + Symfony 7 (src/Controller, src/Service, src/Entity, src/Message, src/Repository, src/DTO)
- **Frontend**: Alpine.js 3 + HTMX + Tailwind CSS (без тяжёлых SPA фреймворков)
- **Queue**: KeyDB (Redis-compatible) + Symfony Messenger
- **Workers**: Python 3.12 микросервисы (по одному на категорию конвертации)
- **DB**: MariaDB 11 + Doctrine ORM
- **Storage**: только S3/MinIO (бакеты `${S3_BUCKET_PREFIX}-inputs` / `-results`); общего тома `/shared-files` нет (убран 2026-06-20)

## Code Quality
- `make phpstan` — обязательно исправлять все ошибки PHPStan
- `make cs` — автоисправление code style
- `make cs-check` — ручное исправление остального
- PHPStan игнорировать только в крайнем случае (максимум 2 попытки исправить)
- Тесты: PHPUnit для PHP, pytest для Python воркеров

## API Design
REST под префиксом `/api/v1/` (версионирование), JSON request/response, JWT Bearer (LexikJWT), OpenAPI через NelmioApiDocBundle. Карта эндпоинтов, firewalls/роли, конвенции ошибок — skill `api-design`.

## Queue Architecture
- Каналы (KeyDB Streams): `conv.document`, `conv.image`, `conv.audio`, `conv.video`, `conv.data`, `conv.ai`.
- **Единая модель транспорта: ВСЕ воркеры (on-server + remote) — WS-клиенты gateway. Ни один воркер не трогает KeyDB или S3 напрямую — только WS-Gateway (единственный читатель Streams: `XREADGROUP`/`XACK`/`XAUTOCLAIM`) + Symfony API.** KeyDB наружу НЕ публикуется; удалённый воркер подключается к публичному `wss://`.
- **Воркеры flag-agnostic: валидируют только форматы (source→target) и выполняют конвертацию; флаги (`ocr`, `subType` и пр.) выбирает БЭК/API при постановке задачи в нужный stream.**
- Контракт очередей и дизайн транспорта — `docs/queue-contract.md`, `docs/queue-streams.md`, `docs/queue-redesign-design.md`.

## Authentication
- Telegram-логин через бота: magic-link на своём устройстве (same-device, nonce-bound), не Login Widget. Анонимная конвертация guest-User по httpOnly-cookie `guest_id` (`ROLE_GUEST`), кроме `isAi`/`category=Video` (→ 403 `auth_required`). SMS OTP — заглушка (501). JWT 1h (LexikJWT) + refresh 30 дней. Полный контракт флоу (webhook, secrets, merge guest-истории) — skill `redesign-auth-access-contract`.
- Регистрация webhook: `make tg-set-webhook` из корня (нужны `TELEGRAM_WEBHOOK_SECRET` в `app-symfony/.env.local` + публичный `API_URL` в `.env`).

## Payments
MVP = только **Telegram Stars** (Bot API: invoice → `successful_payment` webhook). Stripe/Cryptomus — вне MVP. Фича **FROZEN** (в коде только DB-скелет `Payment`/`Plan`, платежи не персистятся) → детали и разморозка: `.claude/kanban/freeze/docs-payments-integration.md`.

## File Handling
- Загружаемые файлы: валидация MIME + расширения
- Path traversal защита: ключи S3 валидируются, выход за пределы бакета (`${S3_BUCKET_PREFIX}-inputs`/`-results`) запрещён
- Авто-удаление через 24ч (Symfony Scheduler)
- Max size: 50MB free, 500MB paid (Nginx limit_req + PHP проверка)

## Docker
- **Все `docker compose` команды — ТОЛЬКО через Makefile-таргеты** (`make docker-check`, `make up`,
  `make down`, `make build`, `make logs`…). Не дёргать `docker compose ...` напрямую. Нет нужного
  таргета — добавить в Makefile, не запускать руками. `make docker-check` = `docker compose config -q`.
- docker-compose.yml — основной, docker/limits.yml — лимиты для прода
- **Образы публикуем в наш Harbor-registry** (авторизация в консоли уже настроена). Сборка/пуш —
  через Makefile-таргеты. Remote-воркеры (AI и пр.) пуллят образ из Harbor на своём хосте.
- **KeyDB наружу НЕ публикуется** — доступ к очередям off-server только через HTTP pull-API (см. Queue Architecture).
- Каждый воркер — отдельный контейнер
- Файлы (вход и результат) — только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`); общего volume
  `/shared-files` больше нет
- KeyDB — единственный instance, несколько баз (0: cache, 1: sessions, 2: queues)

## Secrets / env
- **Секреты — только в `.env.local`** (gitignored). Makefile делает `include .env` + `-include .env.local` + `export`, поэтому значения из `.env.local` уходят в окружение и compose подхватывает их через `${VAR}`. В трекаемых `.env` / `.env.local_example` секреты держим ПУСТЫМИ (плейсхолдеры). Никогда не коммить реальные ключи.

## S3 / MinIO
- **Все операции с S3 — через MCP `minio`** (`mcp__minio__*`): бакеты, юзеры, политики, объекты, presign. Не дёргать `mc`/`mc admin` вручную. Шаренный endpoint — `apis3.xakki.ru` / `apis3.variantgood.com`; бакет результатов — `convertor-results` (`${S3_BUCKET_PREFIX}-results`). Ограничение MCP: создание кастомной IAM-политики недоступно — только встроенные (`readwrite`/`readonly`/…) через `policy_attach`.

## Frontend Rules
- Никаких npm install в проде — CDN для Alpine.js и HTMX, Tailwind через CDN play
- Для сборки (если нужна): Vite без тяжёлых зависимостей
- HTMX для динамики без написания JS (статус задачи, история)
- Alpine.js для интерактивных компонентов (drag & drop, модалки)

## Key Files
- `ROADMAP.md` — **канонический порядок выполнения MVP (7 стадий, приоритеты) + справочные данные (матрица форматов, лимиты, API, UI). Сверяться при планировании/старте задач.**
- `.claude/kanban/` — kanban-карточки задач (источник статуса реализации).
  - **Любую обнаруженную проблему/баг/нужную доработку (вкл. находки ревью) заводи карточкой в `.claude/kanban/grooming/`, если её ещё нет в канбане.** Не теряй находки в чате — фиксируй в grooming.
- `.env` — конфигурация (не коммитить секреты)
- `Makefile` — основные команды (build, test, migrate, queue)
