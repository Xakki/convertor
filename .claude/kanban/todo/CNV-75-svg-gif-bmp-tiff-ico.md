### Static SVG: image-worker legacy targets

**Criticality:** Medium

**TAGS:**
- feature
- images
- svg
- image-worker

**Description:**
Реализовать в image-worker статичную конвертацию SVG в GIF, BMP, TIFF и ICO. Карточка не меняет API-каталог и frontend.

**Problem:**
Однокадровый SVG pipeline ещё не применяет зафиксированные правила palette/alpha, TIFF и ICO, поэтому результаты legacy-форматов непредсказуемы.

**Impact:**
Без worker-реализации backend не сможет безопасно публиковать обещанные static SVG targets, а пользователи получат неверные кадры или свойства файлов.

**Recommendation:**
Использовать существующий безопасный SVG raster pipeline с CairoSVG и Pillow: GIF — один статичный кадр, BMP — без alpha, TIFF — single-page LZW, ICO — PNG-кадры 16/32/48/256. Принимать только нормализованные options из job; не добавлять browser runtime или анимацию.

**Acceptance Criteria:**
- image-worker создаёт статичный GIF из SVG без анимации.
- BMP не содержит alpha; TIFF состоит из одного LZW-сжатого кадра.
- ICO содержит PNG-кадры 16×16, 32×32, 48×48 и 256×256.
- Worker-тесты с SVG fixture проверяют MIME/свойства результата, размеры и кадры ICO.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Статичный SVG → GIF остаётся однокадровой image-worker конвертацией.
- Анимированный SVG → GIF принадлежит CNV-82 и browser-worker; fallback анимации в один кадр запрещён.
- Публикацию пар в catalog выполняет CNV-95, UI — CNV-96.
