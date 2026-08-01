### Drift-тест проверяет матрицу из DEV-базы вместо тестовой

**Criticality:** Medium

**TAGS:**
- tech-debt
- test
- ci

**Epic:** [[CNV-47]] — подзадача 10.

**Problem:**
`workers/tests/test_routing_drift.py` вызывает `docker exec … php bin/dump-matrix.php --json`
БЕЗ установки переменной окружения `APP_ENV=test` (строки 184–186, также путь native php
без флага на 160–164). В результате скрипт читает матрицу из DEV-базы (`APP_ENV` по умолчанию),
а не из тестовой БД.

**Impact:**
1. `make test-php-live` падает на шаге drift-проверки при условии: разработчик добавил миграцию
   на своём хосте, но забыл накатить её на dev-базу. Сами PHPUnit-тесты при этом проходят зелёные
   (тестовая БД мигрирована корректно), но агрегат падает с ошибкой уровня SQL («Unknown column
   status» и т.д.) — потому что drift-тест проверяет dev-БД, а не тестовую.

2. Результат `make test-php-live` зависит от состояния личной dev-базы разработчика, а не только
   от окружения тестов. Это «скрытая зависимость» — нарушает изоляцию тестового контура.

3. Когда появится CI-pipeline, у раннера вообще не будет dev-базы — и drift-шаг либо упадёт
   (попытается подключиться к несуществующей базе), либо потребует поднимать дополнительное
   окружение только для этого шага.

**Recommendation:**
Вариант A: drift-тест проверяет тестовую БД (`APP_ENV=test`). Seed-only набор — принят как достаточный.

**Acceptance Criteria:**
- [x] Native php path в `test_routing_drift.py` явно ставит `APP_ENV=test` (как docker-путь уже делает через тест-стенд)
- [x] Seed-only матрица в тестовой БД принята как достаточная для drift-проверки (без требования реальных регистраций воркеров)
- [x] `make TEST=1 test-drift` зелёный без зависимости от личной dev-БД

**Related cards:**
- `[[registry-04-matrix-tooling-tests]]` — там drift-тест был переписан и лежит сейчас
- `[[CNV-26-no-ci-pipeline]]` — CI раннер выявит эту проблему первым

**Decisions:**
- Узкий scope: закрепить `APP_ENV=test` на native php path (docker-путь под `make TEST=1` уже бьёт в тест-стенд).
- Seed-only полнота — принимаем (реальные регистрации воркеров в drift не требуются).
- Понятное сообщение при немigrated БД — вне scope (по желанию при реализации).

**Work notes:**
Groomed 2026-08-01: pin APP_ENV=test on native path; accept seed-only; AC narrowed.

**Update 2026-07-30 (рефакторинг Makefile/env):** вариант **A** реализован —
`test_routing_drift.py::_container_name()` теперь берёт `COMPOSE_PROJECT_NAME` из
**окружения** (Makefile его экспортирует), а не из файла `.env`. Под `make test`
(TEST=1) таргет `test-drift` бьёт в php-контейнер тест-стенда, где `APP_ENV=test`
→ матрица читается из `convertor-test`. Плюс guard `REQUIRE_TEST` не даёт запустить
`test-drift` без тест-окружения. Остаток: pin `APP_ENV=test` на native php path.

**Status:** ready.

**Execution Log:**
- 2026-08-01: todo→progress; pin APP_ENV=test on native php path in test_routing_drift.py::_load_registry().
- 2026-08-01: make TEST=1 test-drift green (5 passed); progress→test→ready.
