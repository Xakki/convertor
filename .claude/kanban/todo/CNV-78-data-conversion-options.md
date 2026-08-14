### Настройки конвертации данных

**Criticality:** Medium

**TAGS:**
- feature
- data
- conversion-options
- grooming

**Description:**
Добавить безопасный MVP параметров data-конвертаций для CSV и JSON.

**Problem:**
У data formats нет единого набора настроек: CSV требует delimiter/encoding/quote,
JSON — pretty-print/indent, XML/YAML/TOML — правила сериализации. Неконсистентные
дефолты могут менять данные или ломать повторную загрузку.

**Impact:**
Пользователь не может управлять совместимостью экспортируемых данных; неправильные
опции способны незаметно изменить типы или структуру.

**Recommendation:**
Реализовать для CSV delimiter, quote и UTF-8; для JSON — pretty-print и indent.
При невалидном UTF-8 возвращать строгую ошибку. YAML/TOML/XML отложить до
подтверждённого спроса; все опции валидировать per target format.

**Acceptance Criteria:**
- CSV принимает whitelisted delimiter/quote и только UTF-8; невалидный UTF-8
  завершает конвертацию предсказуемой ошибкой без замены символов.
- JSON поддерживает pretty-print и ограниченный indent; значения проходят
  серверную валидацию.
- Для CSV/JSON есть fixture и round-trip-тесты, фиксирующие риск изменения
  структуры и типов; YAML/TOML/XML не получают UI/API options.
- Тесты/QA green: pytest; make test; make build.

**Decisions:**
- 2026-08-14: CNV-85 — обязательный prerequisite: общий каталог profiles,
  персонализированный `/formats` и общая грамматика controls реализуются до
  domain schema и data-worker application в этой карточке.
- 2026-08-15: не передавать пользователю произвольные serializer options;
  допустимые поля должны быть whitelisted per target format.
- 2026-08-14: MVP — CSV delimiter/quote/UTF-8, JSON pretty-print/indent;
  невалидный UTF-8 отклоняется; YAML/TOML/XML отложены.
