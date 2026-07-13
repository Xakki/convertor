### Сидинг тарифных планов — тихий no-op (нет команды и нет фикстур)

**Criticality:** Medium

**TAGS:**
- bug-fix
- feature
- config

**Description:**
`make init` / `make seed-plans` выполняют
`doctrine:fixtures:load --group=plans || app:seed:plans || true`, но:
- команды `app:seed:plans` в репозитории **нет**;
- каталога `src/DataFixtures/` (группа `plans`) **нет**;
- `|| true` глотает ошибку.

**Problem:**
Сидинг планов — тихий no-op. Свежий `make init` не создаёт ни одного
тарифного плана.

**Impact:**
На чистой установке нет subscription-планов → квоты/лимиты/оплата не имеют
базовых сущностей; фичи, зависящие от планов, ломаются или ведут себя
неопределённо. Ошибка не видна из-за `|| true`.

**Recommendation:**
Реализовать реальный сидинг: команда `app:seed:plans` ИЛИ класс
`DataFixtures` (группа `plans`), создающий канонический набор планов
(free/paid и т.п. — сверить с ROADMAP по лимитам 50MB free / 500MB paid).
После — убрать/пересмотреть `|| true`, чтобы провал сидинга был заметен.

**Acceptance Criteria:**
- `make seed-plans` на чистой БД создаёт полный набор тарифных планов.
- Идемпотентность: повторный запуск не плодит дубли.
- `|| true` не маскирует реальную ошибку сидинга.
- Tests/QA green: `make phpstan`, `make cs-check`.

**Open questions:** *(grooming)*
- Команда `app:seed:plans` vs `DataFixtures --group=plans` — что каноничнее
  для проекта (init гоняется и в проде?).
- Точный состав/параметры планов (имена, лимиты, цены) — сверить с ROADMAP/квотами.

**Контекст:** разбор `make init`/`make seed-plans` (2026-07-12).

**Status:** grooming.
