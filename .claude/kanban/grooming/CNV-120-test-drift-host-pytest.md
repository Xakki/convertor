### Перевести test-drift с host pytest в контейнер

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Инфраструктурная доработка тестового контура: выполнять drift-проверки Python
в контейнере, а не через зависимости Python на хосте.

**Problem:**
Таргет `test-drift` в `workers/Makefile:416` запускает
`PYTHONPATH=. pytest ...` непосредственно на хосте. Это существующий дефект,
найденный полным gate EPIC-003; он находится вне scope EPIC-003 и в рамках
эпика не исправлялся. Активного дубликата в `grooming/` и `todo/` не найдено.

**Impact:**
Полный тестовый gate зависит от установленного на хосте pytest и Python-
окружения, поэтому результат менее воспроизводим и нарушает стабильное правило
проекта: Python запускается только в контейнерах.

**Recommendation:**
Перевести `test-drift` на штатный test-compose/container selector, сохранив
текущий набор drift-тестов, рабочую директорию и жёсткую передачу exit code.
Не добавлять host fallback и не ослаблять проверки.

**Acceptance Criteria:**
- `make TEST=1 test-drift` запускает pytest только внутри тестового контейнера;
  host `python`/`pytest` не требуется.
- Таргет сохраняет текущий набор drift-тестов и возвращает ненулевой exit code
  при падении любой проверки.
- Dry-run/контрактный тест подтверждает test selector и отсутствие production
  selector либо host pytest-команды.
- Профильные drift-тесты, Make-контракт, `kanban-lint` и относящиеся к изменению
  проверки проекта проходят.

**Decisions:**
- Реализация намеренно side-filed: это существующий out-of-scope дефект,
  обнаруженный во время полного gate EPIC-003; EPIC-003 его не исправляет.

**Execution Log:**
- 2026-08-23 — evidence: `workers/Makefile:416`; полный `make test` EPIC-003
  раскрыл host-команду `PYTHONPATH=. pytest ...`. Поиск активных карточек не
  нашёл дубликат; live-операции не выполнялись.
