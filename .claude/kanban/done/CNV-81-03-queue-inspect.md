### Детальная диагностика очередей (`queue-inspect`)

**Criticality:** High

**TAGS:**
- tech-debt
- makefile
- keydb
- observability

**Description:**
Вторая, зависящая от CNV-81-02 подзадача диагностики. Происхождение: CNV-63
«`make queue-status` вводит в заблуждение (XLEN вместо backlog); плюс детальный
`queue-inspect`». Она добавляет отдельный подробный инструмент и не расширяет
краткую сводку `queue-status` до нечитабельного дампа.

**Problem:**
Для расследования pending, idle и DLQ сейчас требуется вручную составлять
`keydb-cli`-команды внутри контейнера. Нельзя воспроизводимо ограничить
диагностику одним stream, получить группы, записи pending и DLQ через Makefile.

**Impact:**
Оператор медленно и по-разному собирает данные, а полезные подробности
смешиваются с повседневной сводкой статуса.

**Recommendation:**
Добавить в `workers/Makefile` `queue-inspect` с необязательным
`STREAM=conv.<type>`. Без фильтра команда обходит только допустимые `conv.*`;
с фильтром валидирует stream по этому allowlist и диагностирует только его.
Для выбранных потоков выводить `XINFO GROUPS` и детальные pending-записи
(ID, consumer, idle, delivery count); отдельно выводить содержимое
`conv.dead`. Использовать `keydb-cli` через существующий Makefile-путь, без
нового `scripts/` и без изменений очередей.

**Acceptance Criteria:**
- Существует `make queue-inspect` с кратким `##`-описанием на русском языке.
- Без `STREAM` команда печатает `XINFO GROUPS` и подробный XPENDING для
  каждого разрешённого `conv.*`; каждая pending-запись содержит ID, idle и
  delivery count.
- `make queue-inspect STREAM=conv.<type>` ограничивает вывод указанным
  разрешённым потоком; неверное значение завершается понятной ошибкой и не
  выполняет произвольную команду/stream.
- Независимо от фильтра команда печатает содержимое `conv.dead` с явной
  подписью DLQ и не называет `conv.result.dead` текущим DLQ.
- Пустые группы, отсутствие pending и пустой DLQ выводятся явно без ошибки;
  команда не изменяет KeyDB (`XACK`, `XDEL`, `XTRIM` и другие мутации
  отсутствуют).
- На dev-стенде вручную проверены общий режим и `STREAM=conv.<type>`;
  результат внесён в execution log.
- Выполнены относящиеся проверки Makefile/линтеры и `make build`; команды и
  результаты записаны в execution log.

**Decisions:**
- 2026-08-14: подробный inspect отделён от CNV-81-02, чтобы ежедневный
  `queue-status` оставался короткой правдивой сводкой, а тяжёлый вывод был
  явной диагностической операцией.
- 2026-08-14: inspect только читает состояние KeyDB; любые действия над DLQ
  остаются вне этой карточки.

**Execution order:**
- Выполнять третьей, только после готовности CNV-81-02: она опирается на
  зафиксированную терминологию pending, XLEN и `conv.dead`.
- Перед началом перечитать CNV-63; исходную карточку не изменять.

**Лог выполнения:**
- 2026-08-14: добавлен `queue-inspect` в `workers/Makefile`: один read-only Lua `EVAL` находит только ключи `conv.*` типа `stream`, исключает `conv.dead`, сортирует список и проверяет `STREAM` по найденному allowlist до любой инспекции выбранного ключа. Для каждого выбранного stream выводятся группы и полный постраничный `XPENDING RANGE` (ID, consumer, idle-ms, deliveries); `DLQ conv.dead` через `XRANGE` выводится независимо от фильтра. Пустые группы, PEL, отсутствующий/пустой DLQ печатаются явно. Команда использует существующий password-safe шаблон `keydb-cli`.
- 2026-08-14: статические проверки прошли: `make -n queue-inspect`; `make help | grep -F queue-inspect`; скан таргета подтвердил отсутствие `XACK`, `XDEL`, `XTRIM`, `XADD`, `EVALSHA`, `SCRIPT` и `conv.result.dead`.
- 2026-08-14: `make docker-check` успешно (`dev: ok`, `test: ok`). На доступном dev-стенде успешно выполнены `make queue-inspect` (найдены `conv.ai`, `conv.audio`, `conv.data`, `conv.document`, `conv.image`, `conv.result`, `conv.video`; во всех `convertor` PEL пуст), `make queue-inspect STREAM=conv.document` (только выбранный stream плюс `DLQ conv.dead: empty`) и ошибочная проверка `make queue-inspect STREAM=conv.not-permitted` (понятная ошибка, exit 2). `make build` успешно завершился.
- 2026-08-14: устранены блокирующие замечания review к `56cce37`: `STREAM` больше не подставляется Makefile в shell-исходник. Значение экспортируется как runtime-окружение, читается рецептом в `requested` и передаётся в `keydb-cli EVAL` отдельным quoted-аргументом; Lua allowlist обнаруженных `conv.*` сохранён. Проверка `make -n queue-inspect STREAM='conv.document"; touch /tmp/queue-inspect-reviewer-payload; #'` не содержит payload в shell-команде и не создала marker-файл.
- 2026-08-14: после `docker exec ... keydb-cli EVAL` рецепт немедленно сохраняет `$?` и возвращает его до любого вывода/разбора результата; sentinel `__QUEUE_INSPECT_INVALID__` по-прежнему даёт exit 2. Повторные dev-проверки прошли: `make queue-inspect` — все семь разрешённых stream и пустой DLQ (exit 0); `make queue-inspect STREAM=conv.document` — только выбранный stream + пустой DLQ (exit 0); `make queue-inspect STREAM=conv.not-permitted` — понятная ошибка (exit 2); hostile filter — sentinel (exit 2), marker-файл отсутствует. Инъекция сбоя `make KEYDB_CONT=queue-inspect-missing-container queue-inspect` не напечатала stdout target-а и завершилась ошибкой до обработки `out`. Дополнительно прошли `make help | grep -F queue-inspect`, `git diff --check`, `make docker-check` (`dev: ok`, `test: ok`) и `make build`.
- 2026-08-14: независимое review объединённых коммитов `56cce37` и `f732cbf` прошло без блокирующих замечаний; карточка переведена в `ready`.
