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
