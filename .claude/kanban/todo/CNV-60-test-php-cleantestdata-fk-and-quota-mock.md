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
Полный PHP-gate красный; узкие прогоны webhook/unit зелёные.

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
