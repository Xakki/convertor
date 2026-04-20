# Convertor Service — Progress Tracker
> Last updated: 2026-04-20

## Current Status: 🟢 Фаза 1 — Скелет готов

## Completed
- [x] LibreOffice микросервис (Python/aiohttp): doc/docx/odt/pdf → docx/txt/md
- [x] Docker инфраструктура: MariaDB, Nginx, KeyDB, PHP-FPM, все воркеры
- [x] Тесты для LibreOffice воркера (test_main.py)
- [x] Мастер-план (.claude/info/plan.md)
- [x] CLAUDE.md с правилами проекта
- [x] **app/** — PHP 8.5 + Symfony 7 skeleton (39 файлов)
  - Entity: User, Plan, Conversion, FileStorage, Payment
  - Enums: ConversionStatus, FileCategory, PaymentStatus, PaymentGateway
  - Services: TelegramAuthService, ConversionRegistry, ConversionManager, QuotaService
  - Controllers: AuthController, ConversionController
  - Messenger: ConversionMessage + Handler
  - Migration: все таблицы + seed планов
  - Config: Doctrine, Messenger (KeyDB), Security (JWT), NelmioApiDoc
- [x] **workers/** — Python 3.12 воркеры (19 файлов)
  - BaseWorker: KeyDB queue, retry, health endpoint, graceful shutdown
  - LibreofficeWorker (documents queue)
  - FfmpegWorker (media queue)
  - ImageWorker + OCR (images queue)
  - AiWorker: Whisper STT + TTS (ai queue)
  - DataWorker: CSV/JSON/XML/YAML (data queue)
  - Тесты: test_base_worker, test_data_worker
- [x] **frontend/** — Alpine.js + HTMX + Tailwind (15 файлов)
  - index.html: drag&drop конвертер, polling статуса
  - dashboard.html: история, квота
  - pricing.html: тарифы, Stripe + Telegram Stars кнопки
  - admin/index.html: статистика, графики, очереди
  - components: header, footer, login-modal (Telegram Widget + SMS)
  - JS: app.js (Alpine store, apiFetch), upload.js, auth.js
- [x] **docker/** — инфраструктура (14 файлов)
  - docker-compose.yml: 11 сервисов (nginx, php-fpm, mariadb, keydb, libreoffice, 5 воркеров)
  - Dockerfiles для всех воркеров
  - nginx/conf.d/default.conf: API → php-fpm, SPA fallback
  - Makefile: полный набор команд
  - .env обновлён

## Next Steps (Фаза 2 — Запуск)
1. `composer install` в `app/`
2. Генерация JWT ключей: `php bin/console lexik:jwt:generate-keypair`
3. `make init` — поднять всё окружение, накатить миграции
4. Настроить `TELEGRAM_BOT_TOKEN` в .env
5. Проверить работу LibreOffice очереди end-to-end
6. Реализовать платёжные интеграции (Фаза 4)

## Что осталось по плану
- [ ] Фаза 2: FFmpeg, Image, Data воркеры — проверить/доработать под реальные зависимости
- [ ] Фаза 3: AI воркер — скачать модель Whisper, настроить TTS
- [ ] Фаза 4: Платежи (Telegram Stars webhook, Stripe Checkout, Cryptomus)
- [ ] Фаза 5: Доработка Admin panel
- [ ] Фаза 6: S3/MinIO, rate limiting, очистка файлов, метрики
