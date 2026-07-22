### Мелкие follow-up после home-04 (examples/seed)

**Criticality:** Low

**TAGS:**
- tech-debt
- backend

**Описание:**
Две некритичные находки, всплывшие при реализации `home-04-format-info-examples`
(seed-examples + публичный `ExampleController`). Обе non-blocking, вынесены
сюда, чтобы не потерять.

1. **Pre-existing PHPUnit notice** (не относится к эпику home):
   `ConversionRegistryFallbackTest::testInvalidateMatrixResetsPerRequestCache`
   использует `createMock` без expectations → PHPUnit Notice. Exit code = 0
   (гейт зелёный), но баннер «OK, but there were issues». Починка — добавить
   ожидания моку либо заменить на реальный объект.

2. **async-aws HeadObject content-length = 0 для `text/html`**: для примера
   `examples/markup/md-to-html.html` `HeadObject` возвращает `content-length: 0`
   (реальный объект в S3 — ~1.9 KiB; стрим отдаёт все 1985 байт корректно).
   Сейчас обойдено защитно (строка размера рендерится только при `size > 0`).
   Более чистое решение — кэш ответа `GET /api/v1/examples` (инвалидируемый при
   seed) вместо per-request HeadObject.

**Open questions:**
- Нужен ли кэш списка примеров вообще на текущем масштабе (6 объектов)?
