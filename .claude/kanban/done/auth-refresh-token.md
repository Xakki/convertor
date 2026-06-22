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

**Критерии приёмки:**
- Логин выдаёт access (JSON) + refresh (httpOnly cookie).
- `POST /api/v1/auth/refresh` обновляет access по валидному refresh + ротирует refresh; невалидный/просроченный → 401.
- Повторное использование уже ротированного refresh → отзыв всей family + 401 (reuse-detection).
- `POST /api/v1/auth/logout` инвалидирует refresh (удаляет family) + чистит cookie.
- Тесты: happy-path login→refresh→refresh, истёкший/неизвестный → 401, reuse уже использованного → отзыв family.

**Decisions:**
- 2026-06-22: выделено из `backend-hardening-bugs` по решению пользователя — refresh-механика слишком объёмна для смешивания с баг-фиксами.
- 2026-06-22: **хранилище = KeyDB db 1 (sessions)** (решение пользователя). Не Doctrine-сущность.
- 2026-06-22: **ротация = rotate-on-use + reuse-detection** (отзыв всей family при повторном использовании старого refresh).
- 2026-06-22: команда — be-dev (impl) + tester (функц. тесты) + reviewer (`/code-review`).

**Дизайн (KeyDB reuse-detection, family-модель):**
- Стек: Lexik JWT (access, TTL 3600s, JSON) остаётся как есть. Refresh — opaque.
- Refresh-cookie value = `{familyId}.{secret}`: `familyId` = uuid (локатор), `secret` = ≥32 байта CSPRNG (base64url).
- Ключ KeyDB (db 1): `rt:{familyId}` → JSON `{userId, secretHash, prevSecretHash, graceUntil, exp}`, TTL = остаток до `exp` (исходный срок family, 30д от login). `secretHash` = hash(secret) (sha256/HMAC).
- Login: создать familyId+secret, записать `rt:{familyId}` (prevSecretHash=null), поставить cookie.
- `/refresh` (**атомарно — Lua-скрипт или WATCH/MULTI**, read-compare-write нельзя без атомарности): распарсить cookie → загрузить `rt:{familyId}`.
  - Нет ключа → 401 (истёк/неизвестен).
  - `hash(secret) == secretHash` (текущий) → ротация: новый secret, `prevSecretHash := secretHash`, `secretHash := hash(new)`, `graceUntil := now+GRACE` (напр. 30–60с), TTL = min(остаток, исходный exp) — **НЕ продлевать сверх исходного срока family**; выдать новый access JWT + новый cookie.
  - `hash(secret) == prevSecretHash` И `now < graceUntil` → **доброкачественный повтор** (конкурентный/ретрай-рефреш): НЕ отзывать; повторно выдать актуальный токен (можно вернуть текущий secret без новой ротации) + access JWT. **Это критично:** SPA/HTMX-фронт шлёт несколько 401-запросов параллельно → каждый дёргает /refresh с тем же cookie; без grace это вызвало бы ложный отзыв family и разлогин.
  - Иначе (несовпадение, или prev вне grace) → **reuse** → `DEL rt:{familyId}` (отзыв всей family) + 401.
  - После валидации, до выпуска access: загрузить User по `userId`, проверить `isActive` (деактивированный → 401, отзыв family).
- Logout: `DEL rt:{familyId}` + очистка cookie.
- Cookie-атрибуты: `HttpOnly`, `Secure` (выкл. в dev/test через env), `SameSite` через env (**дефолт Lax**; если фронт и API окажутся cross-site за shared-nginx — нужен `None; Secure` + CORS credentials — проверить топологию прода перед хардкодом), `Path=/api/v1/auth`, host-only домен. Все атрибуты — через env-флаги.
- KeyDB-клиент: проверить существующий Redis/Messenger DSN, завести сервис/коннект на db index 1 (sessions), не трогать db 2 (queues).
- **Логику family create/rotate/revoke вынести в сервис `RefreshTokenService`** (не инлайн в контроллере) — переиспользуется будущим SMS-логином + юнит-тестируется против fake-store без живого KeyDB.
- **Известное ограничение (в скоупе, явно):** logout удаляет refresh-family, но уже выпущенный access-JWT остаётся валиден до своего 1ч-истечения (свойство stateless JWT) — приемлемо для этой задачи.

