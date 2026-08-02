### Conversion delete: log S3 failures in deleteObjectQuietly

**Criticality:** Nit
**Epic:** [[CNV-48]]
**Discovery:** review CNV-8 (реализация `ConversionManager::deleteConversion`)

**TAGS:**
- tech-debt
- observability

**Description:**
По итогам review CNV-8: `ConversionManager::deleteObjectQuietly()` глотает
`\Throwable` при S3 `deleteObject` без логирования. Идемпотентность (строка БД
всё равно удаляется) — намеренная, как в `FileCleanupService`, но там сбой
логируется `logger->warning` с bucket/key/conversionId/error.

**Problem:**
При user-initiated hard delete (`DELETE /convert/{id}`) сбой S3 остаётся
невидимым: осиротевшие объекты в `-inputs`/`-results` не обнаружить без
ручного аудита бакетов.

**Impact:**
Низкий — редкий транзиентный сбой S3 или race с параллельной очисткой; БД
консистентна, но orphan-объекты копятся молча до 24h-sweep или ручного prune.

**Recommendation:**
Добавить `LoggerInterface` в `ConversionManager` (или передать в
`deleteObjectQuietly`) и логировать `warning` по образцу
`FileCleanupService::deleteObject` — с `bucket`, `key`, `conversionId`,
`error`; поведение (не блокировать delete строки БД) не менять.

**Acceptance Criteria:**
- Сбой S3 при `deleteConversion` пишет `warning` в лог с контекстом (bucket, key,
  conversionId, message).
- Hard delete строки БД по-прежнему не блокируется сбоем S3.
- Tests/QA green: `make phpstan`, `make cs-check`, релевантные PHPUnit для
  delete/retry (CNV-8 suite).

**Open questions:**
- Нет блокеров — рекомендация однозначна; карточка в grooming для batch epic
  CNV-48.

**Decisions:**
