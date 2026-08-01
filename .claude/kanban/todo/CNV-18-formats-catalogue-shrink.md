### Каталог форматов: сокращение необъявленных пар (docs/UI ↔ реальность)

**Criticality:** Medium

**TAGS:**
- tech-debt
- registry
- data-quality
- docs

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

**Status:** todo.
