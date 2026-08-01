### migrate-diff: загрязнение при генерации миграций из-за дрейфа схемы БД и entity-маппингов

**Criticality:** Medium

**TAGS:**
- tech-debt
- database
- migrations
- quality
- tooling

**Description:**
`make migrate-diff` (Doctrine `doctrine:migrations:diff`) генерирует миграции, загрязненные посторонними изменениями схемы. При попытке создать миграцию для новой фичи на ветке `epic/registry-02` агент `backend-php` заметил в выводе `diff`:
- целевая фича: добавить колонку в таблицу `foo`
- паразитный вывод: ALTER TABLE другие таблицы — расхождения между текущим state в MariaDB (dev-стенд, `convertor`) и что сейчас описано в Doctrine entity-маппингах.

Агент отбросил этот вывод и написал миграцию вручную. Это означает, что `make migrate-diff` **сейчас недоступен как инструмент** — его не безопасно использовать (любой, кто запустит этот таргет, получит миграцию, загрязненную чужими дрифтами).

Корневая причина неизвестна: либо (a) есть hand-written миграции, которые расходятся с entity-маппингами, либо (b) были правки в entities, которые никогда не были замигрированы в dev-БД, либо (b) их комбинация.

Параллельно `doctrine:schema:validate` грязный почти по всем сущностям: Doctrine хочет переименовать hand-named индексы в `IDX_*`, плюс нормализации `DC2Type`. Индексы объявляются руками в миграциях (без `#[ORM\Index]` на entity) — отсюда шум в diff/validate. Карточка `[[doctrine-schema-validate-drift]]` поглощена сюда.

**Problem:**
При генерации новой миграции любой разработчик не может положиться на чистый вывод `migrate-diff` — придётся вручную вычищать паразитные diff или писать миграцию вручную. Невозможность автоматизировать == техдолг на качестве разработки. `schema:validate` нельзя использовать как гейт.

**Impact:**
- **Высокий** — блокирует удобный workflow "поправил entity → make migrate-diff → готово", заставляет писать вручную
- На dev-стенде экономия времени в каждую задачу (~5–10 мин за миграцию)
- Риск пропустить реальное изменение, потеряв его в шуме паразитного дрифта

**Recommendation:**
1. Catch-up миграция + объявить индексы через `#[ORM\Index]` на entities (имена как в БД)
2. После sync — `migrate-diff` пустой на чистой БД
3. CI empty-diff гейт — позже вместе с GHA (`[[CNV-26-no-ci-pipeline]]`)

**Acceptance Criteria:**
- [ ] Зафиксировано, какие таблицы/колонки/индексы дрифтят
- [ ] Catch-up миграция синхронизирует DB ↔ entities
- [ ] На entities добавлены `#[ORM\Index]` с реальными именами индексов (и прочие mapping-правки, нужные для чистого validate)
- [ ] После применения на чистую БД: `migrate-diff` пустой; `doctrine:schema:validate` зелёный (mapping)
- [ ] Кратко задокументировано, как не допускать дрифт после merge миграций
- [ ] CI empty-diff — follow-up вместе с GHA (не блокер закрытия этой карточки)

**Decisions:**
- Исправление: catch-up миграция + `#[ORM\Index]` на entities (имена = как в БД), чтобы убрать шум rename IDX_*.
- CI-check на пустой `migrate-diff` — позже, вместе с GitHub Actions (`[[CNV-26-no-ci-pipeline]]`), не в scope закрытия этой карточки.
- Поглощена карточка `[[doctrine-schema-validate-drift]]` (тот же mapping↔DB дрифт индексов/DC2Type).

**Work notes:**
Groomed 2026-08-01: catch-up + ORM\Index; CI empty-diff deferred to GHA; absorbed doctrine-schema-validate-drift.

**Status:** todo.
