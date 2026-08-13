### Настройки конвертации данных

**Criticality:** Medium

**TAGS:**
- feature
- data
- conversion-options
- grooming

**Description:**
Спроектировать параметры результата csv/json/xml/yaml/toml и будущих data formats.

**Problem:**
У data formats нет единого набора настроек: CSV требует delimiter/encoding/quote,
JSON — pretty-print/indent, XML/YAML/TOML — правила сериализации. Неконсистентные
дефолты могут менять данные или ломать повторную загрузку.

**Impact:**
Пользователь не может управлять совместимостью экспортируемых данных; неправильные
опции способны незаметно изменить типы или структуру.

**Recommendation:**
Выбрать форматы высокого спроса, зафиксировать lossless-семантику и validation
schema по target format перед добавлением интерфейса.

**Acceptance Criteria:**
- Определён MVP target formats и таблица параметров с дефолтами и пределами.
- Для каждого параметра указан риск изменения данных и тесты round-trip/fixture.
- Созданы готовые implementation-карточки для согласованного MVP.

**Open questions:**
- Входят ли в MVP CSV delimiter/encoding/quote и JSON indentation, или только
  presentation-настройки без изменения данных?
- Какие encoding разрешены и как обрабатывать невалидные символы?
- Нужна ли настройка YAML/TOML/XML сразу, или её отложить до появления спроса?

**Decisions:**
- 2026-08-15: не передавать пользователю произвольные serializer options;
  допустимые поля должны быть whitelisted per target format.
