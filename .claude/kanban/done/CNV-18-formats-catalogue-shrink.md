### Каталог форматов: сокращение необъявленных пар (docs/UI ↔ реальность)

**Criticality:** Medium

**TAGS:**
- tech-debt
- registry
- data-quality
- docs

**Epic:** [[CNV-47]] — подзадача 5.

**Description:**
При seed-миграции `ConversionRegistry` DB-матрица рекламирует на 57 пар меньше,
чем старый PHP-hardcode. Все 57 пар проверены по Python-воркерам — ни одна
никогда не работала. Hardcode над-рекламировал пары, которые воркеры отклоняют.

Группы «мёртвой рекламы» (markup-семейство, epub-source сверх epub→md, pages,
плюс уже санкционированная Stage-7 четвёрка) и переклассификация
markup→document для 10 пар — см. историю в git / Execution Log при старте.

**Problem:**
Docs / `ROADMAP.md` / UI-копия могут всё ещё перечислять форматы/пары, которых
нет в живой матрице. Каталог уже сократился в runtime; документация должна
совпасть с реальностью.

**Recommendation:**
Санкционировать shrink каталога; обновить ROADMAP/docs/UI под фактическую
матрицу. Реализацию недостающих пар оставить Stage 7 / freeze
(`stage7-libreoffice-extra-formats` и др.). Guard registry↔worker — не сейчас.

**Acceptance Criteria:**
- `ROADMAP.md`, релевантные docs и UI-копия/лендинг сверены с фактическим
  `GET /formats` / DB-матрицей; устаревшие упоминания мёртвых пар убраны или
  явно помечены как Stage 7.
- Переклассификация `category` markup→document для 10 пар учтена в UI/docs,
  если они группируют по `category`.
- Нет новой карточки/кода guard registry↔worker в рамках этой задачи.

**Decisions:**
- (2026-08-01) Санкционирован shrink: обновить ROADMAP/docs/UI под реальность.
- Реализация недостающих пар остаётся Stage 7 / freeze — не в этой карточке.
- Guard registry↔worker — **не** заводить отдельную карточку сейчас.

**Status:** ready.

**Execution Log:**
- 2026-08-01: Inventory: live matrix 309 pairs (golden); dead docs/UI surfaces found.
- 2026-08-01: Docs: ROADMAP matrix + queue-streams markup claim aligned to live; Stage 7 rows explicit.
- 2026-08-01: UI: CuratedConversionPairs + ExampleCatalog markup→document; TEXTUAL_SOURCE_FORMATS drop rst/latex/wiki; SHOWCASE drop markup/archive; app-front FORMAT_GROUPS shrink.
- 2026-08-01: No registry↔worker guard; no new format pairs (CNV-41 owns).
- 2026-08-01: Residual: `make seed-examples` needed after ExampleCatalog category change (S3 path examples/document/…).
- 2026-08-01: Unit OK — CuratedConversionPairsTest, ConversionRegistryTextSourceTest, ExampleCatalogTest.
- 2026-08-01: → ready (catalogue shrink complete; AC met).
