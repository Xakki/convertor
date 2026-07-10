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

**Решение (черновик):**
- Добавить в payload `/api/v1/formats` булев признак на пару — `ocrCapable` (или `isOcr`), вычисляемый из `ConversionRegistry::isOcrSupported(from,to)`.
- На фронте вычислять `showOcr` из этого флага для выбранной пары (from,to), удалить оба JS-константа `OCR_SOURCES/OCR_TARGETS`.

**Открытые вопросы:**
- Имя поля: `ocrCapable` vs `isOcr` (согласовать с существующим `isAi`).
- Не сломает ли добавление поля потребителей `/formats` (проверить сериализацию/тесты).

**Контекст:** найдено при ревью [[upload-conversion-ui]] (2026-07-10). На момент находки JS-списки ТОЧНО совпадали с registry — живого бага нет, только риск дрейфа, поэтому вынесено отдельной карточкой, а не чинилось в UI-таске.

**Status:** grooming.
