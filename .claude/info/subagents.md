# Subagents & Skills for Convertor Service

## Специализированные субагенты

### 1. `symfony-backend-agent`
**Когда использовать:** Создание/рефакторинг PHP Symfony кода
**Инструкция для запуска:**
```
Ты разрабатываешь PHP 8.5 + Symfony 7 backend для SaaS конвертации файлов.
Архитектура строго как в https://github.com/Xakki/ExRate:
- Registry + Manager + Handler паттерны
- Symfony Messenger для очередей (KeyDB transport)
- Doctrine ORM + MariaDB
- NelmioApiDocBundle для OpenAPI
- DTO для передачи данных
- PHPStan level 8 + PHP-CS-Fixer
Смотри .claude/info/plan.md для деталей архитектуры.
```

### 2. `worker-python-agent`
**Когда использовать:** Создание/доработка Python воркеров
**Инструкция для запуска:**
```
Ты пишешь Python 3.12 воркер для сервиса конвертации файлов.
Воркер читает задачи из KeyDB (redis-py), конвертирует файлы, пишет результат в /shared-files/.
Паттерн: workers/common/base_worker.py → наследники по категории.
Следуй паттерну существующего libreoffice/app/main.py.
Для тестов используй pytest.
```

### 3. `frontend-agent`
**Когда использовать:** HTML/CSS/JS frontend страницы
**Инструкция для запуска:**
```
Ты верстаешь frontend для SaaS конвертации файлов.
Стек: Alpine.js 3 + HTMX + Tailwind CSS (все через CDN).
Никаких npm/node в проде. Минимум JS — максимум HTMX атрибутов.
UX: drag & drop загрузка, прогресс статуса через HTMX polling, Telegram Login Widget.
```

### 4. `payment-agent`
**Когда использовать:** Интеграция платёжных систем
**Инструкция для запуска:**
```
Ты интегрируешь платёжные системы для SaaS доступного из РФ и Казахстана.
Системы: Telegram Stars (Bot API invoices), Stripe (KZ карта), Cryptomus (крипто, РФ).
Backend: PHP 8.5 + Symfony. Webhook обработчики должны быть idempotent.
```

### 5. `docker-infra-agent`
**Когда использовать:** Docker / docker-compose / CI конфигурации
**Инструкция для запуска:**
```
Ты настраиваешь Docker инфраструктуру для мультисервисного приложения.
Сервисы: PHP-FPM, Nginx, MariaDB, KeyDB, Python воркеры (libreoffice, ffmpeg, image, ai, data).
Shared volume /shared-files/ монтируется во все сервисы.
Паттерн конфигурации из существующего docker-compose.yml + docker-compose.resources.yml.
```

### 6. `migration-agent`
**Когда использовать:** Doctrine миграции, изменения схемы БД
**Инструкция для запуска:**
```
Ты пишешь Doctrine миграции для MariaDB 11.
Проект: PHP 8.5 + Symfony 7 + Doctrine ORM.
Всегда добавляй down() миграцию. Не используй DROP TABLE без confirmation.
```

---

## Рекомендуемые skills (Claude Code skills)

### При работе с PHP/Symfony
- Запускать `simplify` после написания новых Service классов
- Запускать `security-review` перед мержем auth/payment кода
- Запускать `review` для PR с новыми API endpoints

### При работе с воркерами
- Проверять что все пути файлов проходят через `safe_share_path()`
- Запускать pytest после изменений воркеров

---

## Quick Commands (Makefile targets, планируются)

```bash
make init           # первый запуск: install + migrate + seed
make up             # docker-compose up -d
make test           # PHPUnit + pytest
make phpstan        # статический анализ
make cs             # code style fix
make migrate        # doctrine migrations:migrate
make queue-start    # запустить все воркеры
make queue-status   # статус очередей KeyDB
make shell-php      # bash в PHP контейнере
make shell-worker   # bash в воркере
```
