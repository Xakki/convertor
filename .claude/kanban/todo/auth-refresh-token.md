### Auth: refresh-token (JWT refresh 30д, httpOnly cookie)

**Критичность:** Medium

**TAGS:**
- feature
- auth

**Описание:**
Доделать механику refresh-token согласно правилу CLAUDE.md: «JWT TTL 1h + refresh 30д в httpOnly cookie». Сейчас реализован только access-token (TTL 1ч); refresh-механики нет. Вынесено из задачи `backend-hardening-bugs` (2026-06-22).

**Проблема:**
- Access-token живёт 1ч, refresh отсутствует → пользователь разлогинивается каждый час, нет способа продлить сессию без повторного Telegram-логина.

**Решение (ориентир — образец ExRate + стандартный паттерн):**
- Выпуск пары access (1ч) + refresh (30д) при логине.
- Refresh-token в **httpOnly + Secure + SameSite cookie** (не в JSON-ответе).
- Эндпоинт `POST /api/v1/auth/refresh`: по валидному refresh выдаёт новый access (+ротация refresh).
- Хранение/отзыв refresh: выбрать хранилище (БД-таблица refresh-токенов vs KeyDB) с возможностью инвалидации (logout, компрометация).
- Logout: инвалидировать refresh.

**Открытые вопросы (решить при старте):**
- Хранилище refresh: отдельная Doctrine-сущность (`RefreshToken`) или KeyDB (db 1 sessions)?
- Ротация: одноразовый refresh (rotate-on-use + reuse-detection) или продлеваемый?
- Атрибуты cookie и домен/путь для прод (за shared-nginx).

**Критерии приёмки:**
- Логин выдаёт access + refresh (refresh — httpOnly cookie).
- `/api/v1/auth/refresh` обновляет access по валидному refresh; невалидный/просроченный → 401.
- Logout инвалидирует refresh.
- Тесты на refresh happy-path + истёкший/отозванный.

**Decisions:**
- 2026-06-22: выделено из `backend-hardening-bugs` по решению пользователя — refresh-механика слишком объёмна для смешивания с баг-фиксами.
