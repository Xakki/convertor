### Static SVG: backend profile и catalog

**Criticality:** High

**TAGS:**
- feature
- images
- svg
- backend
- conversion-options

**Description:**
Добавить в backend catalogue profiles для статичных SVG → GIF/BMP/TIFF/ICO и серверную validation их options.

**Problem:**
Без точного profile API может опубликовать worker target с неподдерживаемыми settings или пропустить невалидные options в job.

**Impact:**
Клиенты увидят неверный contract, а worker получит неоднозначные параметры legacy-output.

**Recommendation:**
После CNV-85 назначить profile точным SVG target pairs: публиковать только static semantics и разрешённые текущим pipeline width/height; нормализовать options в job. Не публиковать FPS, duration, loop, palette/dither или animation controls.

**Acceptance Criteria:**
- `/formats` возвращает SVG → GIF/BMP/TIFF/ICO только с profile, совместимым со статичным image-worker.
- Backend принимает только разрешённые profile fields и нормализует их до job payload.
- Animation-specific keys и options неподдерживаемых pairs отклоняются предсказуемо.
- API/contract tests покрывают catalog, valid и invalid options.

**Decisions:**
- Зависит от CNV-85; worker CNV-75 и frontend CNV-96 начинаются после profile.
- GIF в этой карточке только статичный; animated SVG принадлежит CNV-106/CNV-82.
- Форматы доступны всем пользователям; ограничений плана для них нет.
