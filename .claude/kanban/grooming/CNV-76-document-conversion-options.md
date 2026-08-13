### Настройки конвертации документов

**Criticality:** Medium

**TAGS:**
- feature
- documents
- conversion-options
- grooming

**Description:**
Спроектировать параметры результата для document-конвертаций после внедрения
общего подхода к image options.

**Problem:**
Разные движки документов имеют несовместимые параметры: PDF quality/page range,
DOCX/ODT layout, text encoding и markdown dialect. Универсальная форма без
каталога возможностей будет обещать параметры, которые worker не применяет.

**Impact:**
Пользователь не может контролировать важные свойства документа; неограниченный
набор полей увеличит риск несовместимых и невалидных комбинаций.

**Recommendation:**
Составить target-format capability catalog, выбрать небольшой MVP и определить
контракт/валидацию по образцу image options.

**Acceptance Criteria:**
- Для PDF, офисных, text и markup target formats подготовлена таблица кандидатов:
  параметр, движок, дефолт, лимит и совместимость.
- Выбран MVP параметров и созданы готовые implementation-карточки по зонам.
- Определено, какие параметры shared с image contract, а какие специфичны для
  document worker.

**Open questions:**
- Какие target formats приоритетны: PDF, DOCX/ODT, TXT/Markdown или HTML?
- Нужны ли page range/ориентация PDF в первом релизе и как валидировать их?
- Должны ли настройки храниться тем же localStorage-паттерном по target format?

**Decisions:**
- 2026-08-15: до согласования не добавлять поля в существующую форму конвертера.
