### Data-worker: применение CSV/JSON settings

**Criticality:** High

**TAGS:**
- feature
- data
- data-worker

**Description:**
Применить normalized CSV/JSON settings из CNV-103 в data-worker.

**Problem:**
Backend validation не сохраняет совместимость export, если serializer worker-а игнорирует delimiter, quote, UTF-8, pretty-print или indent.

**Impact:**
Пользователь получит файл, не соответствующий подтверждённой настройке, либо данные будут молча изменены.

**Recommendation:**
Выполнить worker scope CNV-78: применить CSV whitelist и строгий UTF-8 failure без replacement, JSON pretty-print/indent; не читать options YAML/TOML/XML.

**Acceptance Criteria:**
- CSV output использует normalized delimiter/quote и strict UTF-8 handling.
- JSON output применяет pretty-print и normalized indent.
- Fixture round-trip tests проверяют структуру и типы; invalid UTF-8 даёт безопасную error.
- `pytest`, `make test` и `make build` зелёные.

**Decisions:**
- Зависит от CNV-85 и CNV-103; это исполняющая часть CNV-78.
- Frontend controls принадлежат CNV-105 после profile.
- YAML/TOML/XML вне scope.
