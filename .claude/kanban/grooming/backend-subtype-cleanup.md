### Вычистить мёртвый `subType` на бэке (ConversionMessage/resolveSubType)

**Критичность:** Low

**TAGS:**
- tech-debt

**Описание:**
После того как ai-воркер стал flag-agnostic (см. [[validate-ai-worker]]) и больше не
читает `job["subType"]` (режим STT/TTS выводится из пары форматов), поле `subType`
в `ConversionMessage` и логика `resolveSubType` на бэке стали мёртвыми.

**Проблема:**
- `subType` всё ещё формируется/передаётся бэком, но воркером не используется.

**Решение:**
- Удалить `subType` из `ConversionMessage` (DTO/Message) и метод `resolveSubType`
  (и его вызовы) на PHP-стороне.
- Проверить, что выбор stream по-прежнему корректен (stream выбирает бэк по паре
  форматов / флагам вроде `ocr`).

**Критерии приёмки:**
- `subType` не упоминается на бэке (grep чист).
- `composer test:phpstan` зелёный, тесты бэка зелёные.

**Open questions:**
- Точные места: где формируется `subType` и `resolveSubType` (найти grep'ом при старте).

**Decisions:**
- Выделено из [[validate-ai-worker]] (2026-06-23): сам ai-воркер стал flag-agnostic,
  cleanup бэка вынесен отдельной задачей (вне scope воркер-таска).
