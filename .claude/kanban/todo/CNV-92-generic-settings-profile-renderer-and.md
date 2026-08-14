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
