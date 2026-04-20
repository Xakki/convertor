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
- **Storage**: /shared-files/ локально → MinIO в проде

## Code Quality
- `composer test:phpstan` — обязательно исправлять все ошибки PHPStan
- `composer test:cs-fix` — автоисправление code style
- `composer test:cs-check` — ручное исправление остального
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
- Воркеры: Python скрипты, читают из KeyDB (redis-py), пишут результат в shared-files
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
- Path traversal защита: только внутри /shared-files/
- Авто-удаление через 24ч (Symfony Scheduler)
- Max size: 50MB free, 500MB paid (Nginx limit_req + PHP проверка)

## Docker
- docker-compose.yml — основной, docker-compose.resources.yml — лимиты для прода
- Каждый воркер — отдельный контейнер
- Shared volume: /shared-files/ монтируется во все сервисы
- KeyDB — единственный instance, несколько баз (0: cache, 1: sessions, 2: queues)

## Frontend Rules
- Никаких npm install в проде — CDN для Alpine.js и HTMX, Tailwind через CDN play
- Для сборки (если нужна): Vite без тяжёлых зависимостей
- HTMX для динамики без написания JS (статус задачи, история)
- Alpine.js для интерактивных компонентов (drag & drop, модалки)

## Ignored Files
- `libreoffice/app/tests/test_main.py` — интеграционный тест, требует запущенный контейнер
- `shared-files/**` — рабочая директория воркеров

## Key Files
- `.claude/info/plan.md` — мастер-план с таблицей форматов и фазами
- `.claude/info/progress.md` — текущий прогресс реализации
- `.env` — конфигурация (не коммитить секреты)
- `Makefile` — основные команды (build, test, migrate, queue)
