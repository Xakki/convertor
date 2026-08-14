### Data settings: frontend controls

**Criticality:** High

**TAGS:**
- feature
- data
- frontend
- conversion-options

**Description:**
Отобразить CSV/JSON controls из data profiles и сохранить values по target format.

**Problem:**
Frontend без profile-aware controls может показать serializer fields неподдерживаемому target или послать произвольные values.

**Impact:**
Пользователь не контролирует совместимость export либо получает предсказуемо отклонённый request.

**Recommendation:**
После CNV-92 и CNV-103 рендерить CSV delimiter/quote и JSON pretty-print/indent только для соответствующих pairs; фиксированный UTF-8 отображать как свойство. Не создавать controls YAML/TOML/XML.

**Acceptance Criteria:**
- CSV и JSON показывают только свои profile fields и effective defaults.
- UTF-8 не редактируется произвольным encoding input.
- YAML/TOML/XML не получают data controls; state изолирован по target format.
- Frontend tests покрывают pair-specific rendering, persistence и отсутствующий profile.

**Decisions:**
- Зависит от CNV-85, CNV-92 и CNV-103; worker scope принадлежит CNV-78/CNV-104.
- UI не является источником validation.
- Arbitrary serializer options вне scope.
