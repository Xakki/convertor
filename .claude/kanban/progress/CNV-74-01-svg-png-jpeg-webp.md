### Поддержка SVG → PNG, JPEG и WebP

**Criticality:** High

**TAGS:**
- feature
- images
- svg
- workers

**Description:**
Image-worker должен принимать SVG как исходный формат и растеризовать его в PNG,
JPEG/JPG либо WebP через CairoSVG.

**Problem:**
`workers/image/worker.py` намеренно исключает SVG из `_MATRIX`: Pillow не
обеспечивает требуемую обработку SVG, а CairoSVG отсутствует в образе.

**Impact:**
Конвертация SVG недоступна в API-каталоге, интерфейсе и воркере, хотя это один из
базовых сценариев получения растровой версии векторной графики.

**Recommendation:**
Добавить CairoSVG в image-worker image и использовать его только для ветки SVG.
Обновить capability matrix, fallback-каталог фронтенда и связанные тесты. Перед
рендерингом ограничить небезопасные внешние ресурсы SVG и не допускать обхода
стандартных лимитов входного файла.

**Acceptance Criteria:**
- SVG отображается как source format с target-форматами ровно `png`, `jpg`,
  `jpeg`, `webp` в worker capabilities, `/api/v1/formats` и fallback-каталоге
  `app-front/js/upload.js`.
- Воркер создаёт корректный PNG, JPEG/JPG и WebP; JPEG получает RGB-результат.
- SVG с внешними ресурсами/ссылками не инициирует сетевой доступ при рендеринге.
- Ошибка CairoSVG возвращается как понятная ошибка конвертации, без утечки пути,
  содержимого SVG или трассировки.
- Добавлены unit/integration-тесты успешных пар и отказа для неподдерживаемой пары;
  существующие image-worker тесты остаются зелёными.
- Выполнены применимые `make`-проверки workers и backend; PHPStan и code style без
  новых ошибок.

**Decisions:**
- 2026-08-15: в этой задаче поддерживаются только `png`, `jpg/jpeg`, `webp`.
- 2026-08-15: GIF, BMP, TIFF и ICO вынесены в CNV-75.

**Affected zones:**
- `workers/image/worker.py`
- `workers/requirements*.txt` и Dockerfile image-worker
- `workers/tests/test_image_worker*.py`
- `app-front/js/upload.js`
- API-каталог capabilities в `app-symfony`

**Execution Log:**
- 2026-08-15: задача переведена в progress; SVG-ветка использует CairoSVG с запретом внешних ресурсов, затем Pillow для JPEG/WebP.
- 2026-08-15: обновлены сгенерированные capabilities-каталоги и fallback интерфейса; добавлены тесты целевых SVG-пар, блокировки внешнего ресурса и сокрытия деталей ошибки.
- 2026-08-15: `make test-python-image`, `make TEST=1 test-drift` и `make TEST=1 test-php FILTER=FormatsCatalogIndependenceTest` успешны. `make phpstan` и `make cs-check` остановлены окружением с Error 9 на 0%% до анализа.
