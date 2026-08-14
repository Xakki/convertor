### Расширение SVG-конвертаций image-worker

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Изолированное расширение image-worker и общего каталога форматов для SVG target-конвертаций.

**Problem:**
Поддерживаемые движком GIF, BMP, TIFF и ICO недоступны как согласованные SVG target-форматы с проверяемой семантикой.

**Impact:**
Пользователи не получают legacy- и icon-форматы, а случайная реализация рискует некорректной прозрачностью, анимацией или набором ICO-размеров.

**Recommendation:**
Реализовать согласованную статичную политику CairoSVG/Pillow из CNV-75 и закрепить её fixture-тестами.

**Acceptance Criteria:**
- Выполнены все AC CNV-75; pytest, `make test` и `make build` зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- PNG/JPEG/WebP не входят в scope: они реализованы отдельно в CNV-74-01.

**Subtasks:**
- CNV-75 — SVG → GIF, BMP, TIFF и ICO

**Integration checklist:**
- Обновить каталог форматов и проверить результаты на fixture SVG.
- Выполнить pytest, `make test` и `make build`.
