### migrate-diff: загрязнение при генерации миграций из-за дрейфа схемы БД и entity-маппингов

**Criticality:** Medium
**Epic:** [[CNV-48]]

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
- [x] Зафиксировано, какие таблицы/колонки/индексы дрифтят
- [x] Catch-up миграция синхронизирует DB ↔ entities
- [x] На entities добавлены `#[ORM\Index]` с реальными именами индексов (и прочие mapping-правки, нужные для чистого validate)
- [x] После применения на чистую БД: `migrate-diff` пустой; `doctrine:schema:validate` зелёный (mapping)
- [x] Кратко задокументировано, как не допускать дрифт после merge миграций
- [x] CI empty-diff — follow-up вместе с GHA (не блокер закрытия этой карточки)

**Decisions:**
- Исправление: catch-up миграция + `#[ORM\Index]` на entities (имена = как в БД), чтобы убрать шум rename IDX_*.
- CI-check на пустой `migrate-diff` — позже, вместе с GitHub Actions (`[[CNV-26-no-ci-pipeline]]`), не в scope закрытия этой карточки.
- Поглощена карточка `[[doctrine-schema-validate-drift]]` (тот же mapping↔DB дрифт индексов/DC2Type).

**Work notes:**
Groomed 2026-08-01: catch-up + ORM\Index; CI empty-diff deferred to GHA; absorbed doctrine-schema-validate-drift.

**Status:** ready.

## Drift inventory (до фикса)

### Индексы (только в SQL, не на entity → DROP/RENAME в diff)
| Table | Index | Columns | Diff wanted |
|---|---|---|---|
| conversions | IDX_CONVERSIONS_USER_ID | user_id | RENAME → hash |
| conversions | FK_CONVERSIONS_INPUT | input_file_id | RENAME → hash |
| conversions | FK_CONVERSIONS_OUTPUT | output_file_id | RENAME → hash |
| conversions | IDX_CONVERSIONS_CREATED_AT | created_at | DROP |
| conversions | IDX_CONVERSIONS_STATUS_UPDATED_AT | status, updated_at | DROP |
| conversions | IDX_CONVERSIONS_STATUS_CREATED_AT | status, created_at | DROP |
| payments | IDX_PAYMENTS_USER_ID | user_id | RENAME → hash |
| payments | IDX_PAYMENTS_STATUS | status | DROP |
| users | UNIQ_USERS_TELEGRAM_ID / PHONE / EMAIL / GUEST_ID | … | RENAME → hash |
| plans | UNIQ_PLANS_NAME | name | RENAME → hash |

### Колонки (DC2Type comments / TINYINT display / DB DEFAULT без mapping)
| Table | Columns |
|---|---|
| conversions | status (DEFAULT), is_ai/is_ocr (TINYINT(1)+DEFAULT), created_at/updated_at (DC2Type) |
| payments | status (DEFAULT), created_at (DC2Type) |
| users | plan/daily_*/is_*/quota_reset_at/created_at (DEFAULT + DC2Type + TINYINT) |
| file_storage | created_at/expires_at (DC2Type) |
| conversion_toggles | created_at/updated_at (DC2Type) |
| worker_capabilities | last_seen (DC2Type), status (DEFAULT) |

## Execution Log

- 2026-08-02: start — card → progress; branch `epic/CNV-48`; drift inventory via `schema:update --dump-sql` on TEST+DEV.
- 2026-08-02: entities — `#[ORM\Index]`/`UniqueConstraint` с реальными именами (commit `1717a45`, Agent: chore).
- 2026-08-02: catch-up `Version20260801214439` (колонки: снять DC2Type, TINYINT/DEFAULT); применена на **TEST** и **DEV**.
- 2026-08-02: verify — `schema:validate` OK (mapping+DB); `migrate-diff` → No changes detected (оба стенда); `make cs` + `make phpstan` OK.
- 2026-08-02: doc `docs/doctrine-schema-drift.md`.
