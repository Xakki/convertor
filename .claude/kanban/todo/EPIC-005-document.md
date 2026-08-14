### Параметры document-конвертаций

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Внедрить whitelisted MVP-настройки document-конвертаций во frontend, API и document-worker.

**Problem:**
Пользователь не может управлять page range/orientation PDF и параметрами TXT/Markdown; универсальная форма может обещать настройки, которые worker не применяет.

**Impact:**
Пользователь не получает необходимые настройки документа, а несовместимые параметры движков могут приводить к непредсказуемому результату.

**Recommendation:**
Реализовать согласованный контракт PDF/TXT/Markdown: server-side validation → document-worker → UI/localStorage → тесты; DOCX/ODT в MVP не расширять.

**Acceptance Criteria:**
- Выполнены AC CNV-76 во frontend, API и document-worker.
- pytest, `make test` и `make build` зелёные после интеграции.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Отдельный fullstack-эпик для document-агента: его контракт не зависит от audio/video и data options.

**Subtasks:**
- CNV-76 — параметры document-конвертаций

**Integration checklist:**
- Проверить изоляцию параметров по target format и отказ для невалидных значений.
- Выполнить pytest, `make test` и `make build`.
