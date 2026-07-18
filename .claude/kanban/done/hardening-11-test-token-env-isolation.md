### Детерминировать WORKER_API_TOKEN/GATEWAY_INTERNAL_TOKEN в phpunit.xml (регрессия hardening-02)

**Criticality:** High

**TAGS:**
- bug-fix
- test
- regression

**Description:**
hardening-02 (прокидка `WORKER_API_TOKEN`/`GATEWAY_INTERNAL_TOKEN` в env php/cron
через `x-app-env` anchor) запекла **dev-значения** этих токенов в OS-env
dev-контейнера `xakki-convertor-php`. Symfony Dotenv НЕ перекрывает уже
установленную OS-переменную при загрузке `.env.test`. Поэтому любой прогон
PHPUnit через `docker exec … phpunit` на dev-контейнере (это и есть путь
`make test-php-live` и `make test-php`) резолвит `%env(WORKER_API_TOKEN)%` в
dev-значение, а функциональные тесты шлют `.env.test`-значение
(`test-worker-token`) → mismatch → 401.

**Problem:**
`make test-php-live` exit 2: ~21 failures в `WorkerControllerTest` /
`WorkerRegisterControllerTest` («Failed asserting that 401 is identical to
200/400»). Доказано: тот же тест с форсированными тест-токенами
(`docker exec -e WORKER_API_TOKEN=test-worker-token -e GATEWAY_INTERNAL_TOKEN=test-internal-token`)
→ `OK (18 tests)`; без форса → 11 failures.

**Impact:**
Канонический CI-таргет `test-php-live` (сертифицируется картой hardening-08)
красный; интеграционный гейт эпика не может пройти чисто. Регрессия занесена
самим эпиком → эпик её и чинит (subtask 11).

**Recommendation:**
Форсировать тест-значения в `app-symfony/phpunit.xml(.dist)` через
`<env name="WORKER_API_TOKEN" value="test-worker-token" force="true"/>` и
аналогично `GATEWAY_INTERNAL_TOKEN` — тогда phpunit-процесс детерминирован
независимо от env контейнера, а изолированный стек `test-api-integration`
(там значения уже тестовые) не затрагивается. Не менять сами тесты и не трогать
hardening-02 (для прода прокидка токенов корректна — проблема только в тест-изоляции).

**Acceptance Criteria:**
- В `phpunit.xml(.dist)` оба токена зафиксированы тест-значениями с `force="true"`.
- `make test-php-live` зелёный (0 failures) на dev-контейнере с запечёнными dev-токенами.
- `make test-api-integration` остаётся зелёным (не регрессировал).
- Tests/QA green: `make phpstan`, `make cs-check`.

**Контекст:** вскрыто при верификации hardening-08 (2026-07-18); причинность
подтверждена team-lead'ом форс-прогоном. Однокоренная с fix#2 hardening-08
(дрейф источников env), но в container-startup/env-пути, не в DB-пароле.

**Status:** done.
