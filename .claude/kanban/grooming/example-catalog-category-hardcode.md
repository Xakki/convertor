### ExampleCatalog хардкодит category-строки, дублирующие enum FileCategory

**Criticality:** Trivial

**TAGS:**
- tech-debt

**Description:**
`app-symfony/src/Service/Examples/ExampleCatalog.php` оперирует «сырыми» строками категорий,
которые совпадают со значениями enum `App\Enum\FileCategory` (`document`, `image`, `audio`,
`video`, `data`, `markup`), но типом с ним не связаны:

- `all()` (строки 27-34) — `new ExampleDefinition('document', …)`, `('markup', …)` и т.д.: первый
  аргумент-`category` передаётся строковым литералом.
- `requiredCategories()` (строка 45) — `return ['document', 'data', 'image', 'audio', 'video'];`
  (подмножество обязательных категорий AC home-04; `markup` — бонус, `ai` намеренно нет — примера
  AI-конвертации в наборе не предусмотрено).

Найдено при ревью задачи `[[worker-type-lists-hardcode]]` (grep литерала типов). Это НЕ канон типов
воркеров (`WorkerType`) — другой домен (UI-категории примеров лендинга), поэтому в скоуп той
задачи сознательно не вошло. Но `category`-строки здесь — это значения `FileCategory`, и сейчас
они не типизированы против enum.

**Problem:**
Опечатка в литерале category (`'documnet'`) или переименование значения в `FileCategory` не будут
пойманы компилятором/PHPStan — `ExampleCatalog` продолжит компилироваться с «мёртвой» категорией,
не совпадающей ни с одним `FileCategory`. Набор мал и фиксирован, поэтому риск низкий (Trivial),
но типобезопасности на границе нет.

**Impact:**
Тихий рассинхрон: значение в `FileCategory` переименовали/убрали → пример на лендинге ссылается на
несуществующую категорию (объект не сгенерится seed-командой или витрина его отфильтрует), без
явной ошибки на этапе сборки.

**Open questions:**
- Стоит ли вообще трогать? Набор намеренно небольшой и фиксированный (докблок класса), домен —
  курируемая витрина, не листинг. Может, типобезопасность тут не окупает изменения (Trivial).
- Если типизировать: менять ли поле `ExampleDefinition::category` со `string` на `FileCategory`,
  или достаточно валидации литералов в тесте (тест сверяет, что все `category` из `all()` и
  `requiredCategories()` — валидные `FileCategory::tryFrom`)? Нужно проверить всех потребителей
  `ExampleDefinition::category` (эндпоинты `find()`/`findBySource()`, seed-команда, шаблоны
  витрины) — ждут ли они строку.
- Правильно ли, что `requiredCategories()` — подмножество, а не производное? Оно осознанно уже,
  чем `FileCategory::cases()` (нет `markup`/`archive`), — это бизнес-решение AC home-04, а не
  дрейф. Значит канон здесь НЕ «все значения enum», и слепое `FileCategory::cases()` было бы
  неверно. Чем связываем: явным подмножеством enum-кейсов?

**Recommendation:**
Решить open questions при груминге. Вероятный минимальный вариант (если решим трогать): тип
`ExampleDefinition::category` → `FileCategory` (или enum-значения в конструкторе), `requiredCategories()`
возвращает явное подмножество `FileCategory`-кейсов; проверить потребителей. Либо явно закрыть как
«осознанный курируемый список, типизация не окупается» с однострочным комментарием-ссылкой на
`FileCategory`.

**Reference:** `[[worker-type-lists-hardcode]]`, enum `App\Enum\FileCategory`
