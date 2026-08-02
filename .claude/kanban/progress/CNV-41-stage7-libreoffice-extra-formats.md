### LibreOffice-воркер: доп-форматы Стадии 7 (epub-вход / pages / таблицы / презентации / pdf→jpg)

**Criticality:** Low — Stage 7 (post-MVP)

**TAGS:**
- feature

**Description:**
Форматы, отложенные при `validate-libreoffice-worker` (MVP покрыл doc/pdf/markup(md)).
Собраны здесь, чтобы не потерять. Владение разметкой markup-семейства (rst/latex/wiki
и связанные пары) — **эта карточка**, не дублировать во freeze.

**Scope (всё — Стадия 7):**
- **epub как ВХОД (полностью).** Сейчас урезано до `epub→md` (pandoc). soffice не
  импортирует epub → остальные цели через pandoc: `epub→docx/odt/html/rtf/txt`;
  `epub→pdf` **заблокирован на `conversion-chaining`** (pandoc→docx→soffice).
- **pages (Apple Pages) как ВХОД.** Сначала probe `libetonyek` в образе; если
  отсутствует — drop pages из scope (не реализовывать вслепую).
- **Таблицы / LibreOffice Calc:** `xls, xlsx, ods, csv` → office/pdf цели
  (Calc в libreoffice-worker). Tabular I/O (чистый csv↔xlsx и т.п. без office) —
  зона **data-worker**, не дублировать здесь без явной границы.
- **Презентации / LibreOffice Impress:** `ppt, pptx, odp` → `pptx, odp, pdf`.
- **PDF→jpg постранично** (`pdftoppm`).
- **Markup** `rst/latex/wiki` (pandoc) — ownership этой карточки.

**Dependencies:**
- Не стартовать раньше Stage 7 / приоритета ROADMAP.
- `epub→pdf` ждёт `conversion-chaining`.
- Матрица/DLQ уже выровнены (`align-document-stream-matrix-dlq` — done).

**Decisions:**
- Выделено из `validate-libreoffice-worker` / `post-mvp-conversion-formats` (2026-06-21).
- (2026-08-01) Ждать Stage 7 перед стартом.
- Markup ownership = эта карточка (не freeze-дубликат).
- pages = probe libetonyek, иначе drop.
- Calc = office/pdf в libreoffice; data-worker = tabular I/O.
- epub→pdf blocked on chaining.

**Acceptance Criteria:**
- Реализованы/валидированы пары scope выше (кроме dropped pages и blocked epub→pdf
  до появления chaining).
- Registry/матрица рекламирует ровно то, что воркер умеет.
- Tests/QA green per project gates.

**Status:** progress (Stage 7 — python-worker zone done; PHP seed synced).

## Execution Log

- (2026-08-03, Agent: chore) started; branch task/CNV-41.
- (2026-08-03, Agent: python-worker) Dockerfile: +libreoffice-calc, +libreoffice-impress; build-time libetonyek probe → **present** (`libetonyek-0.1-1` in Debian trixie) → **pages KEPT** (`pages` → office targets, runtime probe `_libetonyek_available()`).
- (2026-08-03, Agent: python-worker) worker `_MATRIX` Stage-7: epub-input pandoc pairs (no epub→pdf); Calc xls/xlsx/ods/csv→office; Impress ppt/pptx/odp→pptx/odp/pdf; pdf→jpg; markup rst/latex/tex/wiki→md-family.
- (2026-08-03, Agent: python-worker) pdf→jpg: pdftoppm; 1 page → `.jpg`; 2+ pages → `.zip` (`page-001.jpg`, …); delivery ext `jpg`/`zip`.
- (2026-08-03, Agent: python-worker) `make test-python-libreoffice` → 37 passed, 1 skipped (pages test skipped in container — libetonyek present).
- (2026-08-03, Agent: php-registry) seed sync: обновлены `Version20260722150301` documentMatrix + `ConversionRegistrySeedFixture`; новая миграция `Version20260803120000` (UPDATE seed document для уже засеянных БД). 22 source × 155 пар document; golden 394 entries; `ConversionRegistrySeedFixtureSyncTest` + `ConversionRegistryGoldenTest` + `test-drift` green; phpstan migrations OK.
