### Document settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- documents
- backend
- conversion-options

**Description:**
Определить document profiles и серверную validation для PDF, TXT и Markdown.

**Problem:**
Без domain profile API не может отличить применимые document fields от несовместимых DOCX/ODT options.

**Impact:**
Невалидные page/layout/encoding values попадут в job либо будут обещаны неподдерживаемым targets.

**Recommendation:**
После CNV-85 назначить точным supported pairs PDF fields `pageRange` и `orientation`, TXT/Markdown — фиксированный UTF-8 и whitelisted Markdown dialect. Не назначать profile DOCX/ODT и не добавлять им fields.

**Acceptance Criteria:**
- Catalog назначает profiles только поддерживаемым PDF, TXT и Markdown pairs.
- Backend валидирует page range, orientation, UTF-8 и Markdown dialect и сериализует normalized options.
- DOCX/ODT и неподдерживаемые pairs отклоняют document settings как settings без profile.
- API tests покрывают valid/invalid values и pair-specific access.

**Decisions:**
- Зависит от CNV-85; CNV-76 и CNV-99 начинаются после profile.
- Document MVP: PDF page range + orientation, TXT/Markdown UTF-8 + dialect.
- UI grammar принадлежит CNV-92, worker application — CNV-76.
