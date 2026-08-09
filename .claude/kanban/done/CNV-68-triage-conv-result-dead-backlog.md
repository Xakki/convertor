### Разобрать и удалить устаревший `conv.result.dead`; проверить актуальный `conv.dead`

**Criticality:** Low

**TAGS:**
- tech-debt
- workers
- queue
- dlq

**Description:**
`conv.result.dead` (11 записей на 2026-08-04) — устаревшее имя стрима из
до-WS-транспортного PHP `QueueResultConsumerCommand`; сегодня в него никто
не пишет (это зафиксировано тестом `test_gateway_reclaim_dlq.py:301`).
Актуальный DLQ — `conv.dead` (`workers/gateway/keydb.py:49`), в него пишет
`KeyDbGateway.add_to_dlq()` (`workers/gateway/keydb.py:288-322`), читает
`workers/gateway/dlq_consumer.py`. Механизм переотправки уже есть:
`POST /api/v1/admin/dead-letter/requeue`
(`app-symfony/src/Controller/Admin/Api/DlqController.php:60`).

**Problem:**
Прежняя формулировка карты считала `conv.result.dead` активным DLQ и
предлагала «решить, нужна ли переотправка» именно по нему — неверно: это
мёртвый, никем не записываемый стрим-реликт. Реальный вопрос (нужна ли
переотправка) применим к `conv.dead`, а не к `conv.result.dead`.

**Impact:**
Низкий — мёртвый стрим никак не влияет на текущую обработку задач, только
занимает место в KeyDB и путает при диагностике (ошибочно принимается за
активный DLQ).

**Recommendation:**
Выбрано: удалить легаси-стрим.
1. Разово разобрать 11 исторических записей в `conv.result.dead` (что это
   было, когда написано) — задокументировать для истории.
2. Удалить стрим `conv.result.dead` из KeyDB.
3. Отдельным пунктом — проверить содержимое актуального `conv.dead` и по
   каждой записи решить, нужна ли переотправка через существующий
   `POST /api/v1/admin/dead-letter/requeue`, или запись действительно
   мёртвая.

**Acceptance Criteria:**
- 11 записей `conv.result.dead` (на 2026-08-04) разобраны и задокументированы
  (что/почему) до удаления.
- Стрим `conv.result.dead` удалён из KeyDB (минимум на dev-стенде).
- Содержимое `conv.dead` (актуальный DLQ) проверено; по каждой записи
  зафиксировано решение: переотправка через
  `POST /api/v1/admin/dead-letter/requeue`, либо оставить как мёртвую.
- Карта не содержит утверждения о `conv.result.dead` как активном DLQ —
  зафиксировано, что это устаревшее неиспользуемое имя, актуальный DLQ —
  `conv.dead`.

