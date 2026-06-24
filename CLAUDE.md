# Convertor Service — Project Rules for Claude Code

## Project Overview
SaaS-сервис конвертации файлов всех форматов. PHP 8.5 + Symfony 7 backend, Alpine.js + HTMX + Tailwind frontend, Python воркеры, KeyDB очереди.

## Architecture Reference
Архитектура backend строго по образцу **https://github.com/Xakki/ExRate**:
- Registry pattern для конверторов (аналог ProviderRegistry)
- Manager pattern для оркестрации
- Symfony Messenger для очередей
- DTO для передачи данных между слоями
- AbstractBase для воркеров

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
- REST API под префиксом `/api/`
- JSON request/response
- JWT аутентификация (Bearer token)
- OpenAPI документация через NelmioApiDocBundle
- Версионирование: `/api/v1/`

## Queue Architecture
- Каждый тип конвертации — отдельный KeyDB queue channel
- Имена каналов: `conversion.documents`, `conversion.images`, `conversion.audio`, `conversion.video`, `conversion.ai`
- **Локальные (on-server) воркеры — ТОЛЬКО KeyDB Streams (consumer groups) + S3 in/out.** Никаких Redis-list очередей и общего тома `/shared-files`.
- **Remote-воркеры (вне сервера) — через универсальный HTTP pull-API, НЕ напрямую в KeyDB/S3.** KeyDB наружу НЕ публикуется, поэтому off-server воркер (напр. AI на домашнем WSL+GPU) тянет задания по API (short-poll ~10 сек), а файлы (вход и результат) идут ЧЕРЕЗ API (не через S3 напрямую). Auth — статичный bearer-токен в конфиге воркера. API — шлюз над Streams: claim из consumer-group `conv.<type>` (lease), ack по `result`/`fail`. API задуман универсальным для всех типов воркеров. Первый потребитель — AI-воркер ([[validate-ai-worker]]).
- **Воркеры flag-agnostic: валидируют ТОЛЬКО форматы (входные данные + конвертацию source→target) и выполняют её. Флаги (`ocr`, `subType` и пр.) воркер НЕ читает.** Выбор поведения — из пары (sourceFormat, targetFormat) (напр. растр→txt/md/docx = OCR; audio→text = STT; text→audio = TTS). Какой именно stream получит задачу — решает БЭК/API: для неоднозначных пар (один (from→to) умеют несколько воркеров, напр. `pdf→txt`: document-extract vs image-OCR) в API есть флаг, выбирающий доступный stream (первый экземпляр — `ocr`→`Conversion::isOcr`→`streamFor()`). AI — в крайнем случае.
- PHP side: только ставит задачу + обновляет статус по callback/polling

## Authentication
- Telegram Login Widget как основной метод
- SMS OTP как резервный (SMSC.ru)
- Верификация Telegram hash: HMAC-SHA256 с bot token
- JWT: TTL 1h, refresh token 30 дней в httpOnly cookie

## Payments
- Telegram Stars: через Telegram Bot API (invoice → successful_payment webhook)
- Stripe: через Stripe Checkout (KZ карта поддерживается)
- Cryptomus: REST API v1 (USDT/BTC, доступно из РФ)

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
  `/shared-files` больше нет (убран задачей storage-input-to-s3 2026-06-20)
- KeyDB — единственный instance, несколько баз (0: cache, 1: sessions, 2: queues)

## Secrets / env
- **Секреты — только в `.env.local`** (gitignored). Makefile делает `include .env` + `-include .env.local` + `export`, поэтому значения из `.env.local` уходят в окружение и compose подхватывает их через `${VAR}`. В трекаемых `.env` / `.env_dist` секреты держим ПУСТЫМИ (плейсхолдеры). Никогда не коммить реальные ключи.

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
