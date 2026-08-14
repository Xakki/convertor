### Правдивая краткая сводка очередей (`queue-status`)

**Criticality:** High

**TAGS:**
- tech-debt
- makefile
- keydb
- observability

**Description:**
Первая из двух упорядоченных подзадач диагностики очередей. Происхождение:
CNV-63 «`make queue-status` вводит в заблуждение (XLEN вместо backlog); плюс
детальный `queue-inspect`». Эта карточка ограничена короткой сводкой статуса;
детальный вывод выделен в CNV-81-03.

**Problem:**
Текущий `make queue-status` показывает XLEN `conv.*` как единственный заметный
показатель. XLEN накопителен и не равен backlog, поэтому рост старых записей
выглядит как затык даже при пустом pending. Оператор не видит первичную
метрику ожидания задач и размер текущего DLQ.

**Impact:**
Ложная интерпретация XLEN провоцирует несуществующие инциденты и замедляет
диагностику настоящих задержек.

**Recommendation:**
Изменить только `workers/Makefile`: для каждого `conv.*` вывести первым
pending активной consumer-group и max-idle самой старой pending-записи; XLEN
оставить вторичной явно подписанной накопительной метрикой, не backlog.
Добавить размер `conv.dead` как DLQ. Повторно использовать семантику и вызовы,
которые уже применяет `workers/metrics_exporter/exporter.py`:
`xinfo_groups`, `xpending_range(..., count=1)` и `xlen`. Не создавать
каталог `scripts/` и не называть `conv.result.dead` текущим DLQ.

**Acceptance Criteria:**
- `make queue-status` выводит по каждому `conv.*` pending consumer-group как
  первый показатель и max-idle (мс) самой старой pending-записи; отсутствие
  группы или pending обрабатывается явно и без ошибки.
- XLEN остаётся отдельной вторичной колонкой/полем с ясной пометкой
  «накопительное, не backlog»; вывод нельзя ошибочно прочитать как backlog.
- Вывод содержит размер `conv.dead` (XLEN) с подписью DLQ.
- Команда не ссылается на `conv.result.dead` как на текущий DLQ.
- В `workers/Makefile` есть краткое `##`-описание таргета на русском языке.
- На dev-стенде вручную подтверждено: при `pending=0` и растущем XLEN сводка
  не сообщает ложный backlog; результат внесён в execution log.
- Выполнены относящиеся проверки Makefile/линтеры и `make build`; команды и
  результаты записаны в execution log.

**Decisions:**
- 2026-08-14: CNV-63 разделена: этот этап определяет краткий контракт статуса,
  а полный XINFO/XPENDING/DLQ-дамп сознательно перенесён в CNV-81-03.
- 2026-08-14: источник истины для текущего DLQ — `conv.dead`; устаревшее имя
  `conv.result.dead` не использовать.

**Execution order:**
- Выполнять второй, после CNV-81-01 и перед CNV-81-03.
- Перед началом перечитать CNV-63; исходную карточку не изменять.

## Execution Log

- 2026-08-14: Проверены `AGENTS.md`, эта карточка, исходная CNV-63 и семантика `workers/metrics_exporter/exporter.py`: exporter использует группу `convertor`, `XINFO GROUPS`, `XPENDING RANGE ... COUNT 1` для idle самой старой PEL-записи и `XLEN conv.dead` для DLQ.
- 2026-08-14: Обновлён только `workers/Makefile`: `queue-status` сортирует и читает stream-ключи `conv.*`, исключает `conv.dead` из обычных строк, выводит первыми `pending[convertor]` и `oldest-pending-idle-ms`, затем `cumulative-xlen-not-backlog`; отсутствие группы обозначается явно, пустой PEL даёт idle `0`. Отдельно выводится `DLQ conv.dead: xlen=…`.
- 2026-08-14: Ручная dev-проверка `make queue-status` успешна: `conv.document` имел `pending[convertor]=0` при `cumulative-xlen-not-backlog=48` (также ненулевые XLEN остальных stream), поэтому вывод не сообщает ложный backlog; `DLQ conv.dead: xlen=0`.
- 2026-08-14: Проверки успешно: `make -n queue-status`, `make help | grep -F 'Кратко показать pending/idle'`, `git diff --check`, grep отсутствия `conv.result.dead` в `workers/Makefile`; полный `make build` завершился успешно (образы worker, gateway, exporter, AI и db-dump-cron собраны).
- 2026-08-14: Независимый review коммита `25e9220` пройден без блокирующих замечаний.
