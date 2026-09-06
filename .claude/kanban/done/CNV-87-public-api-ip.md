### CNV-87 — Backend: анонимная IP-identity для public API

**Criticality:** High

**TAGS:**
- backend
- security
- api
- privacy
- rate-limit

**Description:**
Backend-разработчик реализует серверный fallback identity для разрешённых public API
операций без cookie и bearer-токена. Приоритет остаётся у JWT, персонального API-токена
и валидного `guest_id`; IP-derived identity применяется только при их отсутствии.

**Problem:**
Public API без браузерной cookie не имеет контрактного владельца для quota, rate-limit
и проверки владения текущей операцией. Доверие клиентскому forwarded header позволило бы
подменить identity.

**Impact:**
Анонимные интеграции либо не работают предсказуемо, либо обходят действующую модель
quota/owner checks; ошибочная обработка IP создаёт риск раскрытия данных за NAT/VPN.

**Recommendation:**
Ввести keyed HMAC-SHA-256 identifier от client IP на доверенной server-side границе.
Принимать `X-Forwarded-For` и `X-Real-IP` только от единственного trusted proxy Nginx;
не сохранять raw IP. Ограничить fallback quota, rate-limit и владением одной текущей
операцией, без list-history и management-доступа.

**Acceptance Criteria:**
- Разрешённые public endpoints без cookie/token получают identity только после проверки
  отсутствия JWT, personal token и валидного `guest_id`.
- IP identity применяет существующие quota, rate-limit и owner checks; AI/video и прочие
  guest/public ограничения не обходятся.
- Nginx определён единственным trusted proxy; spoofed client `X-Forwarded-For` не меняет
  выбранную identity.
- Новая identity не записывает raw IP или HMAC secret в БД, ответы, exception text и логи;
  секрет доступен только через `.env.local`.
- Existing guest-cookie flow, login merge и registered/API-token flow сохраняют поведение;
  операции разных owners изолированы.
- IP-derived identity и связанные anonymous conversion records получают срок хранения 30
  дней и исполнимый backend cleanup-hook для этого срока.
- Профильные backend functional/unit tests зелёные для precedence, same-IP stability,
  distinct-owner isolation и trusted-proxy защиты.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимости:** нет; это первый шаг цепочки `CNV-87 → CNV-83 → CNV-84 → CNV-86`.
- JWT, personal API token и валидный `guest_id` имеют приоритет над IP fallback.
- Анонимному API доступны formats/examples, создание, quota, status и ресурсы только
  собственной текущей операции; history list, retry/delete, payment, profile и management
  остаются user/admin-only.
- Privacy regression, пользовательская документация и проверки отсутствия утечек вне
  реализации identity принадлежат CNV-112.

## Execution log

- **2026-09-05 — CNV-87 implementation:** Added keyed HMAC-SHA-256 anonymous identity derived from Symfony's trusted client IP resolution. JWT, personal bearer, and valid `guest_id` precedence remains unchanged; IP fallback is opaque, stateless, and does not emit a cookie. Added a daily 30-day cleanup hook that deactivates expired guest owners and clears their lookup key.
- **Scoped verification:** `make TEST=1 test-php-unit` — PASS (623 tests, 3925 assertions, 6 existing PHPUnit deprecations). Container lint — PASS. Focused identity tests cover stable same-IP identity, distinct-IP isolation, and untrusted forwarded-header resistance.
- **2026-09-05 — Post-review verification:** `make TEST=1 migrate` applied `Version20260905100000` to isolated `convertor-test`; migration status reports 29/29 executed, current/latest `Version20260905100000`, no new migrations. `GuestAuthenticationTest` PASS (6 tests, 35 assertions); anonymous identity/cleanup/config tests PASS (6 tests, 21 assertions); full unit suite PASS (625 tests, 3938 assertions, 6 PHPUnit deprecations); PHPStan application and migration configs PASS; `cs-check`, test compose `config-check`, and `git diff --check` PASS. Fixed a fail-closed PHPStan defect in `AnonymousIdentityService` (`inet_pton()` false handling) and formatter drift in the CNV-87 files; scoped commit `38ffc94`.
- **2026-09-05 — CNV-87 E2E contract repair:** Replaced the legacy cookie-only `GuestConvertCookieE2eTest` assumptions with isolated coverage for both contracts: no-cookie conversion derives and persists an anonymous-IP owner without emitting `guest_id`; a valid signed `guest_id` reuses the existing cookie owner, even with a different request IP, and does not reset the cookie. Assertions are request/owner scoped rather than total-row based; the test uses a fresh reserved test IP and cleans conversion fixtures, isolated KeyDB stream entries, and users.
- **E2E verification:** `make TEST=1 migrate` — already at `Version20260905100000`; focused `make TEST=1 test-php FILTER='GuestConvertCookieE2eTest'` — PASS (2 tests, 20 assertions); full `make TEST=1 test-php` — PASS (1086 tests, 6391 assertions, 12 PHPUnit deprecations).
- **2026-09-05 — CNV-87 E2E rigor repair:** Replaced probabilistic 254-address selection with deterministic IPv6 documentation-space candidates that derive the owner identity and scan all `guestId` rows before every request, so repeated runs never reuse an existing row. Added a collision regression fixture with exact owned cleanup. No-cookie and valid-cookie flows now assert absence by `Set-Cookie` name, catching empty and clearing (`Max-Age=0`) headers as well as replacement values.
- **E2E re-verification:** focused `make TEST=1 test-php FILTER='GuestConvertCookieE2eTest'` twice — PASS each (3 tests, 27 assertions); full `make TEST=1 test-php` — PASS (1087 tests, 6398 assertions, 12 PHPUnit deprecations); `make TEST=1 cs-check` and `git diff --check` — PASS.
- **Review boundary:** No CNV-83/token lifecycle, audit, OpenAPI, merge, push, release, or deploy work performed.

## Accepted evidence — EPIC-006 wrap-up (2026-09-05)

- **Independent review:** APPROVE. CNV-87 acceptance evidence is complete; the implementation, repair, and test evidence above satisfies the card scope. CNV-87 remains `ready`; do not move to `done` here.
- **Implementation/repair commits:** `2166a41b56700ebae7ebaedb8fd432add463febd` (anonymous IP identity); `559e05e5769119e639b9255273f03fde57519ecd` (proxy and anonymous-identity hardening); `38ffc948bd9dc0f451f79774b589c594fa550e69` (fail-closed `inet_pton()` typing and formatter repair); `f916a363b692a28b896fc750f1ab7c980cf97db2` (guest E2E contract coverage); `dcad06864e78fa2114ac4997425931df2f0b7461` (deterministic collision/isolation and cookie-header rigor).
- **Verification:** focused guest E2E `3 tests, 27 assertions` passed twice; full PHP E2E `1087 tests, 6398 assertions` passed; full PHP unit `625 tests, 3938 assertions` passed; migration at `Version20260905100000` (`29/29`); PHPStan application/migration, `cs-check`, test compose config check, and `git diff --check` passed.
- **Reviewer digest/checksum:** no independent reviewer session digest/checksum is locally available; verdict is recorded as the accepted review result without inventing one.
- **Parked risks/deprecations:** CNV-112 retains independent privacy regression coverage; proxy trust/forwarded-header spoofing, NAT/VPN correlation, and 30-day cleanup remain monitored integration risks. Test runs report existing PHPUnit deprecations only: 6 in unit and 12 in full E2E; no new deprecation is attributed to CNV-87. CNV-83 remains `todo` and is the next sequential child; no CNV-83 work was performed.
