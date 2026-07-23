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

`worker-ai` (CPU-образ по умолчанию, `image: ${COMPOSE_PROJECT_NAME}/worker-ai:${AI_VARIANT:-cpu}`) поднимается вместе со всеми (`make up`), подключаясь по внутреннему `ws://ws-gateway:8091`; удалённые GPU-хосты (напр. домашний WSL+GPU) поднимают тот же воркер с `AI_VARIANT=cuda`/`AI_RUNTIME=nvidia` и подключаются к публичному `wss://` — оба независимые consumer'ы `conv.ai`, задачи балансируются между ними.

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

## Ключевые make-таргеты

| Таргет | Назначение |
|--------|-----------|
| `make init` | Первичная инициализация (build + up + migrate) |
| `make up` / `make down` / `make restart` | Старт / стоп / рестарт стека |
| `make build` / `make rebuild` | Сборка образов (`rebuild` — без кэша) |
| `make pull` | Подтянуть базовые образы из Harbor |
| `make ps` / `make logs` / `make logs-<svc>` | Статус / логи |
| `make harbor-login` | Логин в Docker-registry (Harbor) |
| `make docker-check` | Валидация compose (`config -q`) |
| `make migrate` | Doctrine-миграции |
| `make console CMD="…"` | Произвольная Symfony-консоль |
| `make tg-set-webhook` | Регистрация Telegram webhook |
| `make restart-php` / `make shell-php` | Рестарт / shell php-контейнера |
| `make composer CMD="…"` | Composer внутри контейнера |
| `make test` | PHPUnit + pytest БЕЗ провижининга `convertor-test`; та же PHPUnit-сюита, что и в `test-php-live` — DB-зависимые тесты падают с ошибкой доступа, если БД не поднята |
| `make test-php-live` | **Канонический CI-таргет**: провижинит `convertor-test` (БД + миграции), затем гоняет ту же PHPUnit-сюиту чисто, без DB-ошибок |
| `make phpstan` / `make cs` / `make cs-check` | Статанализ и code style |

`make help` — полный список.

## Аутентификация и доступ

- **Анонимная конвертация без логина** — гость по подписанной httpOnly-cookie `guest_id` (`ROLE_GUEST`). Исключения: AI-конвертации и Видео требуют логина (`403 auth_required`). При логине история гостя перепривязывается к пользователю.
- **Telegram-логин через бота** — magic-link на своём устройстве (same-device, не Login Widget): `POST /api/v1/auth/telegram/start` → deep-link `t.me/<bot>?start=<code>` → тап в боте (webhook) → magic-ссылка в чат → открытие на том же устройстве проверяет два секрета (nonce-cookie + linkSecret) → JWT + refresh.
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
