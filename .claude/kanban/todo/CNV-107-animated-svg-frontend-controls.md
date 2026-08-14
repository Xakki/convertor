### Анимированный SVG: frontend controls

**Criticality:** High

**TAGS:**
- feature
- svg
- animation
- frontend
- conversion-options

**Description:**
Отобразить frontend controls profile анимированного локального SVG → GIF без UI для сети, файлов или recording.

**Problem:**
Без выделенного UI пользователь не может выбрать доступные animation values, а generic form может показать поля, которых нет в browser profile.

**Impact:**
Animated SVG feature будет недоступна либо сформирует request с unsafe/unsupported options.

**Recommendation:**
После CNV-92 и CNV-106 рендерить только catalog fields width/height, доступный FPS, loop и background; показывать effective defaults и profile-filtered values. Local state ключевать target format, не добавлять URL input и не вычислять duration на клиенте.

**Acceptance Criteria:**
- UI отображает controls только для доступного animated SVG → GIF browser profile.
- Guest видит fixed default без editable settings; free/basic/pro видят только значения, разрешённые catalog.
- UI не содержит URL input, file-subresource control, recording, palette/dither или raw renderer settings.
- Frontend tests покрывают plan-filtered rendering, persistence и отсутствие profile fallback.

**Decisions:**
- Зависит от CNV-85, CNV-88, CNV-92 и CNV-106; browser execution принадлежит CNV-82.
- Feature остаётся offline и не расширяет web capture scope.
- API остаётся авторитетом для caps и повторной validation.