**Execution Log:**
- 2026-06-22: реализация (be-dev). Создано: `src/Service/Auth/RefreshTokenService.php` (issueFamily/rotate/revoke, атомарный rotate через Redis EVAL Lua), `RefreshResult.php` (VO), `RefreshCookieFactory.php`. Изменено: `AuthController.php` (telegram() ставит refresh-cookie; новые `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout`), `config/services.yaml` (сервис `app.redis.sessions` на db1 + env-дефолты).
- KeyDB-клиент: отдельный service id `app.redis.sessions` (`REDIS_SESSIONS_DSN=redis://keydb:6379?dbindex=1`) — нет bleed в db2 (queues).
- secretHash = `hash_hmac('sha256', secret, APP_SECRET)`; хранятся только хэши. exp = абсолютный login+30д, ротация TTL не продлевает сверх exp.
- env: `REDIS_SESSIONS_DSN`, `REFRESH_TTL=2592000`, `REFRESH_GRACE=60`, `REFRESH_COOKIE_SECURE=true` (test=false), `REFRESH_COOKIE_SAMESITE=lax`, `REFRESH_COOKIE_NAME=refresh_token` — дефолты в `services.yaml` env() params.
- Ревью (`/code-review` high): блокеров нет. Две hardening-находки исправлены: (1) Lua `cjson.decode` обёрнут в `pcall` + nil-guard `exp` → corrupt/legacy значение само-лечится (`DEL` family + чистый 401, без 500-wedge); (2) constructor бросает `InvalidArgumentException` при пустом APP_SECRET (fail-fast).
- Тесты (tester): `tests/Unit/Service/Auth/RefreshTokenServiceTest.php` (fake `\Redis`-double, реплицирует Lua), `tests/Integration/Service/Auth/RefreshTokenServiceKeyDbTest.php` (живой KeyDB db1, self-skip если недоступен), `tests/Functional/Controller/Api/AuthRefreshControllerTest.php` (WebTestCase: 401+clear, happy-path, logout, deactivated→401+revoke). +21 тест / 104 ассерта.
- `tests/bootstrap.php`: форс `APP_ENV=test` (dev-контейнер экспортит APP_ENV=dev и перебивал phpunit) — ратифицировано, закоммичено.
- Дозакрыт критерий приёмки #1: функц.тест `testTelegramLoginIssuesAccessTokenAndRefreshCookie` (POST `/api/v1/auth/telegram` валидным Telegram-hash → 200 + JSON `token` + `Set-Cookie refresh_token` HttpOnly/Path=/api/v1/auth/SameSite=lax/Secure=false).
- **Verification (team-lead, независимо):** `make phpstan` → [OK] No errors; `make cs-check` → 0 fixable; `make test-php` → **OK (59 tests, 250 assertions)**. Все 4 критерия приёмки покрыты тестами.

**Deploy-gate (требует внимания перед прод):**
- **SameSite:** дефолт `lax` — корректно только если фронт и API same-site за shared-nginx. Если cross-site → выставить `REFRESH_COOKIE_SAMESITE=none` (+ `Secure` + CORS credentials). Проверить топологию прода.
- **Env-ручки** не в трекаемом корневом `.env_dist`: `REDIS_SESSIONS_DSN`, `REFRESH_TTL`, `REFRESH_GRACE`, `REFRESH_COOKIE_SECURE`, `REFRESH_COOKIE_SAMESITE`, `REFRESH_COOKIE_NAME` (дефолты prod-sane в `services.yaml`, но ops их «не видит»).

**Follow-ups (заведены в grooming):**
- `ci-test-db-provisioning` — кодифицировать test-DB (convertor_test) для CI через миграции (не schema:create); иначе live-dep тесты молча скипаются вне dev.
- `refresh-token-injectable-clock` — внедрить инъектируемые часы в RefreshTokenService для чистого тестирования grace-окна (сейчас tester «состаривал» graceUntil в сторе).
