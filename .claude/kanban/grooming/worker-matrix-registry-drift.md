### Worker CAPABILITIES.matrix ⊄ PHP registry: 37 pairs in workers absent from PHP

**Статус: RESOLVED** (2026-06-22, задача stream-subscription-distribution)

**Критичность:** Medium — задания никогда не доедут до worker (пара в матрице воркера, но роутинг PHP её не диспатчит)

**TAGS:**
- bug
- drift
- tech-debt

**Описание:**
Найдено автотестом `test_routing_drift.py::test_worker_matrix_subset_of_registry` (2026-06-22).
Воркер рекламирует пары в CAPABILITIES.matrix, которых нет в PHP ConversionRegistry.

**Resolution (2026-06-22):**

Все 37 violations устранены следующими правками в `ConversionRegistry::workerCapabilities()`:

1. **`toml` как цель (data worker, 5 пар):** добавлен `'toml'` в targets data-воркера.
2. **`3gp` как источник (ffmpeg, 9 пар):** добавлен `'3gp'` в sources video→video и video→audio-extract.
   3gp — только вход, никогда target (не добавлен в targets).
3. **`wma` как цель (ffmpeg, 7 пар):** удалён `"wma"` из `_AUDIO_FORMATS` output set в
   `workers/ffmpeg/worker.py`. wma остаётся как input-key в SUPPORTED.
   Итог: wma→audio остаётся; audio→wma убрано.
4. **`webm/flv/wmv → audio extract` (ffmpeg, 12 пар):** добавлены webm/flv/wmv в источники
   video→audio-extract блока audio-воркера.
5. **`md→{odt,rtf,txt,epub}` (libreoffice, 4 пары):** добавлен отдельный markup-pair
   `[['md'], ['odt', 'rtf', 'txt', 'epub']]` — только md, не затрагивает rst/html/wiki.

Побочный эффект: API-ответ для archive-форматов изменился с 422 на 400 (archive убран из registry
полностью — теперь попадает в !isSupported() → InvalidArgumentException → 400 в контроллере).
Документировано в [[formats-convert-archive-mismatch]].

**Golden master:** обновлён (306 → 326 entries). GoldenTest зелёный.
**Drift test:** 2/2 PASSED.
**PHPStan / CS-Fixer / PHPUnit / pytest:** все зелёные.

**Связанные карточки:**
- [[formats-convert-archive-mismatch]] — Assertion (A): archive routing key без воркера (resolved)
- [[align-document-stream-matrix-dlq]] — Stage-7 пары в conv.document (остаются открытыми)
- [[stage7-libreoffice-extra-formats]] — расширение libreoffice форматов Стадии 7
