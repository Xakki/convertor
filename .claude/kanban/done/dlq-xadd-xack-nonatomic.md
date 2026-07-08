### DLQ `XADD conv.dead` + `XACK` не атомарны → возможен дубль DLQ-записи

**Критичность:** Low (низкая вероятность; потребителя `conv.dead` сегодня нет — write-only)

**TAGS:**
- transport
- gateway
- reliability

**Описание:**
В `workers/gateway/keydb.py` (`add_to_dlq`, ~строки 269–272) две операции идут
последовательно, не атомарно:
```python
await self._redis.xadd(DLQ_STREAM, {"data": dlq_payload})  # успех
await self._redis.xack(stream, GROUP, job_id)               # может упасть (сетевой сбой)
```
Если `XADD conv.dead` прошёл, а `XACK` упал, запись остаётся И в `conv.dead`, И в
PEL `gw-reclaim`. Следующий idle-reclaim передиспетчеризует её, воркер снова
зафейлит → второй `XADD conv.dead` → **дублирующая DLQ-запись** для одного
`conversionId`.

**Decisions (груминг 2026-07-05):**
Выбран вариант **at-least-once + идемпотентность потребителя** (не Lua-атомизация):
- **НЕ** атомизировать `XADD`+`XACK` (без EVAL/Lua). Принять at-least-once
  семантику DLQ-записи как контракт.
- Установлено скаутом: `conv.dead` **сейчас write-only** — ни один consumer
  (PHP/Python) его не читает (`XADD` из gateway `keydb.py:272` и воркеров
  `common/stream_consumer.py:235,491`; читателей `XREADGROUP conv.dead` нет).
  Комментарий `ws_server.py:457` «PHP читает DLQ из conv.dead» — аспирационный,
  не реализован. → сегодня фактического двойного персиста нет.
- Требование идемпотентности по `conversionId` вменяется **будущему** consumer'у
  `conv.dead` (когда он будет строиться в рамках миграции/восстановления).
- Готовый паттерн для переиспользования: `ConversionResultPersister.php:56–59` —
  status-guard (skip, если `getStatus()` уже `Completed`/`Failed`). Легаси
  `QueueResultConsumerCommand` (`conv.result`) уже идемпотентен через него.

**Acceptance Criteria:**
- [x] В docstring `add_to_dlq` (`workers/gateway/keydb.py`) явно зафиксировано:
      запись в `conv.dead` — **at-least-once**; при падении `XACK` после `XADD`
      возможен дубль записи для одного `conversionId`; дедуп — ответственность
      consumer'а.
- [x] У аспирационного комментария `ws_server.py` (`_handle_fail`, «PHP читает DLQ
      из conv.dead») добавлена заметка-требование: будущий consumer `conv.dead`
      ОБЯЗАН быть идемпотентным по `conversionId` (паттерн
      `ConversionResultPersister` status-guard).
- [x] Изменения только документационные (docstring/комментарии) — новой логики,
      Lua, тестов не требуется. Верифицировано `git diff`: `XADD`/`XACK` не тронуты.

**Найдено при:** ревью [[s1-06-reclaim-poison-dlq]] (finding #1, follow-up, не блокер).

**Status:** ready — реализовано (doc-only, keydb.py + ws_server.py docstrings),
верифицировано диффом. Ждёт финального ready→done пользователя.
