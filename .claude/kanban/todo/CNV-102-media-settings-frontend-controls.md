### Media settings: frontend controls

**Criticality:** High

**TAGS:**
- feature
- audio
- video
- frontend
- conversion-options

**Description:**
Отобразить доступные audio/video presets из персонализированного catalog.

**Problem:**
Без frontend domain scope пользователь не увидит разрешённые presets или сможет выбрать значения, недоступные его plan/target.

**Impact:**
Media conversion остаётся с opaque defaults либо формирует невалидные requests.

**Recommendation:**
После CNV-92 и CNV-100 рендерить только profile controls, показывать effective default и скрывать значения, отфильтрованные API. Для audio-only target показывать только audio preset.

**Acceptance Criteria:**
- UI показывает audio low/medium/high только для audio profile.
- UI показывает разрешённые video resolution/FPS и не показывает недоступные plan values.
- Audio-only target не отображает video controls; state key — target format.
- Frontend tests покрывают plan-filtered catalog, persistence и pair-specific rendering.

**Decisions:**
- Зависит от CNV-85, CNV-92 и CNV-100; worker scope принадлежит CNV-77/CNV-101.
- UI не содержит codec selector, raw FFmpeg args или duration control.
- API повторно валидирует все отправленные values.
