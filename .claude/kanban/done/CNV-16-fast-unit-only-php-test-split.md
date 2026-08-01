### Нужен ли быстрый unit-only PHP тест-таргет (без live-DB)?

**Criticality:** Minor

**TAGS:**
- test
- tech-debt

**Epic:** [[CNV-47]] — подзадача 7.

**Description:**
При реализации hardening-08 (fix#1 — задокументировать `test-php-live` как
канонический CI-таргет) вскрылось, что премиса карты неверна: **нет** быстрого
unit-only PHP-разреза. `make test` и `make test-php-live` гоняют ОДИН И ТОТ ЖЕ
полный PHPUnit-сьют (245 тестов, идентичная `docker exec … phpunit` команда).
Когда тест-БД не поднята, большинство Functional/Admin-тестов НЕ скипаются молча,
а падают громко (54–56 errors: Doctrine «Access denied»/missing table); лишь
0–2 теста имеют `markTestSkipped`-гард.

**Problem:**
Разработчику негде быстро прогнать только unit-тесты (без поднятия БД/стека) —
любой PHP-прогон требует провизии тест-БД, иначе громкий красный. hardening-08
задокументировал реальное поведение как есть, но вопрос «а хотим ли мы быстрый
unit-only split» остаётся открытым.

**Impact:**
Без быстрого разреза локальный TDD-цикл дороже (каждый прогон = стек+БД).
Не блокер, качество/DX.

**Контекст:** вскрыто при hardening-08 (2026-07-18). Реальное поведение уже
задокументировано в Makefile/README той картой; здесь — только вопрос о новом
быстром таргете.

**Update 2026-07-30 (рефакторинг Makefile/env):** посылка карточки изменилась.
`test-php-live` и `test-db-setup` удалены; `make test` теперь САМ поднимает
изолированный тест-стенд (`xakki-convertor-test`) с готовой `convertor-test` и
гоняет полный PHPUnit-сьют чисто — 494 теста зелёные, «громкой красноты» от
непровижиненной БД больше нет by design. Открытый вопрос сузился: нужен ли
быстрый `test-php-unit` (только `tests/Unit`, БЕЗ подъёма стенда) для локальной
итерации — теперь это вопрос СКОРОСТИ, а не корректности.

**Acceptance Criteria:**
- [x] Добавлен `make test-php-unit` — гоняет только `tests/Unit`, без подъёма тест-стенда
- [x] `make test` остаётся каноническим CI-таргетом (полный сьют на live-стенде)
- [x] Документировано в `##` help Makefile

**Decisions:**
- Да: добавить `make test-php-unit` (только `tests/Unit`), без подъёма стенда.
- `make test` остаётся каноническим CI-таргетом.
- Отдельный PHPUnit suite `unit` в `phpunit.xml` — по необходимости при реализации (не блокер решения).

**Work notes:**
Groomed 2026-08-01: approve fast unit-only target; make test stays CI canonical.

**Status:** ready.

## Execution Log

- 2026-08-01: added `test-php-unit` in `app-symfony/Makefile`; no `REQUIRE_TEST`; phpunit `tests/Unit`.
- 2026-08-01: verified `make test-php-unit` on dev php container — OK (236 tests, 948 assertions, ~0.6s); no test-stand. AC met → test→ready.
