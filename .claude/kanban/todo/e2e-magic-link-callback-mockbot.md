### E2E Telegram-логин poll-флоу с мок-ботом

**Criticality:** Low

**TAGS:**
- test
- auth

**Description:**
Выделено из [[e2e-login-helper-magic-link]] (2026-07-11). Та карта закрывает только
логин-хелпер интеграционных тестов через прямую генерацию JWT в фикстуре (минуя
Telegram). Отдельно нужен e2e-набор для самого auth-round-trip, чтобы покрыть логику
one-time и no-burn, которую фикстура-шорткат не трогает. Эту карту НЕ блокирует и ей
не блокируется.

⚠️ **Ретаргет 2026-07-28:** зависит от [[telegram-login-confirm-in-bot-no-magic-link]].
Модель логина меняется с magic-link (`callback` + `linkSecret`) на **poll**:
исходная вкладка опрашивает `GET /api/v1/auth/telegram/poll?code=…` и обменивает код
на сессию по двум факторам `code` + nonce-cookie (linkSecret выпилен). Эта e2e-карта
должна ехать ПОСЛЕ или ВМЕСТЕ с той — иначе покроет удаляемый `callback`.

**Problem:**
Флоу `POST /api/v1/auth/telegram/start` → webhook (`POST /api/v1/telegram/webhook`,
апрув кода) → `GET /api/v1/auth/telegram/poll` требует реального Telegram round-trip,
который в e2e напрямую не воспроизвести. Без покрытия регрессии в проверке
nonce-cookie, атомарности one-time и no-burn на mismatch пройдут молча.

**Impact:**
Не покрыт критичный auth-путь: session-fixation-защита (nonce-cookie),
one-time-инвалидация кода, no-burn на mismatch. Регрессии в них — дыра в
аутентификации.

**Recommendation:**
Добавить e2e-кейсы на весь round-trip с застабленным/мок-Telegram-ботом:
- `/auth/telegram/start` ставит httpOnly-cookie `tg_login_nonce`, отдаёт `code` +
  deep-link;
- застабленный webhook авторизует `code` (status-guard, first-tap-wins);
- `/auth/telegram/poll`:
  - `pending` пока апрува не было (`204`);
  - на успехе (`code` + верный nonce-cookie, status=authorized) → выдаёт
    refresh-cookie, мержит guest-историю, `DEL` кода (one-time);
- негативные кейсы: неверный/отсутствующий nonce-cookie → отказ, код НЕ сгорает
  (no-burn); повторный успешный `poll` по тому же коду → one-time (второй раз отказ);
  истёкший код → `410`/`gone`.

**Acceptance Criteria:**
- E2E покрывает happy-path round-trip start→webhook→poll с мок-ботом.
- Проверены: nonce-cookie как единственный обменный фактор, one-time, no-burn на
  mismatch, poll `pending`→`authorized`.
- Никаких ссылок на удалённый `callback`/`linkSecret`.
- Tests/QA green: `make test` / `make test-php-live` (live-стек), `make phpstan`,
  `make cs-check`.

**Decisions:** Стаб Telegram round-trip = мокать ТОЛЬКО исходящий Bot API
HTTP-клиент (`TelegramBotClient`/HTTP), а реальные контроллеры
`start`→`webhook`→`poll` гонять через HTTP — проверяем настоящий флоу
nonce-cookie, one-time, no-burn на mismatch, защиту заголовка
`X-Telegram-Bot-Api-Secret-Token`. Состояние code/nonce — в реальном KeyDB
тест-стека `make test-e2e` с ИЗОЛИРОВАННЫМ тест-префиксом (не in-memory), в русле
существующего e2e (изоляция по тест-БД/префиксу).

**Контекст:** выделено из [[e2e-login-helper-magic-link]] (2026-07-11); ретаргетнуто
под poll-модель ([[telegram-login-confirm-in-bot-no-magic-link]], 2026-07-28).

**Status:** todo.
