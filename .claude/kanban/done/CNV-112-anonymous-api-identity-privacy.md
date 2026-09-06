### CNV-112 — Privacy/security: регрессии и документация anonymous API identity

**Criticality:** High

**TAGS:**
- security
- privacy
- api
- testing
- documentation

**Description:**
Privacy/security-специалист добавляет независимые regression tests и пользовательскую
policy-документацию для anonymous IP-derived identity, реализованной CNV-87. Карточка
не меняет алгоритм identity, proxy configuration или backend ownership code.

**Problem:**
IP-derived identifier допускает корреляцию активности и особенно чувствителен к утечке
raw IP, подмене forwarded headers и раскрытию history пользователей за общим NAT/VPN.

**Impact:**
Изменение identity может незаметно ослабить privacy boundary или создать ложные обещания
в публичной документации об anonymous access и retention.

**Recommendation:**
Зафиксировать privacy contract в документации и независимом regression suite: identity
только на trusted-proxy boundary, raw IP/secret нигде не раскрываются, anonymous history
не выдаётся без cookie/token, anonymous identity очищается по `createdAt` после 30 дней.

**Acceptance Criteria:**
- Regression tests подтверждают JWT/token/valid `guest_id` precedence, no-cookie access,
  same-IP stability, distinct-owner isolation и защиту от spoofed forwarded headers.
- Tests доказывают отсутствие raw IP и HMAC secret в persistence, API responses, logs и
  documentation fixtures.
- Tests подтверждают, что anonymous клиент получает ресурсы только текущей собственной
  операции и не получает list-history за shared NAT/VPN IP.
- Tests подтверждают 30-day `createdAt` cleanup lifecycle только для IP-derived identity;
  conversion records и guest files остаются ответственностью отдельного retention-сервиса.
- Пользовательская privacy/API-документация описывает trusted proxy boundary, 30-day
  retention, ограниченный anonymous scope и отсутствие общего history; не раскрывает
  secret или внутренние сетевые детали.
- Профильные security/privacy tests и документационный check зелёные.

**Decisions:**
- **Владелец:** privacy/security-специалист.
- **Зависимость:** CNV-87 реализует identity и cleanup-hook.
- Это независимая regression/documentation задача; она не изменяет CNV-87 backend
  algorithm, CNV-83 tokens, CNV-84 audit contract или CNV-86 OpenAPI areas.
- Секрет HMAC хранится только в `.env.local`; raw IP не является продуктовым metadata.

## Execution log

- **2026-09-05 — CNV-112 implementation:** добавлены независимый privacy regression
  suite `AnonymousApiPrivacyRegressionTest` и пользовательская документация
  `docs/api-privacy.md` с container-side fixture. Тесты фиксируют opaque identity
  без исходного адреса/ключа, защиту от spoofed forwarded headers, приоритет JWT и
  personal bearer над anonymous fallback, приоритет валидной `guest_id`, запрет
  anonymous history без cookie и документированный 30-day retention/ограниченный
  scope. Алгоритм CNV-87, proxy config и ownership code не изменялись.
- **Проверки:** `make TEST=1 test-php-unit
  FILTER='AnonymousApiPrivacyRegressionTest|AnonymousIdentityServiceTest|AnonymousIdentityCleanupServiceTest|GuestAuthenticationTest|GuestConvertCookieE2eTest'`
  — PASS (657 unit tests, 4031 assertions; только существующие 6 deprecations и
  13 notices); `make TEST=1 phpstan` — PASS для application и migrations;
  `make TEST=1 cs`/`cs-check` — PASS; `make TEST=1 config-check` — PASS;
  `git diff --check` — PASS.
- Связанный worker-dependent `make TEST=1 test-php
  FILTER='GuestAuthenticationTest|GuestConvertCookieE2eTest'` запущен отдельно:
  6 тестов прошли, 3 сценария получили 503 вместо ожидаемого результата из-за
  недоступного worker service. Это существующее ограничение функциональной среды,
  не дефект CNV-112 и не заявляется как зелёный gate.

