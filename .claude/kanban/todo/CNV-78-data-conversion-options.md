### Применение CSV/JSON settings в data-worker

**Criticality:** Medium

**TAGS:**
- feature
- data
- data-worker

**Description:**
Применить нормализованные CSV и JSON settings в data-worker. Карточка не изменяет profile schema, API validation или frontend controls.

**Problem:**
Если worker не применяет whitelist delimiter/quote/UTF-8 и pretty-print/indent, экспорт меняет структуру или игнорирует выбор пользователя.

**Impact:**
Пользователь получает несовместимые файлы и не может надёжно повторить преобразование данных.

**Recommendation:**
Для CSV применять разрешённые delimiter и quote, строго отклонять невалидный UTF-8 без replacement; для JSON применять pretty-print и ограниченный indent. Не добавлять options для YAML/TOML/XML и не принимать произвольные serializer options.

**Acceptance Criteria:**
- data-worker создаёт CSV только с разрешёнными delimiter/quote и UTF-8; невалидный UTF-8 завершается предсказуемой worker error без замены символов.
- data-worker применяет JSON pretty-print и разрешённый indent.
- Fixture и round-trip worker tests фиксируют сохранение структуры и типов для CSV/JSON.
- YAML/TOML/XML не читают и не получают settings.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Profile и серверная validation реализует CNV-103; эта карточка зависит от CNV-103 и CNV-85.
- UI реализует CNV-105 после profile; общая frontend grammar принадлежит CNV-92.
- Data MVP ограничен CSV delimiter/quote/UTF-8 и JSON pretty-print/indent.
