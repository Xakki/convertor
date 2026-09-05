### Conversion settings API frontend queue contract tests

**Criticality:** High

**TAGS:**
- tech-debt
- qa
- conversion-options

**Description:**
Cross-layer QA scope CNV-85 без реализации business logic.

**Problem:**
Specific problem(s) this task solves.

**Impact:**
Consequences of not addressing.

**Recommendation:**
Добавить contract/regression tests API/OpenAPI, frontend profiles, validation и
normalized job serialization.

**Acceptance Criteria:**
- Tests покрывают unknown/invalid/inaccessible options, effective defaults и
  deduplicated profile schema.
- Проверены frontend rendering и queue/job normalization без image-only leakage.

**Decisions:**
- Зависит от CNV-85, CNV-92 и CNV-93; заменяет только QA scope CNV-85.

**Execution log:**
- 2026-09-05 CNV-94: добавлен лёгкий PHPUnit contract/regression suite без frontend framework. Проверены production catalog assignments (ссылки на профили, уникальность, явные `ocr`/`animated`), закрытая grammar/access metadata, frontend profile-driven rendering без image-only ключей, versioned target persistence, auth-aware formats loading, OCR suppression и normalized submission seam.
- Verification: `make TEST=1 test-php FILTER='ConversionSettings(ContractQa|FrontendContract|CatalogApi)'` — 68 tests, 1011 assertions OK; backend settings cross-check `make TEST=1 test-php FILTER='Conversion(Settings|Catalog|Options)'` — 277 tests, 2841 assertions OK; `make TEST=1 phpstan` — OK; `make TEST=1 cs-check` — 0 fixable files; `make TEST=1 config-check` — OK; `git diff --check` — clean. Mutation proof: временная подмена auth-aware `/formats` fetch дала ожидаемый красный тест, затем production template восстановлен.
- Scope boundary: runtime/business logic не менялся; browser harness и heavy dependencies не добавлялись. No merge, push, release, or deploy performed.
