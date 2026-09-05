### Generic settings profile renderer and local state

**Criticality:** High

**TAGS:**
- feature
- frontend
- conversion-options

**Description:**
Frontend-часть CNV-85: generic renderer profile-defined controls и local state.

**Problem:**
UI жёстко знает image options и не может безопасно отобразить profiles из API.

**Impact:**
Consequences of not addressing.

**Recommendation:**
Рендерить только controls из versioned `/formats` catalogue; local state ключевать
target format; не вводить domain-specific или raw renderer arguments.

**Acceptance Criteria:**
- Поддержаны range/select/number/text/boolean/color с API boundaries/defaults.
- UI показывает effective default и скрывает недоступные plan/guest fields.
- Есть frontend tests rendering, persistence и invalid API profile handling.

**Decisions:**
- Зависит от backend catalogue CNV-85; заменяет только frontend scope CNV-85.

**Execution log:**
- 2026-09-05 CNV-92 handoff: frontend renderer now consumes versioned `/api/v1/formats` settings profiles, supports range/select/number/text/boolean/color, keys local state by target format plus catalog version, hides non-editable fields and OCR settings, validates malformed profiles fail-closed, and submits only normalized editable options through the existing auth-aware transport. Direct defect repaired: stale plan-inaccessible select values are excluded from submission.
- Current evidence on `task/CNV-92`: `make TEST=1 test-php FILTER=ConversionSettingsFrontendContractTest` → 4 tests, 25 assertions, OK; `make TEST=1 phpstan` → OK; `make TEST=1 cs-check` → 0 fixable files; `make TEST=1 config-check` → OK; Twig-extracted frontend script `node --check` → OK; `git diff --check` → clean; full `kanban-lint.sh --repo /home/xakki/convertor` → 71 cards, 0 errors, 0 warnings.
- Lifecycle boundary: source/test changes are prepared for independent acceptance review; no merge, push, release, or deploy performed.
