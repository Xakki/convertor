### Применение document-настроек в document-worker

**Criticality:** Medium

**TAGS:**
- feature
- documents
- document-worker

**Description:**
Применить нормализованные document options в document-worker для PDF, TXT и Markdown. Карточка не изменяет profile schema, API validation или frontend controls.

**Problem:**
Даже валидированные параметры бесполезны, если worker игнорирует page range, orientation и Markdown dialect либо передаёт их неподдерживаемым DOCX/ODT путям.

**Impact:**
Пользователь увидит настройки, но получит документ с дефолтным layout или несовместимой сериализацией.

**Recommendation:**
Применять `pageRange` и `orientation` только при поддерживаемом PDF output; для TXT/Markdown писать UTF-8 и выбранный разрешённый dialect. Для DOCX/ODT options не читать и не добавлять. Использовать только нормализованный job payload.

**Acceptance Criteria:**
- document-worker применяет page range и orientation к поддерживаемому PDF result.
- TXT и Markdown result создаются в UTF-8; выбранный dialect влияет только на Markdown output.
- DOCX/ODT conversion не получает новых options и сохраняет текущую семантику.
- Worker-тесты покрывают каждую поддержанную настройку и отсутствие её эффекта на неподдерживаемых targets.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Profile и серверная validation сначала реализуются в CNV-97; worker зависит от CNV-97 и CNV-85.
- UI реализуется отдельно в CNV-99 после profile; общая frontend grammar принадлежит CNV-92.
- PDF page range + orientation и TXT/Markdown UTF-8 + dialect — единственный document MVP.
