### Восстановить dump-matrix + переписать drift-тест на register-round-trip

**Criticality:** High

**TAGS:**
- tech-debt
- test

**Description:**
Третий шаг Phase 2 эпика `[[registry-00-self-registration]]`, идёт ДО удаления хардкода
(`[[registry-05-drop-hardcode]]`), чтобы тестовая обвязка не покраснела на следующем шаге.
Закрывает молчаливую регрессию, найденную при груминге: инструмент `app-symfony/bin/dump-matrix.php`
был случайно удалён 2026-07-10 коммитом `2105d70` (не связанный с реестром коммит про auth), из-за
чего `workers/tests/test_routing_drift.py` (загрузчик PHP-стороны `_load_php_matrix()` L75-96)
последние ~12 дней **тихо скипается** (`pytest.skip` L92-95) вместо падения — drift между PHP и
Python-матрицами всё это время никем не проверялся.

**Problem:**
- `bin/dump-matrix.php` не существует → `test_all_routing_keys_have_worker` (L173-194) и
  `test_worker_matrix_subset_of_registry` (L201-247) не выполняются, а помечаются skip — CI
  зелёный без реальной проверки.
- `ConversionRegistryGoldenTest::__construct()` вызывает `new ConversionRegistry()` без аргументов
  (L31) → `repository=null` → тест ВСЕГДА идёт по хардкод-пути, даже после того как БД станет
  источником истины — golden-фикстура перестанет отражать реальное поведение.
- Docblock-инструкция регенерации golden-фикстуры (L21-23) ссылается на удалённый
  `bin/dump-matrix.php --write`.

**Impact:**
Пока эта карточка не закрыта, следующий шаг ([[registry-05-drop-hardcode]]) либо ломает тесты,
либо (хуже) тесты остаются молча зелёными и не ловят реальный дрейф PHP/Python матриц —
повторение уже случившейся 12-дневной слепой зоны.

**Recommendation:**
- (a) Воскресить `app-symfony/bin/dump-matrix.php`: теперь он должен строить матрицу через
  контейнер/DI, читая `ConversionRegistry` из DB-репозитория (после `[[registry-03-seed-migration]]`
  БД не пуста), а не через `new ConversionRegistry()` без аргументов.
- (b) `workers/tests/test_routing_drift.py` — переписать с «PHP-хардкод vs Python-CAPABILITIES»
  на **register-round-trip**: то, что реально осело в `worker_capabilities` после регистрации
  (объединение задекларированных Python-`CAPABILITIES` всех воркеров) == то, что отдаёт
  `dump-matrix.php`. Убрать skip-на-отсутствие-инструмента — отсутствие `dump-matrix.php`
  должно ПАДАТЬ тест, не пропускать его.
- (c) `ConversionRegistryGoldenTest` — перевести на seeded DB (использовать фикстуру/сид из
  `[[registry-03-seed-migration]]` вместо `new ConversionRegistry()` без аргументов) или
  регенерировать `tests/Fixtures/conversion_matrix.golden.txt` под DB-путь; обновить
  docblock-инструкцию регенерации на актуальную команду.

**Acceptance Criteria:**
- `app-symfony/bin/dump-matrix.php` существует, исполняется, строит матрицу из DB-репозитория.
- `test_routing_drift.py` реально выполняется (не skip) в CI и падает при намеренно внесённом
  расхождении (проверить локально перед сдачей).
- `ConversionRegistryGoldenTest` использует seeded DB, а не хардкод-конструктор без аргументов;
  докблок с инструкцией регенерации актуален.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest (`workers/tests/test_routing_drift.py`).

**Decisions:**
- Груминг 2026-07-22: карточка сознательно поставлена ДО [[registry-05-drop-hardcode]] — правило
  «не удалять источник данных, пока проверяющая его обвязка не переведена на новый источник».

**Зависит от:** `[[registry-03-seed-migration]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress
