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
