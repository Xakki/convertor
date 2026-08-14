### Static SVG: frontend catalog controls

**Criticality:** High

**TAGS:**
- feature
- images
- svg
- frontend
- conversion-options

**Description:**
Отобразить в frontend static SVG targets и controls, предоставленные backend catalog.

**Problem:**
Image UI может показывать захардкоженные targets или animation controls, не соответствующие static SVG profile.

**Impact:**
Пользователь не увидит доступный target либо отправит option, который worker не поддерживает.

**Recommendation:**
После CNV-92 и CNV-95 использовать только profile из `/formats`, сохранять local state по target format и передавать effective defaults. При отсутствии profile скрывать controls, не заменяя ответ локальной схемой.

**Acceptance Criteria:**
- UI показывает SVG → GIF/BMP/TIFF/ICO, доступные в персонализированном catalog.
- Рендерятся только controls static profile и их API defaults/boundaries.
- Отсутствующий или недоступный profile не создаёт fallback controls; conversion остаётся без settings.
- Frontend tests покрывают rendering, persistence по target и отсутствие animation controls.

**Decisions:**
- Зависит от CNV-85, CNV-92 и CNV-95; worker implementation принадлежит CNV-75.
- GIF в UI этой карточки маркируется как статичный; animation UI вне scope.
- Local state не является источником validation: API остаётся авторитетом.
