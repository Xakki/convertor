### Document worker settings application

**Criticality:** High

**TAGS:**
- feature
- documents
- document-worker

**Description:**
Применить нормализованные profiles CNV-97 в document-worker; не менять backend contract или frontend.

**Problem:**
Profile validation не гарантирует фактического использования page/layout/serialization options worker-ом.

**Impact:**
Успешная конвертация проигнорирует выбранные параметры и создаст недостоверный результат.

**Recommendation:**
Реализовать правила CNV-76: PDF page range/orientation, TXT/Markdown UTF-8 и whitelisted dialect; не читать options для DOCX/ODT.

**Acceptance Criteria:**
- Worker применяет все normalized fields CNV-97 только к поддерживаемым targets.
- DOCX/ODT сохраняют прежнее поведение без settings.
- Fixture tests проверяют PDF output и TXT/Markdown serialization.
- `pytest`, `make test` и `make build` зелёные.

**Decisions:**
- Зависит от CNV-85 и CNV-97; исполняет worker scope CNV-76.
- Frontend controls принадлежат CNV-99 и начинаются после profile.
- Arbitrary document engine options вне scope.
