# Convertor Service

SaaS-сервис конвертации файлов всех форматов: **документы, изображения, аудио, видео, данные и AI-конвертации** (OCR, речь↔текст). Загружаете файл — получаете результат в нужном формате. Без обязательного логина для большинства конвертаций.

## Технологии

- **Backend:** PHP 8.5 + Symfony 7 (REST API `/api/v1/`, JWT, OpenAPI через NelmioApiDoc)
- **Frontend:** Twig + Alpine.js 3 + HTMX + Tailwind CSS (без тяжёлых SPA, всё с CDN)
- **Воркеры:** Python 3.12 — по одному контейнеру на категорию (libreoffice/document, ffmpeg audio+video, image, data, AI — CPU по умолчанию, GPU через `AI_VARIANT=cuda`+`AI_RUNTIME=nvidia`)
- **Очереди:** KeyDB Streams (Redis-совместимый) + Symfony Messenger
- **WS-Gateway:** асинхронный Python-сервис — единственный мост между очередями и воркерами
- **БД:** MariaDB 11 + Doctrine ORM
- **Хранилище:** только S3/MinIO (бакеты `${S3_BUCKET_PREFIX}-inputs` / `-results`)

## Архитектура

Сервис поднимается одним docker-compose стеком из **13 сервисов**:

`php`, `cron`, `mariadb`, `keydb`, `nginx`, `worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video`, `worker-image`, `worker-data`, `worker-ai`, `ws-gateway`, `metrics-exporter`.

`worker-ai` (CPU-образ по умолчанию, `image: ${IMAGE_NS}/worker-ai:${AI_VARIANT:-cpu}`) поднимается вместе со всеми (`make up`), подключаясь по внутреннему `ws://ws-gateway:8091`; удалённые GPU-хосты (напр. домашний WSL+GPU) поднимают тот же воркер с `AI_VARIANT=cuda`/`AI_RUNTIME=nvidia` и подключаются к публичному `wss://` — оба независимые consumer'ы `conv.ai`, задачи балансируются между ними.

### Транспорт: WS-Gateway как единственный читатель очередей

Ключевой инвариант: **ни один воркер не трогает KeyDB и S3 напрямую**. Всё общение идёт через WS-Gateway (WebSocket) и Symfony API (HTTP).

- **ws-gateway** — единственный, кто читает KeyDB Streams (`XREADGROUP`/`XACK`/`XAUTOCLAIM`). Каждый воркер — это WS-клиент gateway.
- KeyDB наружу не публикуется; удалённый воркер цепляется к публичному `wss://`.
- Воркеры не держат S3-креды и авторизуются статичным bearer-токеном (`WORKER_API_TOKEN`).

### Путь конвертации (end-to-end)

1. Пользователь загружает файл → Symfony кладёт его в S3 (`-inputs`), создаёт `Conversion` в БД и ставит задачу в KeyDB Stream `conv.<type>` (Messenger).
2. **ws-gateway** читает задачу из стрима и раздаёт её подключённому воркеру нужного типа по WS.
3. Воркер тянет вход через `GET /api/v1/worker/jobs/{id}/input` (Symfony стримит из S3), выполняет конвертацию.
4. **Малый результат** (≤ `WS_RESULT_INLINE_MAX`, обычно ≤256 KB) — воркер шлёт inline по WS → gateway → внутренний relay-эндпоинт Symfony (защита `GATEWAY_INTERNAL_TOKEN`) → Symfony пишет S3+БД. **Большой результат** — воркер делает `POST /api/v1/worker/jobs/{id}/result` (multipart) напрямую в Symfony.
5. Gateway делает `XACK`; живой статус (`conv:status`) пишет тоже gateway. Пользователь забирает результат из S3 (`-results`) по presigned-ссылке.

Воркеры **flag-agnostic**: валидируют только форматы (source→target) и выполняют конвертацию; выбор поведения (OCR / STT / TTS) и нужного стрима — на стороне API.

## Первый запуск

Требуется Docker + Docker Compose. Все команды — через `make` (напрямую `docker compose` не дёргать).

```bash
# 1. Секреты и локальная конфигурация
cp .env.local_example .env.local
#    заполнить .env.local (БД, S3/MinIO, токены воркеров/gateway, Telegram-бот)

# 2. Первичная инициализация: build + up + migrate (тарифные планы сидит миграция Version20260419000001)
make init
```

Последующие запуски:

```bash
make up        # поднять стек
make down      # остановить
make restart   # перезапустить
make ps        # статус контейнеров
make logs      # логи всех сервисов (make logs-<service> — по одному)
```

