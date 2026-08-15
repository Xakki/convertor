### test-php suite red: CleanTestData FK + ConversionTextInput Quota mock

**Criticality:** Medium

**TAGS:**
- bug
- tests

**Description:**
`make TEST=1 test-php` падает на 3 кейсах, не связанных с CNV-58 webhook-тестами.

**Problem:**
1. `CleanTestDataCommandTest::testForceWipesTransactionalDataPreservesAdminAndConfig` —
   FK `balance_transactions.user_id` → `users.id`: команда чистит users, не удалив
   ledger (после CNV-28).
2. `ConversionTextInputControllerTest` (2 теста) — stub `QuotaService::check()` без
   return; PHPUnit 13 не может сгенерировать return для enum `BillingMode` → 500.

**Impact:**
На момент обнаружения полный PHP-gate был RED; узкие прогоны webhook/unit были GREEN.

**Recommendation:**
- CleanTestData: удалять `balance_transactions` до users (или CASCADE).
- ConversionTextInput: `willReturn(BillingMode::…)` на mock `check()`.

**Acceptance Criteria:**
- `make TEST=1 test-php` зелёный без этих ошибок/фейлов.

**Decisions:**
- (2026-08-02) Найдено при прогоне suite после CNV-58 test-engineer; вне scope CNV-58.
- (2026-08-05) Из-за этих фейлов `test-php` падает первым в цепочке `make test`
  (`test-up → test-php test-python test-drift`), и Make останавливается — CNV-71
  дрейф-гарды (`workers/tests/test_catalog_drift.py`, `test_routing_drift.py`)
  в обычном `make test` не выполняются. Поднимает приоритет карточки.
- (2026-08-06) CNV-71-03: `test-gateway` (214 тестов WS-gateway, включая expiry-sweep)
  добавлен в цепочку `test` (`test-up → test-php test-python test-gateway test-drift`)
  — теперь он тоже глушится тем же обрывом на `test-php`, наравне с `test-drift`.
  Ставки ещё выше: без явного `make TEST=1 test-gateway` вся gateway-сюита не
  выполняется вообще. Явный прогон подтверждает: `test-python`, `test-gateway`
  (214 passed, 1 skipped), `test-drift` (22 passed) — зелёные сами по себе.

**Execution Log:**
- (2026-08-15) Карточка перемещена `todo → progress`. Fresh Luna RED:
  `docker exec xakki-convertor-test-php php vendor/bin/phpunit --filter="CleanTestDataCommandTest"`
  — exit 2; 2 tests, 10 assertions, 1 error.
- `CleanTestDataCommandTest::testForceWipesTransactionalDataPreservesAdminAndConfig`
  завершился `Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException`:
  `SQLSTATE[23000]` / `1451 Cannot delete or update a parent row`; FK
  `balance_transactions.user_id` (`FK_BALANCE_TRANSACTIONS_USER`) ссылается на
  `users.id`. Падение в `CleanTestDataCommand.php:136`: users удалялись до ledger.
- Scope: добавлено child-first удаление `balance_transactions` до users и согласованные
  счётчики плана/результата; fixture создаёт ledger-запись и проверяет dry-run/force-wipe.
- (2026-08-15) Focused RED: `docker exec xakki-convertor-test-php php
  vendor/bin/phpunit --filter="CleanTestDataCommandTest"` — exit 2, нарушение FK.
- Focused GREEN: exit 0, 2 теста, 22 assertions.
- Полная PHP acceptance: exit 0, 727 тестов, 3221 assertions, 12 PHPUnit
  deprecations, без failures/errors.
- Independent implementation-source review: no defects found.
- CNV-72 разблокирована, но не начата. Неблокирующие риски: удаление S3 остаётся вне DB-транзакции; регрессионный тест
  не проверяет отображаемые счётчики ledger.
- (2026-08-15) Historical parent PHP-gate RED on a clean canonical test-up (later corrected): `make TEST=1 test-php` — exit 2; 727 tests, 3212 assertions, 12 unchanged deprecations; ровно три failure, все ожидают 429/403 для `mp3 → txt`, но получают 503 `worker_unavailable`.
- Confirmed root cause: на чистом стеке нет строки `worker_capabilities` для типа `ai`; устаревший `worker-ai` ранее маскировал несамодостаточность тестов. Поэтому availability-gate корректно возвращает 503 до intended auth/quota assertions.
- Test-only scope: в `ConversionQuotaEnforcementTest` и `GuestAuthenticationTest` непосредственно для затронутых `mp3 → txt` запросов создаётся ровно одна уникальная capability типа `ai` через `WorkerCapabilityRepository::upsert()`; `tearDown()` удаляет только созданную данным тестом строку. Production availability-before-auth-before-quota порядок и существующее покрытие `worker_unavailable` не изменяются.
- (2026-08-15) First intended three-test lifecycle GREEN attempt ended exit 2:
  `ConversionQuotaEnforcementTest.php:220` and `GuestAuthenticationTest.php:169` threw
  `LogicException: Kernel booted before WebTestCase::createClient`; the third test passed.
  In both failing paths the AI-capability fixture called `static::getContainer()` and booted the
  kernel before the assertion helper created the WebTestCase client.
- Lifecycle correction (test-only): each affected test creates its `WebTestCase` client before the
  AI fixture and passes that client to the assertion helper; the helper no longer creates a client
  after kernel boot. Test-owned AI capability setup and targeted `tearDown()` cleanup of the created
  fixture were preserved.
- (2026-08-15) Second focused GREEN via Makefile:
  `make TEST=1 test-php FILTER='(ConversionQuotaEnforcementTest::testFreeUserAiConversionReturns429InsufficientBalance|ConversionQuotaEnforcementTest::testGuestAiStillReturns403AuthRequiredNot429|GuestAuthenticationTest::testGuestAiConversionReturns403AuthRequired)'`
  — exit 0; 3 tests, 15 assertions, no errors.
- (2026-08-15) Full PHP gate GREEN: `make TEST=1 test-php` — exit 0; 727 tests, 3221 assertions,
  12 unchanged PHPUnit deprecations, no failures/errors.
- Focused Terra re-review remains pending after this documentation correction.
- (2026-08-15) Исправленная фиксация lifecycle: parent RED на чистом стеке выявил
  3 несамодостаточных AI auth/quota-теста, ранее замаскированных stale `worker-ai`;
  focused RED — 3/3 вернули 503. Добавлены test-only уникальные fixtures
  `WorkerCapability`, а lifecycle исправлен созданием клиента до fixture.
- Focused GREEN: 3 теста/15 assertions; полный PHP GREEN: 727 тестов/3221
  assertions, 12 неизменённых deprecations. Первый независимый re-review — FAIL
  только по документации; исправлено. Focused re-review — PASS. CNV-60 снова ready.
- (2026-08-15) Пользователь одобрил финализацию CNV-60 и перенос в `done` в
  составе локального squash merge EPIC-002 без push.

**Status:** done
