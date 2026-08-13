### Настройки конвертации документов

**Criticality:** Medium

**TAGS:**
- feature
- documents
- conversion-options
- grooming

**Description:**
Добавить согласованный MVP параметров document-конвертаций по образцу image
options, не обещая настройки, которые document-worker не применяет.

**Problem:**
Разные движки документов имеют несовместимые параметры: PDF quality/page range,
DOCX/ODT layout, text encoding и markdown dialect. Универсальная форма без
каталога возможностей будет обещать параметры, которые worker не применяет.

**Impact:**
Пользователь не может контролировать важные свойства документа; неограниченный
набор полей увеличит риск несовместимых и невалидных комбинаций.

**Recommendation:**
Реализовать PDF page range и orientation; для TXT/Markdown — UTF-8 и выбранный
dialect Markdown. Сохранять настройки в localStorage по target format. DOCX/ODT
в MVP остаются без пользовательских параметров.

**Acceptance Criteria:**
- API и UI принимают и валидируют PDF page range и orientation; worker применяет
  их для поддерживаемых PDF-результатов.
- Для TXT/Markdown контракт допускает только UTF-8 и whitelisted Markdown dialect.
- Настройки изолированы в localStorage по target format; DOCX/ODT не получают
  новых полей.
- Есть API, worker и UI-тесты для валидных/невалидных значений.
- Тесты/QA green: pytest; make test; make build.

**Decisions:**
- 2026-08-15: до согласования не добавлять поля в существующую форму конвертера.
- 2026-08-14: MVP — PDF page range + orientation, TXT/Markdown UTF-8 + dialect,
  localStorage по target format; DOCX/ODT без настроек.
