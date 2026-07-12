### Вынести признак OCR-пары в `/api/v1/formats` (убрать дубль OCR-списков на фронте)

**Критичность:** Low

**TAGS:**
- refactor
- backend
- frontend

**Описание:**
Фронтовая страница конвертации (`templates/conversion/index.html.twig`) хардкодит `OCR_SOURCES=['jpg','png','tiff','pdf']` и `OCR_TARGETS=['txt','md','docx']`, чтобы решать, показывать ли OCR-тоггл. Это дубль `App\Service\Conversion\ConversionRegistry::OCR_SOURCES/OCR_TARGETS` — единственного владельца OCR-матрицы по архитектуре проекта.

**Проблема:**
- Дрейф: при изменении OCR-матрицы на бэке (добавить source/target, нюанс «pdf только под флагом» и т.п.) JS-списки молча разойдутся — тоггл будет показываться/прятаться для неверных пар (422 от API либо скрытый, но валидный OCR-путь).
- `GET /api/v1/formats` (`getSupportedFormats`) сейчас отдаёт `{from,to,category,isAi}` — БЕЗ признака OCR-способности пары.

**Decisions (2026-07-11):**
- Имя поля — **`ocrCapable`** (не `isOcr`): семантика «доступная возможность / тоггл», отличается от `isAi` (обязательный роутинг).
- В payload `GET /api/v1/formats` добавить булев `ocrCapable` на пару, вычисляемый из `ConversionRegistry::isOcrSupported(from,to)`.
- Фронт (`templates/conversion/index.html.twig`): вычислять `showOcr` из `ocrCapable` для выбранной пары (from,to), удалить оба JS-константа `OCR_SOURCES`/`OCR_TARGETS`.
- Поле аддитивное (не ломающее) — в рамках реализации проверить сериализацию `/formats` и существующие тесты.

**Контекст:** найдено при ревью [[upload-conversion-ui]] (2026-07-10). На момент находки JS-списки ТОЧНО совпадали с registry — живого бага нет, только риск дрейфа, поэтому вынесено отдельной карточкой, а не чинилось в UI-таске.

**Status:** ready (реализовано в `aed295b`, ветка `task/formats-api-ocr-capable-flag`).

**Execution Log:**
- `ConversionRegistry::getSupportedFormats()` — добавлен `ocrCapable => isOcrSupported(from,to)` + PHPDoc.
- `ConversionController` — `OA\Property ocrCapable:boolean` в OpenAPI-схеме `/formats`.
- `templates/conversion/index.html.twig` — удалены `OCR_SOURCES`/`OCR_TARGETS`, `showOcr` теперь берёт `ocrCapable` выбранной пары (паттерн `loginRequired`).
- Проверка регрессии: все OCR-пары (jpg/pdf/png/tiff → txt/md/docx) есть в матрице (golden-снапшот), target-дропдаун предлагает только in-matrix пары → новый `showOcr` поведенчески идентичен старому декартову чеку во всех достижимых состояниях UI.
- QA: `make phpstan` OK (70/70), `make cs`/`cs-check` чисто, phpunit (Golden/Routing/ConversionToggle) — OK 13 tests / 48 assertions.