### Создать первого админа

Админ-панель (`/admin` UI + `/api/v1/admin/*`) закрыта `ROLE_ADMIN`. UI управления ролями нет — первый админ выдаётся консольной командой:

```bash
make console CMD="app:user:make-admin <email|id>"
```

## Команды

Полный список — `make help` (Makefile = единственный источник правды по таргетам).

### Окружение

Поведение всех таргетов и compose определяется только env-файлами, в порядке наложения:

`.env` (база, трекается) → `.env.local` (секреты хоста, gitignored) → `.env.test` (только при `TEST=1`).

Переменные, нужные **только** бэкенду, живут в `app-symfony/.env*` и в корневых не дублируются.

### Тесты

`make test` поднимает **отдельный тест-стенд** (свой compose-проект `xakki-convertor-test`:
свои контейнеры, тома, порты 110xx, БД `convertor-test`) и гоняет на нём PHPUnit + pytest
воркеров + drift-guard. Dev-стенд при этом не затрагивается — стенды живут параллельно.
`make test-down` сносит тест-стенд вместе с томами.

Отдельные тест-таргеты требуют явного тест-окружения: `make TEST=1 test-php`,
`make TEST=1 test-e2e`, `make TEST=1 test-api-integration`, `make TEST=1 test-gateway`.

### CI (GitHub Actions)

Workflow: [`.github/workflows/ci.yml`](.github/workflows/ci.yml). Запускается на каждый PR и вручную (`workflow_dispatch`).

**Блокирующий job `Quality gates`** (должен быть required в branch protection, иначе merge не блокируется — только настройки репозитория Settings → Branches, YAML этого не делает):

- `make TEST=1 phpstan`
- `make TEST=1 cs-check`
- `make TEST=1 test-php`
- `make TEST=1 test-python`
- `make TEST=1 test-drift`

Стенд — тот же изолированный `xakki-convertor-test`, что и локально (`make test-up` / `make test-down`).

**Warn-only job `E2E / integration (warn-only)`** (`continue-on-error: true`, параллельно с gates):

- `make TEST=1 test-e2e`
- `make TEST=1 test-api-integration`

Без `S3_SECRET` e2e/integration пропускаются с предупреждением (job не падает).

**Secrets (Settings → Secrets and variables → Actions):**

| Secret | Назначение |
|--------|------------|
| `DOCKER_USER` | Логин Harbor (`harbor.xakki.ru`) — обязателен для gates |
| `DOCKER_PASS` | Пароль Harbor — обязателен для gates |
| `S3_SECRET` | S3 для warn-only e2e/integration; gates используют плейсхолдеры из `.env` + `.env.test` |

**Особенности CI:** без fluent-логирования (`COMPOSE_FILE=docker-compose.yml:docker/limits.yml`, без `fluent-logging.yml`); `PUID`/`PGID` берутся с runner'а. Полный прогон GHA с этого хоста не проверяется — локально: `make docker-check`, при наличии — `actionlint` / `yamllint` на workflow.

## Аутентификация и доступ

- **Анонимная конвертация без логина** — гость по подписанной httpOnly-cookie `guest_id` (`ROLE_GUEST`). Исключения: AI-конвертации и Видео требуют логина (`403 auth_required`). При логине история гостя перепривязывается к пользователю.
- **Telegram-логин через бота** — pairing + poll (same-device, same-tab, не Login Widget): `POST /api/v1/auth/telegram/start` → deep-link `t.me/<bot>?start=<code>` → тап «Войти» в боте (webhook) → исходная вкладка поллит `GET /api/v1/auth/telegram/poll?code=` с nonce-cookie → JWT + refresh.
- **JWT** — TTL 1h (LexikJWT), **refresh-token** — 30 дней (httpOnly cookie, ротация в Redis).
- **SMS OTP** — резервный, пока заглушка (501).

Регистрация webhook: `make tg-set-webhook` (нужны `TELEGRAM_WEBHOOK_SECRET` в `app-symfony/.env.local` + публичный `API_URL`).

## Оплата

- **MVP — только Telegram Stars** (XTR, через Bot API: invoice → `successful_payment` webhook).
- Вне MVP (заморожено): Stripe, Cryptomus.

## Куда смотреть дальше

- **`ROADMAP.md`** — канонический порядок MVP (7 стадий), матрица форматов, лимиты, API, UI.
- **`CLAUDE.md`** — правила проекта, детали архитектуры очередей/транспорта, auth, S3, секреты.
- **`.claude/kanban/`** — карточки задач (источник статуса реализации).
