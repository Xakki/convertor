### Drift в docs/queue-streams.md — §2/§3 описывают до-S1 модель (воркеры сами трогают KeyDB)

**Criticality:** Medium

**TAGS:**
- tech-debt
- documentation
- gateway
- queue

**Описание:**
`docs/queue-streams.md` содержит устаревшие описания архитектуры воркеров и управления очередями. Две критические секции всё ещё описывают до-S1 модель, где воркеры обращаются к KeyDB напрямую, что противоречит текущей реальной реализации.

**Проблема:**
- Строка ~46: «Workers create the group on startup: `XGROUP CREATE...`» — описывает, как воркер самостоятельно создаёт consumer-group на старте.
- Строка ~78: «the main loop calls `_reclaim_stuck()`» — описывает рецlaim stuck-сообщений на уровне воркера.
- Оба этих процесса (`XGROUP`, `XAUTOCLAIM`, reclaim) теперь целиком находятся в gateway, а не в воркерах.

**Факт текущей реализации:**
Эти операции (`XGROUP CREATE`, `XAUTOCLAIM`, reclaim) реализованы в:
- `workers/gateway/keydb.py` — группы, чтение/запись потоков
- `workers/gateway/reclaim.py` — логика reclаim
- `workers/common/ws_client.py` — WS-клиент воркера

Инвариант CLAUDE.md: **только gateway читает/пишет Streams** (`XREADGROUP`/`XACK`/`XAUTOCLAIM`), воркеры — WS-клиенты и KeyDB вообще не трогают.

**Противоречие:**
Эти две секции конфликтуют с уже поправленной секцией про consumer-name (строки ~56-64), которую обновили при правке auto-WORKER_ID. Там указано правильно, что имя consumer = стабильный `worker_id` воркера дословно (handshake `workerId`, без PID и без префиксов), передаётся в gateway как есть (`consumer = session.worker_id`), но §2/§3 всё ещё говорят о воркере как об инициаторе `XGROUP`/reclaim.

**Задача:**
Переписать §2 и §3 в `docs/queue-streams.md` чтобы корректно описать текущую WS-транспорт модель:
- Воркер НЕ создаёт group и НЕ делает reclaim — это целиком ответственность gateway
- Воркер подключается к gateway по WS и получает задачи
- Gateway — единственный читатель Streams (`XREADGROUP`/`XACK`/`XAUTOCLAIM`)
- Привести текст в соответствие с источниками кода (см. файлы выше)

**Контекст:**
Найдено при реализации auto-WORKER_ID + baked WORKER_TYPE=ai для AI-воркера (эта же ветка обновила consumer-name секцию, но оставила §2/§3 без изменений). Документация отстала от кода.

**Status:** done.