**Decisions:**
- 2026-08-04: выбран вариант «удалить легаси-стрим» (не переносить/не
  хранить `conv.result.dead»). Прежняя предпосылка карты («`conv.result.dead`
  — текущий DLQ») была неверной — исправлено.
- 2026-08-07 (CNV-71, разбор алерта `ConvertorDeadLetterGrowing`): пункт
  «содержимое `conv.dead` проверено» из Acceptance Criteria — **выполнен**.
  Осталась только часть по `conv.result.dead` (11 легаси-записей, см. выше).

**Разбор единственной записи `conv.dead` (dev-стенд, найдена/устранена 2026-08-07):**
```
id:              1783878917623-0
conversionId:    6
state:           failed
reason:          worker error: Client error '401 Unauthorized' for url
                 'https://convertor.xakki.pro/api/v1/worker/jobs/1783877914562-0/input'
                 (times_delivered=4)
originalStream:  conv.document
originalEntryId: 1783877914562-0
```
Дата записи (по ms-префиксу id) — 2026-07-12 17:55:17 UTC. Consumer-группа
`convertor`: `pending=0`, `last-delivered-id` в точности равен id этой записи —
т.е. запись была доставлена `dlq-consumer`'ом и `XACK`'нута ещё тогда; строка
`Conversion(id=6)` в БД к моменту разбора уже отсутствовала (удалена
несвязанным сбросом dev-данных). Переотправка не нужна — запись
исторический ACKed-residue. Решение: удалена (`XDEL`) с dev-стенда
2026-08-07, повторно проверив перед удалением `pending=0` и совпадение
`last-delivered-id`.

**Корневая причина алерта `ConvertorDeadLetterGrowing` (монотонный gauge):**
`convertor_dead_letter_messages` = `XLEN conv.dead` (`workers/metrics_exporter/exporter.py`).
До правки `dlq_consumer._process_entry` (`workers/gateway/dlq_consumer.py`)
делал только `XACK` после успешного relay-ответа — саму запись из стрима
никогда не удалял (`add_to_dlq` пишет `conv.dead` намеренно без `MAXLEN`,
иначе алерт не мог бы погаснуть в принципе). Итог: `XLEN` мог только расти,
алерт (`expr: convertor_dead_letter_messages > 0`, `for: 10m`) после первой
же DLQ-записи горел бы навсегда, даже если DLQ-consumer успешно финализирует
все новые записи. **Исправлено в CNV-71**: `_process_entry` теперь делает
`XACK` → WARNING-лог с полной декодированной записью (аудит-след, т.к. стрим
был единственной сырой копией) → `XDEL`, строго после подтверждённого
relay-2xx (тот же порядок, что в `expiry.py`). После правки и удаления
residue-записи: `XLEN conv.dead` = 0 на dev, `convertor_dead_letter_messages`
= 0 в Prometheus, `ALERTS{alertname="ConvertorDeadLetterGrowing"}` — пустой
результат (не просто `pending`, алерт полностью снят).

**DLQ-consumer liveness — вердикт по подозрению в «мёртвом» consumer'е:**
`XINFO CONSUMERS conv.dead` на dev показывал `idle` ~17.4 дня у consumer'а
`dlq-consumer`, несмотря на ~30 рестартов `ws-gateway` с 07-26 и лог "dlq
consumer loop started" на каждом. Эмпирически проверено (KeyDB probe +
рестарт тест-стенда с синтетической DLQ-записью): **это НЕ баг, а свойство
метрики** — `idle` в `XINFO CONSUMERS` сбрасывается ТОЛЬКО при реальной
доставке новой записи (`XREADGROUP`, вернувший >0 элементов) или `XCLAIM`;
пустые блокирующие `XREADGROUP`-опросы (5 сек, `dlq_consumer_block_ms`) НЕ
сбрасывают `idle`, даже если consumer их непрерывно выполняет. На
тест-стенде свежерестартованный `dlq-consumer` подобрал и обработал
синтетическую DLQ-запись (`conversionId` заведомо не существующий → PHP 404
→ terminal ack) в пределах ~1 секунды — цикл `run_dlq_consumer_loop` жив и
работает сразу после рестарта. 17.4-дневный `idle` на dev полностью
объясняется тем, что в `conv.dead` на dev с 07-20 не поступало ни одной
новой записи — «мёртвый инструмент», не мёртвый consumer. Фикса не
требуется, отдельная карточка не заводится.

**Контекст:** найдено в ходе диагностического прогона 2026-08-04; `conv.dead`
половина разобрана и закрыта в рамках CNV-71 (2026-08-07).

**Execution Log:**

- 2026-08-08 — карта переведена `todo → progress` проектным
  `kanban-move.sh` (CNV-68); работа ограничена dev KeyDB, DB 2, и только
  легаси-стримом `conv.result.dead`.
- 2026-08-08 — read-only снимок: `make dlq-inspect STREAM=conv.result.dead`.
  `XINFO STREAM`: `length=11`, `groups=0`; `XRANGE` вернул 11 записей.
  Ввиду отсутствия групп `XINFO GROUPS`, `XINFO CONSUMERS` и `XPENDING` пусты.
  Ниже сохранён полный исторический инвентарь до удаления. Время — UTC,
  вычислено из millisecond-части stream ID. Поле `data` — payload легаси
  result-consumer; `_original_id` — исходный ID, сохранённый в записи.

  1. `stream ID=1781958817947-0`, `UTC=2026-06-20 12:33:37.947`,
     `_original_id=1781958817937-0`; `conversionId=4`; payload:
     `state=failed`, `outputBucket=null`, `outputKey=null`,
     `outputMime=null`, `outputSize=null`, `processingMs=0`,
     `error="max_retries (3) exceeded"`. Результат/output отсутствуют.
  2. `stream ID=1782055768805-0`, `UTC=2026-06-21 15:29:28.805`,
     `_original_id=1782055768726-0`; `conversionId=9055767614`; payload:
     `state=completed`, `error=null`, `processingMs=851`,
     `outputBucket=convertor-dev-results`,
     `outputKey=results/2026/06-21/9055767614.json`,
     `outputMime=application/json`, `outputSize=177`.
  3. `stream ID=1782055827930-0`, `UTC=2026-06-21 15:30:27.930`,
     `_original_id=1782055827930-0`; `conversionId=9055826385`; payload:
     `state=completed`, `error=null`, `processingMs=604`,
     `outputBucket=convertor-dev-results`,
     `outputKey=results/2026/06-21/9055826385.mp4`,
     `outputMime=video/mp4`, `outputSize=27003`.
  4. `stream ID=1782055828933-0`, `UTC=2026-06-21 15:30:28.933`,
     `_original_id=1782055828933-0`; `conversionId=9055828613`; payload:
     `state=completed`, `error=null`, `processingMs=61`,
     `outputBucket=convertor-dev-results`,
     `outputKey=results/2026/06-21/9055828613.json`,
     `outputMime=application/json`, `outputSize=177`.
  5. `stream ID=1782056415056-0`, `UTC=2026-06-21 15:40:15.056`,
     `_original_id=1782056415055-0`; `conversionId=11130999638`; payload:
     `state=completed`, `error=null`, `processingMs=548`,
     `outputBucket=convertor-dev-results`,
     `outputKey=results/2026/06-21/11130999638.mp4`,
     `outputMime=video/mp4`, `outputSize=27003`.
  6. `stream ID=1782056416402-0`, `UTC=2026-06-21 15:40:16.402`,
     `_original_id=1782056416401-0`; `conversionId=12866306008`; payload:
     `state=completed`, `error=null`, `processingMs=370`,
     `outputBucket=convertor-dev-results`,
     `outputKey=results/2026/06-21/12866306008.json`,
     `outputMime=application/json`, `outputSize=177`.
  7. `stream ID=1782056715681-0`, `UTC=2026-06-21 15:45:15.681`,
     `_original_id=1782056715680-0`; `conversionId=9055765613`; payload:
     `state=failed`, `outputBucket=null`, `outputKey=null`,
     `outputMime=null`, `outputSize=null`, `processingMs=0`,
     `error="max_retries (3) exceeded"`. Результат/output отсутствуют.
  8. `stream ID=1782062391778-0`, `UTC=2026-06-21 17:19:51.778`,
     `_original_id=1782062391708-0`; `conversionId=11127998756`; payload:
     `state=completed`, `error=null`, `processingMs=652`,
     `outputBucket=convertor-dev-results`,
     `outputKey=test_results/2026/06-21/11127998756.mp4`,
     `outputMime=video/mp4`, `outputSize=27003`.
  9. `stream ID=1782062393398-0`, `UTC=2026-06-21 17:19:53.398`,
     `_original_id=1782062393396-0`; `conversionId=11252716369`; payload:
     `state=completed`, `error=null`, `processingMs=770`,
     `outputBucket=convertor-dev-results`,
     `outputKey=test_results/2026/06-21/11252716369.json`,
     `outputMime=application/json`, `outputSize=177`.
  10. `stream ID=1782062429286-0`, `UTC=2026-06-21 17:20:29.286`,
      `_original_id=1782062429285-0`; `conversionId=10552294370`; payload:
      `state=completed`, `error=null`, `processingMs=580`,
      `outputBucket=convertor-dev-results`,
      `outputKey=test_results/2026/06-21/10552294370.mp4`,
      `outputMime=video/mp4`, `outputSize=27003`.
  11. `stream ID=1782062431037-0`, `UTC=2026-06-21 17:20:31.037`,
      `_original_id=1782062431036-0`; `conversionId=11186773458`; payload:
      `state=completed`, `error=null`, `processingMs=808`,
      `outputBucket=convertor-dev-results`,
      `outputKey=test_results/2026/06-21/11186773458.json`,
      `outputMime=application/json`, `outputSize=177`.

  Ограничение исторических данных: `error` сохраняет только строку
  `"max_retries (3) exceeded"` для двух failed-записей; первичное исключение
  persister-а, stack trace и причина исчерпания попыток в payload не retained,
  поэтому восстановить их из этого стрима невозможно. Для девяти
  `completed`-записей `error=null`; это не доказывает, что первичного сбоя
  persister-а не было, только фиксирует сохранённый payload.
- 2026-08-08 — отдельная read-only проверка актуального `conv.dead` до операции:
  `length=0`, группа `convertor`, `pending=0`, `XRANGE` пуст. Этот стрим не
  является объектом удаления и не изменялся.
- 2026-08-08 — независимый предудалительный контроль повторным
  `make dlq-inspect STREAM=conv.result.dead`: снова `length=11`, `groups=0`,
  `XRANGE=11`; все stream ID и payload совпали с инвентарём выше. Скриптная
  сверка карты: `card_inventory_count=11`, `card_unique_count=11`,
  `card_ids_match_expected=True`, ограничение по первичному исключению есть.
  Только после этой сверки допустимо удаление.
- 2026-08-08 — ровно одно узко ограниченное удаление, без `docker compose`:
  `docker exec xakki-convertor-keydb keydb-cli -n 2 DEL conv.result.dead`.
  Ответ KeyDB: `1` (удалён именно один ключ/стрим). Имя dev-контейнера
  получено из `$(KEYDB_CONT)` Makefile; `REDIS_QUEUE_DB=2` подтверждён
  `make -pn`. Другие команды записи в KeyDB не выполнялись.
- 2026-08-08 — post-delete read-only `make dlq-inspect
  STREAM=conv.result.dead`: `XINFO STREAM` и `XINFO GROUPS` ответили
  `ERR no such key`, `XRANGE` пуст — легаси-стрим более не существует.
- 2026-08-08 — post-delete read-only `make dlq-inspect STREAM=conv.dead`:
  `length=0`, `XRANGE` пуст; единственная группа `convertor` имеет
  `pending=0` (`XPENDING: [0,false,false,false]`). `conv.dead` не удалялся
  и не изменялся.

**Status:** ready
