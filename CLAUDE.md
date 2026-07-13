# Convertor Service — Project Rules for Claude Code

## Project Overview
SaaS-сервис конвертации файлов всех форматов. PHP 8.5 + Symfony 7 backend, Alpine.js + HTMX + Tailwind frontend, Python воркеры, KeyDB очереди.

## Язык
Вся документация (`docs/`, `README`, kanban-карточки, spec'и) и комментарии в коде — **на русском**. Технические идентификаторы (имена файлов, символов, env-переменных, команд) остаются как есть.

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
- Имена каналов: `conv.document`, `conv.image`, `conv.audio`, `conv.video`, `conv.data`, `conv.ai`
- **Единая модель транспорта: ВСЕ воркеры (on-server + remote) — WS-клиенты gateway; никаких Redis-list очередей и общего тома `/shared-files`.** Единственный читатель KeyDB Streams (`XREADGROUP`/`XACK`/`XAUTOCLAIM`) — новый асинхронный Python-сервис **WS-Gateway**; воркеры (remote AI + 4 on-server: document/libreoffice, audio+video/ffmpeg, image, data) НЕ читают `conv.<type>` и НЕ делают self-XACK. KeyDB наружу НЕ публикуется; удалённый воркер (напр. AI на домашнем WSL+GPU) подключается к публичному `wss://`.
- **Ни один воркер (on-server в т.ч.) не трогает KeyDB или S3 напрямую — только gateway (WS) + Symfony API (bulk-нагрузки).** Вход задачи: `GET /api/v1/.../jobs/{id}/input` (Symfony стримит из S3). Малый результат ≤256 KB: inline по WS → gateway → внутренний relay-эндпоинт → Symfony пишет (S3 + БД). Большой результат: воркер `POST /jobs/{id}/result` (multipart) → Symfony пишет, затем `result{resultKey}` по WS → gateway делает XACK. Воркеры не держат S3-креды. Auth — статичный bearer-токен в конфиге воркера. Детали — [[ws-worker-transport-design]].
- **Воркеры flag-agnostic: валидируют ТОЛЬКО форматы (входные данные + конвертацию source→target) и выполняют её. Флаги (`ocr`, `subType` и пр.) воркер НЕ читает.** Выбор поведения — из пары (sourceFormat, targetFormat) (напр. растр→txt/md/docx = OCR; audio→text = STT; text→audio = TTS). Какой именно stream получит задачу — решает БЭК/API: для неоднозначных пар (один (from→to) умеют несколько воркеров, напр. `pdf→txt`: document-extract vs image-OCR) в API есть флаг, выбирающий доступный stream (первый экземпляр — `ocr`→`Conversion::isOcr`→`streamFor()`). AI — в крайнем случае.
- PHP side: ставит задачу в Stream (Messenger) + персистит результат через internal relay/ConversionResultPersister; живой статус (`conv:status`) пишет gateway, не PHP.

## Authentication
- **Telegram-логин через бота — magic-link на своём устройстве (same-device), НЕ Login Widget.** Виджет снят (`login-widget-bot-username` = obsolete). Флоу: сайт `POST /api/v1/auth/telegram/start` → ставит httpOnly-cookie `tg_login_nonce` + отдаёт `code` + deep-link `t.me/<bot>?start=<code>`; юзер жмёт «Войти» в боте → webhook (`POST /api/v1/telegram/webhook`, защита `X-Telegram-Bot-Api-Secret-Token`) авторизует code (status-guard: первый тап), шлёт в чат magic-ссылку `…/api/v1/auth/telegram/callback?code=&s=<linkSecret>`; юзер открывает её на том же устройстве → `GET /callback` проверяет **оба** секрета (nonce-cookie + linkSecret из query, атомарно в Lua, one-time, no-burn на mismatch) → JWT+refresh + 302 на `/`. Два секрета обязательны: nonce-cookie бьёт session-fixation, доставляемый-в-чат linkSecret бьёт account-takeover. Детали — skill `redesign-auth-access-contract`.
- **Анонимная конвертация без логина, кроме `isAi`/`category=Video`** — guest-User по подписанной httpOnly-cookie `guest_id` (`ROLE_GUEST`); ai/video → 403 `auth_required`. При логине история guest'а перепривязывается к реальному User.
- SMS OTP — резервный, пока заглушка (501).
- JWT: TTL 1h (LexikJWT), refresh-token 30 дней (opaque family, httpOnly cookie, Redis Lua-ротация). Фронт после `/callback`-редиректа тянет access-JWT через `POST /api/v1/auth/refresh`.
- Регистрация webhook: `make tg-set-webhook` из корня (нужны `TELEGRAM_WEBHOOK_SECRET` в `app-symfony/.env.local` + публичный `API_URL` в `.env`). Секрет один и тот же для регистрации и для проверки заголовка `X-Telegram-Bot-Api-Secret-Token`.

## Payments
- **(MVP: только Telegram Stars; Stripe/Cryptomus — вне MVP)**
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