- **2026-09-05 — CNV-112 boundary test hardening:** заменён manual-array leakage
  check на реальный kernel `/api/v1/quota` (200 + валидный JSON), реальный
  `GuestAuthenticator` из test container, Doctrine flush/clear/repository reload
  и прямой parameterized DB query `users`. Проверяются raw test IP и test HMAC
  input/secret в body/headers и stored schema values; opaque `guest_id` остаётся
  только persistence identity и не попадает в quota response. При проверке
  runtime выяснено, что проект не использует Monolog и не имеет `monolog.handler`:
  Symfony FrameworkBundle предоставляет настроенный `Symfony\\Component\\HttpKernel\\Log\\Logger`
  со stderr output. На seeded-plan quota path `QuotaService` не вызывает logger;
  единственный warning path — missing plan row, который в тесте не срабатывает.
  Поэтому test не подменяет logger, не создаёт fabricated handler/context и явно
  фиксирует этот boundary fact. Требование Monolog-handler evidence остаётся
  blocked by the repository's actual logging configuration; production logging/config
  не менялся.
- **Проверки boundary repair:** focused `make TEST=1 test-php
  FILTER='AnonymousPrivacyBoundaryTest'` — PASS (3 tests, 67 assertions);
  `make TEST=1 phpstan` — PASS (application and migrations); `make TEST=1
  config-check` — PASS; `git diff --check` — PASS. `make TEST=1 cs-check` после
  import/order correction — PASS.
- **2026-09-06 — CNV-112 kernel-injected DEBUG audit evidence repair:** replaced the
  detached `new GuestAuthenticator(mock logger)` assertion with a test-only
  `CaptureLogger` service wired into the real `GuestAuthenticator` service through
  `config/services_test.yaml`. The same real kernel `/api/v1/quota` request now
  captures exactly one DEBUG record for anonymous fallback, with only the fixed
  message and allowlisted context `auth_identity_type=anonymous_ip` and
  `identity_opaque=true`. The same injected logger seam proves no such record for
  JWT, personal bearer, valid `guest_id`, or malformed bearer requests; the
  logger-failure containment test now also exercises the real kernel request and
  still returns a successful guest quota response. Production logging and
  authenticator behavior were not changed.
- **Проверки CNV-112:** focused `AnonymousPrivacyBoundaryTest` — PASS (5 tests,
  80 assertions); `git diff --check` — PASS. The project `make` target could not
  run because it references a missing Docker container named `php`; the equivalent
  real container command `docker exec xakki-convertor-php php vendor/bin/phpunit
  --filter='AnonymousPrivacyBoundaryTest'` passed after test-cache refresh.

- **2026-09-06 — CNV-112 final logging privacy acceptance: APPROVE.** Финальное
  решение принято после review commit chain `aafe542 → 1bd2982 → a5f760f`
  (`a5f760f` — `test: isolate CNV112 capture logger state`). Реальный kernel
  `CaptureLogger`, инжектированный в реальный `GuestAuthenticator` через
  `config/services_test.yaml`, подтвердил ровно одну DEBUG-запись только для
  anonymous IP fallback. Разрешены ровно два safe context fields:
  `auth_identity_type=anonymous_ip` и `identity_opaque=true`; message и level
  также проверяются exact-match тестом.
- Ветка логирования не срабатывает для JWT, personal bearer, валидного
  `guest_id` и malformed bearer (включая fail-closed 401); эти anonymous-only
  exclusion paths проверены тем же kernel-injected logger. Проверены отсутствие
  raw IP и HMAC secret в persistence, API response, headers/body, logger records
  и documentation fixtures. Ошибка logger containment доказана реальным
  `/api/v1/quota`: logger failure не меняет успешную anonymous authentication и
  guest quota response. `CaptureLogger` сбрасывается в `tearDown`; отдельный
  state-isolation test подтверждает, что failure mode не протекает в следующий
  тест.
- **Гейты:** focused `AnonymousPrivacyBoundaryTest` — PASS (5 tests, 80
  assertions); эквивалентная реальная container-команда PHPUnit — PASS после
  refresh test cache; `git diff --check` — PASS. Канонический `make`-target не
  запустился из-за отсутствующего Docker container `php`, поэтому этот caveat
  не объявляется зелёным gate. Production logging/config, identity algorithm,
  proxy configuration и ownership code не изменялись; credentials, tokens,
  raw IP, headers, bodies и иные secrets в evidence не записаны.
- Post-deploy остаётся отдельным будущим требованием: на развернутом стеке
  подтвердить безопасный путь `Fluent → Graylog` (доставка, redaction и
  отсутствие raw IP/secret), без подмены этой проверки локальным
  `CaptureLogger`-evidence.
- **Lifecycle boundary:** CNV-112 сохраняет статус `ready/`; successor
  `CNV-111` сохраняет статус `todo/`. Acceptance не переводит карточку в
  `done/`; merge, push, release и deploy не выполнялись и не входят в эту
  запись.