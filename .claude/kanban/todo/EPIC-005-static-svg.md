### Frontend-контролы конвертаций

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Profile-driven frontend controls и presentation conversion catalog без worker или
backend implementation scope.

**Problem:**
Статичные GIF/BMP/TIFF/ICO недоступны как согласованные SVG targets.

**Impact:**
Пользователи не получают legacy/icon SVG results, а статичный image pipeline остаётся
неполным.

**Recommendation:**
Реализовать статичную политику CairoSVG/Pillow из CNV-75. Анимированный SVG→GIF
является browser-задачей CNV-82 и выполняется в отдельном EPIC-007.

**Acceptance Criteria:**
- Выполнены AC CNV-75; pytest, `make test` и `make build` зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- PNG/JPEG/WebP не входят в scope: они реализованы отдельно в CNV-74-01.
- Анимированный SVG не относится к image-worker: CNV-82 включена в EPIC-007 как
  задача отдельного browser worker.

**Subtasks:**
- CNV-96 — static SVG catalog fallback
- CNV-99 — document controls
- CNV-102 — media controls
- CNV-105 — data controls
- CNV-107 — animated SVG controls

**Integration checklist:**
- Проверить static SVG fixtures и каталог форматов.
- Выполнить pytest, `make test` и `make build`.
