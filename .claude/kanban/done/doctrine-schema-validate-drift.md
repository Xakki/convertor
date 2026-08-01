### `doctrine:schema:validate` грязный по всему проекту (mapping ↔ DB рассинхрон)

**Criticality:** Minor

**TAGS:**
- tech-debt
- db

**Description:**
`php bin/console doctrine:schema:validate` показывает, что схема БД
рассинхронизирована с ORM-маппингом почти по всем сущностям: Doctrine хочет
переименовать hand-named индексы в свои дефолтные hash-имена (`IDX_*`), плюс
ряд нормализаций `DC2Type`-комментариев/типов. Индексы в проекте объявляются
руками в миграциях (ни одна сущность не декларирует `#[ORM\Index]`), поэтому
`doctrine:migrations:diff` стабильно генерит шумный drift.

**Problem:**
`doctrine:schema:validate` нельзя использовать как гейт (всегда красный), а
`migrations:diff` нельзя доверять «как есть» — каждый раз нужно вручную вычищать
пред-существующий шум (прецедент: `Version20260713061310` уже это делала).

**Impact:**
Нельзя автоматически ловить настоящий mapping-drift; ручная чистка diff при
каждой новой миграции; риск случайно закоммитить чужой drift.

**Recommendation:** (груминг) выбрать стратегию:
- либо привести маппинг к БД (объявить реальные индексы через `#[ORM\Index]` с
  теми же именами, синхронизировать `DC2Type`);
- либо явно принять hand-migration-подход и задокументировать, что
  `schema:validate` в проекте не гейт (тогда — как ловить настоящий drift?).

**Контекст:** найдено при верификации hardening-05-conversions-admin-indexes
(2026-07-17), пред-существующее, не связано с той картой.

**Decisions:**
- Поглощено `[[migrate-diff-schema-drift]]` — работа выполняется там (catch-up + `#[ORM\Index]`).
- Карточка отменена как отдельная задача.

**Work notes:**
Groomed 2026-08-01: cancelled standalone; merged into migrate-diff-schema-drift.

**Status:** cancelled (merged).
