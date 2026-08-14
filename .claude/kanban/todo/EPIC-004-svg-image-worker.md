### Расширение SVG-конвертаций image-worker

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Изолированное расширение image-worker и общего каталога форматов для статичных и
анимированных SVG target-конвертаций.

**Problem:**
Статичные GIF/BMP/TIFF/ICO недоступны как согласованные SVG targets, а анимированный
SVG требует отдельного browser runtime, безопасных лимитов и profile-based settings.

**Impact:**
Пользователи не получают legacy/icon или animated GIF результаты; случайная
реализация может дать однокадровый fallback либо допустить CPU/RAM abuse browser runtime.

**Recommendation:**
Сначала реализовать статичную политику CairoSVG/Pillow из CNV-75, затем использовать
изолированный browser worker EPIC-009 для animated SVG→GIF из CNV-82 после CNV-85.

**Acceptance Criteria:**
- Выполнены AC CNV-75 и CNV-82; pytest, `make test` и `make build` зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- PNG/JPEG/WebP не входят в scope: они реализованы отдельно в CNV-74-01.
- CNV-82 зависит от CNV-85 и CNV-88: global settings catalogue и isolated browser
  runtime предшествуют его Chromium/GIF-specific profile.

**Subtasks:**
- CNV-75 — SVG → GIF, BMP, TIFF и ICO
- CNV-82 — анимированный SVG → GIF через headless Chromium

**Integration checklist:**
- Проверить static и animated SVG fixtures, resource limits Chromium и отсутствие
  network/file access.
- Выполнить pytest, `make test` и `make build`.
