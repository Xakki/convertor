### Document settings: frontend controls

**Criticality:** High

**TAGS:**
- feature
- documents
- frontend
- conversion-options

**Description:**
Отобразить document profile controls из catalog и сохранить выбранные values по target format.

**Problem:**
Без domain frontend пользователь не может выбрать доступные document settings или UI покажет поля для неподдерживаемых pairs.

**Impact:**
Конвертация PDF/TXT/Markdown остаётся без управления либо создаёт недопустимый request.

**Recommendation:**
После CNV-92 и CNV-97 рендерить только catalog controls: PDF page range/orientation, TXT/Markdown разрешённый dialect; UTF-8 показывать как фиксированное свойство, а не editable произвольную encoding.

**Acceptance Criteria:**
- UI показывает document controls только для pairs с profile.
- PDF и TXT/Markdown отображают только соответствующие fields и effective defaults.
- DOCX/ODT не получают document controls; state изолирован по target format.
- Frontend tests покрывают rendering, persistence и недоступный profile.

**Decisions:**
- Зависит от CNV-85, CNV-92 и CNV-97; worker scope принадлежит CNV-76/CNV-98.
- API validation остаётся авторитетной.
- Никаких document fields вне утверждённого MVP.

---

**Примечание (CNV-98, воркер, 2026-08-24):** `orientation` правит page setup
промежуточного `.docx` и тем самым **репагинирует** документ ДО того, как
`pageRange` применяется на финальном PDF-экспорте (`txt→pdf`, `md→pdf`).
Значит `pageRange:"1-2"` выбирает страницы уже репагинированного документа
(в новой ориентации), а не той раскладки, которую пользователь видел в
preview с другой ориентацией — один и тот же текст даёт 9 страниц альбомно
против 6 книжно. Поведение осознанное, не баг, но неочевидное — copy у
полей `pageRange`/`orientation` в этой карточке должен явно объяснять эту
связь пользователю (например: «диапазон страниц считается уже после
применения ориентации»).
