### Data settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- data
- backend
- conversion-options

**Description:**
Определить data profiles и серверную validation для CSV и JSON settings.

**Problem:**
Без strict per-target schema API может принять произвольные serializer options или передать CSV field в JSON job.

**Impact:**
Данные могут быть изменены или сериализованы несовместимо без предсказуемой ошибки.

**Recommendation:**
После CNV-85 публиковать CSV delimiter/quote и фиксированный UTF-8; JSON pretty-print и ограниченный indent. Отклонять невалидный UTF-8 строго; YAML/TOML/XML profiles не создавать.

**Acceptance Criteria:**
- Catalog назначает отдельные CSV и JSON profiles только поддерживаемым pairs.
- Backend валидирует whitelist delimiter/quote, UTF-8, pretty-print и indent; normalizes job options.
- Невалидный UTF-8, cross-target key и arbitrary serializer option отклоняются предсказуемо.
- API tests покрывают valid/invalid values и отсутствие profiles YAML/TOML/XML.

**Decisions:**
- Зависит от CNV-85; CNV-78/CNV-104 и CNV-105 начинаются после profile.
- YAML/TOML/XML отложены до подтверждённого спроса.
- Worker application принадлежит CNV-78/CNV-104.
