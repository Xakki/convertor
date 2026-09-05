### Conversion settings contract documentation

**Criticality:** High

**TAGS:**
- tech-debt
- docs
- conversion-options

**Description:**
Документационный scope CNV-85 без изменения runtime-кода.

**Problem:**
Specific problem(s) this task solves.

**Impact:**
Consequences of not addressing.

**Recommendation:**
Создать русский `docs/conversion-settings-contract.md`; обновить queue contract и
связанные cards после стабилизации backend/frontend contract.

**Acceptance Criteria:**
- Документирует vocabulary, profile schema, validation/access invariants, versioning,
  ownership и checklist добавления setting.
- Не описывает raw worker/renderer/FFmpeg arguments как публичный contract.

**Decisions:**
- Зависит от завершённого CNV-85 и CNV-92; заменяет только docs scope CNV-85.

**Execution log:**
- 2026-09-05: На `task/CNV-93` создан русский `docs/conversion-settings-contract.md`;
  `docs/queue-contract.md` дополнен ссылкой и §3.1 о server-side normalized
  `options`, defaults, OCR и запрете raw engine arguments. Runtime-код не изменялся;
  docs-specific test convention в репозитории не обнаружен.
- Verification: catalog/link contract script подтвердил 14 profiles и 16 assignments,
  обязательные types/error codes и относительные ссылки; `make TEST=1 test-php
  FILTER=ConversionSettingsFrontendContractTest` — 7 tests, 43 assertions OK;
  settings catalog/validator/presenter tests — 209 tests, 1830 assertions OK;
  `git diff --check` clean.
