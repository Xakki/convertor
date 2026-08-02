### Telegram poll: clear tg_login_nonce cookie on 403/410 responses

**Criticality:** Nit
**Epic:** [[CNV-52]]

**TAGS:**
- tech-debt
- auth

**Description:**
После CNV-42 poll-path (`GET /api/v1/auth/telegram/poll`) при `403 mismatch` и
`410 expired`/`gone` **не** гасит httpOnly-cookie `tg_login_nonce` — в отличие от
старого callback/failPage, где nonce сбрасывался при финальном отказе. Сейчас
`TelegramLoginController::poll()` вызывает `nonceCookie->clear()` только на
`200 authorized`. TTL cookie 300s смягчает последствия, но устаревший nonce
может оставаться в браузере до истечения.

**Problem:**
Несогласованное поведение: после явного провала poll (истёкший код или
несовпадение nonce) cookie `tg_login_nonce` остаётся и может участвовать в
следующих poll-запросах до TTL.

**Impact:**
Низкий — TTL 300s ограничивает окно; UX/безопасность не ломаются, но повторный
`/start` может наслоиться на старый nonce до истечения.

**Recommendation:**
На ответах `403` и `410` добавить `Set-Cookie` с `nonceCookie->clear()` для
согласованности со success-path и старым failPage.

**Acceptance Criteria:**
- `GET /api/v1/auth/telegram/poll` на `403 mismatch` и `410 expired`/`gone`
  возвращает гашение `tg_login_nonce` (path/secure/sameSite как у create).
- Функциональные тесты покрывают clear на 403/410; success-path без регрессии.
- Tests/QA green: `make phpstan`, `make cs-check`, релевантные PHPUnit для auth.

**Decisions:**
- (2026-08-02) Вариант A (@user): гасить nonce на **403 mismatch** и **410
  expired/gone** — оба терминальных fail-path poll.

**Execution Log:**
- (2026-08-02) Implement: `TelegramLoginController::poll()` — `nonceCookie->clear()`
  на 403 mismatch, 410 expired/unknown и 410 inactive-user; тесты 403/410 + success
  через `assertNonceCookieCleared` (path/sameSite/httpOnly/secure как у фабрики).
- (2026-08-02) QA: `make cs-check` OK; PHPStan OK (retry после OOM 137);
  PHPUnit `TelegramLoginControllerTest` + `TelegramLoginPollMergeTest` — 9 OK.

**Status:** ready
