### Параметры data-конвертаций CSV и JSON

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Внедрить безопасные MVP-параметры CSV и JSON во frontend, API и data-worker.

**Problem:**
Пользователь не может управлять совместимостью CSV/JSON-экспорта, а общие serializer options могут незаметно изменить типы или структуру данных.

**Impact:**
Нельзя настроить нужный формат результата, а невалидная кодировка или произвольные параметры могут давать непредсказуемую конвертацию.

**Recommendation:**
Ограничить CSV delimiter/quote/UTF-8 и JSON pretty-print/indent per-target whitelist; отклонять невалидный UTF-8 без замены символов и закрепить контракт round-trip-тестами.

**Acceptance Criteria:**
- Выполнены AC CNV-78; YAML/TOML/XML не получают UI/API options.
- pytest, `make test` и `make build` зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Отдельный fullstack-эпик для data-агента: JSON/CSV contract не смешивается с параметрами других worker-категорий.

**Subtasks:**
- CNV-78 — параметры data-конвертаций CSV и JSON

**Integration checklist:**
- Проверить fixtures и round-trip для CSV/JSON, включая строгую ошибку невалидного UTF-8.
- Выполнить pytest, `make test` и `make build`.
