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
Планы уже сидятся миграцией `Version20260419000001` (free/basic/pro) — отдельный
сидинг не нужен. Убрать мёртвый no-op-таргет `seed-plans` из зависимостей
`make init` и из Makefile; убедиться, что `make init` проходит без него.

**Acceptance Criteria:**
- Мёртвый таргет `seed-plans` убран из зависимостей `make init` и из Makefile
  (несуществующая ссылка тоже убрана).
- `make init` на чистой БД проходит зелёным (не падает на отсутствующем
  таргете/команде).
- После `make migrate` тарифные планы (free/basic/pro) присутствуют в БД
  (сидятся миграцией `Version20260419000001`).
- Tests/QA green: `make phpstan`, `make cs-check`.

**Decisions:**
Планы сидятся миграцией `Version20260419000001` (free/basic/pro). `make seed-plans` —
мёртвый no-op (нет `app:seed:plans`/фикстур). Решение: УБРАТЬ no-op-таргет
`seed-plans` из `make init` и Makefile (+ убрать несуществующую ссылку), плюс
убедиться что `make init` не падает без него. Отдельную seed-команду НЕ вводим —
источник планов = миграция. (Пересмотр/изменение самих значений планов и месячные
лимиты вынесены в отдельную карту plan-quota-daily-monthly.)

**Контекст:** разбор `make init`/`make seed-plans` (2026-07-12).

**Status:** grooming.
