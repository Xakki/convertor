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
