### E2E magic-link callback флоу с мок-ботом

**Criticality:** Low

**TAGS:**
- test
- auth

**Description:**
Выделено из [[e2e-login-helper-magic-link]] (2026-07-11). Та карта закрывает только
логин-хелпер интеграционных тестов через прямую генерацию JWT в фикстуре (минуя
Telegram). Отдельно нужен e2e-набор для самого флоу magic-link callback, чтобы
покрыть логику двух секретов, one-time и no-burn, которую фикстура-шорткат не трогает.
Эту карту НЕ блокирует и ей не блокируется.

**Problem:**
Флоу `POST /api/v1/auth/telegram/start` → webhook (`POST /api/v1/telegram/webhook`) →
`GET /api/v1/auth/telegram/callback` требует реального Telegram round-trip, который в
e2e напрямую не воспроизвести. Без покрытия регрессии в проверке секретов
(nonce-cookie + linkSecret), атомарности one-time и no-burn на mismatch пройдут молча.

**Impact:**
Не покрыт критичный auth-путь: session-fixation-защита (nonce-cookie) и
account-takeover-защита (linkSecret), one-time-инвалидация кода. Регрессии в них —
дыра в аутентификации.

**Recommendation:**
Добавить e2e-кейсы на весь round-trip с застабленным/мок-Telegram-ботом:
- `/auth/telegram/start` ставит httpOnly-cookie `tg_login_nonce`, отдаёт `code` + deep-link;
- застабленный webhook авторизует `code` (status-guard) и «доставляет» magic-ссылку;
- `/auth/telegram/callback` проверяет ОБА секрета (nonce-cookie + linkSecret из query)
  атомарно (Lua), выдаёт JWT+refresh на успехе;
- негативные кейсы: mismatch любого секрета → no-burn (код остаётся валиден),
  повторный успешный callback → one-time (второй раз отказ).

**Acceptance Criteria:**
- E2E покрывает happy-path round-trip start→webhook→callback с мок-ботом.
- Проверены оба секрета (nonce-cookie + linkSecret), one-time, no-burn на mismatch.
- Tests/QA green: `make test` / `make test-php-live` (live-стек), `make phpstan`, `make cs-check`.

**Open questions:** *(grooming — нужен разбор подхода к мок-боту)*
- Как стабить Telegram round-trip: мок Bot API HTTP-клиента vs прямой вызов
  webhook-хендлера в тесте vs test-двойник bot-сервиса.
- Где хранить состояние code/nonce в e2e (реальный KeyDB тест-стека vs in-memory).

**Контекст:** выделено из [[e2e-login-helper-magic-link]] (2026-07-11).

**Status:** grooming.
