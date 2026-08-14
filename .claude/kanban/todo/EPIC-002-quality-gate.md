### Стабильность тестового стенда и quality gate

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
Стабилизировать изолированный тестовый стенд и сделать полный quality gate воспроизводимым одним агентом.

**Problem:**
PHP-набор тестов падает на устаревших fixture/mock, а `make test-down` не удаляет профильный AI-контейнер и оставляет сеть занятой.

**Impact:**
Полный `make test` останавливается раньше остальных suite, а тестовое окружение загрязняется контейнерами между прогонами.

**Recommendation:**
Сначала исправить красный PHP suite, затем обеспечить очистку всех тестовых Compose-профилей и проверить полный цикл поднятия, прогона и teardown.

**Acceptance Criteria:**
- `make TEST=1 test-php` и полный `make test` зелёные.
- После любого тестового сценария `make test-down` удаляет все контейнеры и сеть `xakki-convertor-test`.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Выполнять до feature-эпиков: чистый quality gate нужен для безопасной проверки последующей работы.

**Subtasks:**
- CNV-60 — исправить CleanTestData FK и mock quota в PHP-тестах
- CNV-72 — очищать AI-профиль вместе с тестовым Compose-стендом

**Integration checklist:**
- Выполнить полный цикл `test-up` → тестовые сценарии → `test-down`.
- Выполнить полный quality gate проекта.
