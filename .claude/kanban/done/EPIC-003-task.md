### Ручной GC реестра worker capabilities

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Контролируемая backend/ops-задача для ручного запуска TTL-очистки реестра воркеров. Историческая гипотеза о необходимости разово удалить 6 записей `uBook` опровергнута read-only проверкой живой БД: прежних отключённых строк уже нет, а текущие записи живы.

**Problem:**
Автоматическая очистка реестра существует, но ранее не было безопасного ручного Make-таргета с явным TTL для точечной операции. Изначальный scope также предполагал 6 зависших `uBook`, однако live SELECT подтвердил, что этот destructive scope устарел и не должен исполняться.

**Impact:**
Ручной управляемый GC остаётся полезным ops-инструментом на случай подтверждённых устаревших записей. Удаление текущих живых записей привело бы к повреждению актуального реестра.

**Recommendation:**
Сохранить реализованный синхронный ручной target и применять его только после отдельной read-only инвентаризации и явного подтверждения кандидатов. По состоянию на 2026-08-23 GC живой БД не требуется: TTL-кандидатов и hardcoded junk-кандидата нет; запуск не выполнялся.

**Acceptance Criteria:**
- Выполнены AC CNV-67 для синхронного ручного GC с управляемым TTL; автоматический GC 168ч не изменён.
- Read-only live inventory зафиксирована без идентификаторов инстансов: 8 `alive` записей `uBook` с `last_seen` 2026-08-23 19:17:27..19:17:40 UTC и 8 `alive` записей `safin.variantgood.com` с `last_seen` 2026-08-23 19:17:22..19:17:41 UTC.
- Подтверждено 0 кандидатов старше 72ч, 0 кандидатов старше 168ч и отсутствие `instance_id = test:worker` (empty result set).
- Зафиксировано, что live GC не требуется и не запускался; текущие записи `uBook` не удалялись.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Отдельный контролируемый эпик реализует ручной Make-таргет, но не санкционирует автономные destructive-операции в живой БД.
- Изначальная гипотеза от 2026-08-04 состояла в том, что 6 отключённых строк `uBook` требуют разовой очистки. Live SELECT от 2026-08-23 опроверг её: этих строк больше нет, текущие 8 строк `uBook` имеют статус `alive`, поэтому scope их удаления снят.
- При текущих 0 TTL-кандидатов и отсутствующем `test:worker` ручной GC не нужен и не запускается.

**Subtasks:**
- [CNV-67 — ручной GC `worker_capabilities` и live-проверка необходимости запуска](CNV-67-worker-capabilities-manual-gc.md)

**Integration checklist:**
- Зафиксировать sanitized read-only inventory и решение о необходимости запуска.
- Выполнить профильные backend/ops-проверки.
- Не запускать live GC при отсутствии подтверждённых кандидатов.

## Журнал выполнения

- **2026-08-23 — решение о старте:** EPIC-003 разрешён к автономному выполнению до стадии `ready`; финализация в `done`, push и destructive-операции с живой БД не разрешены. Утверждённая дочерняя карточка: CNV-67; зависимость EPIC-002 выполнена. Baseline ветки `main`: `da60671802532c8a13632b109237e00407c82661`. Приёмка: ручной синхронный GC с управляемым TTL, неизменный авто-GC 168ч, профильные тесты и подтверждённая неизменность посторонних записей. На старте историческая гипотеза о 6 отключённых `uBook` была припаркована до отдельного одобрения, backup и read-only инвентаризации.
- **Вывод:** EPIC-002 находится в `done`; EPIC-003 может начинаться только с единственной упорядоченной зависимости — CNV-67.
- **Зоны ответственности:** Terra реализует синхронный backend/ops-путь GC и Makefile-таргет; владелец/ops утверждает scope и любой запуск против живой БД; Luna выполняет только изолированные read-only инвентаризации и проверки, не меняя контракты, lifecycle или данные.
- **План проверки:** отдельный процесс Luna фиксирует исходные count/status и scope на безопасном стенде; Terra выполняет профильные проверки и Makefile-таргеты; отдельный процесс Luna сверяет итоговые count/status и неизменность остальных воркеров. Для живой БД любой запуск допускается только после read-only gate владельца.
- **Решение по live scope:** исходная гипотеза о 6 отключённых строках `uBook` требовала проверки, а не удаления по старым данным. Read-only live SELECT опроверг гипотезу; текущий destructive scope снят, автоматический GC с TTL=168ч остаётся без изменений.
- **2026-08-23 — child handoff:** CNV-67 прошла независимое ревью с verdict **PASS**, mandatory findings отсутствуют; repair commit `48e71df`. Дочерняя карточка штатно перемещена в `.claude/kanban/ready/CNV-67-worker-capabilities-manual-gc.md`.
- **Targeted gates:** PHPUnit 11 tests / 24 assertions; CS Fixer 0/277; PHPStan 157/157 и migrations 26/26 — зелёные.
- **Full integration gates:** `make docker-check` → dev ok, test ok; `make test` → exit 0 на изолированном проекте `xakki-convertor-test`; `make test-down` → exit 0; `make TEST=1 ps` → пустой список сервисов; `make build` → exit 0, все образы собраны.
- **Side-filed finding:** существующий out-of-scope дефект host pytest в `test-drift`, обнаруженный полным gate EPIC-003, не исправлялся; создана grooming-карточка `.claude/kanban/grooming/CNV-120-test-drift-host-pytest.md` с evidence `workers/Makefile:416`.
- **2026-08-23 — production backup:** `make db-dump-push` завершился с exit 0; локальный файл `backup/dump.sql.gz` загружен в `s3://convertor-dump/xakki-convertor/convertor-20260823-191535.sql.gz` (upload 19.46 KiB). Exact-key round-trip через `make db-dump-pull DUMP_KEY=xakki-convertor/convertor-20260823-191535.sql.gz` подтверждён: SHA-256 до/после `12215149470f670c7d9be08f2cdf90027efa9a78313be72b187732aeca1e9bbd`, verified size 19932 bytes.
- **2026-08-23 19:17 UTC — sanitized live inventory:** 8 строк `host=uBook`, все `alive`, диапазон `last_seen` 19:17:27..19:17:40; 8 строк `host=safin.variantgood.com`, все `alive`, диапазон `last_seen` 19:17:22..19:17:41. Кандидатов `last_seen < now-72h` — 0; кандидатов `last_seen < now-168h` — 0. Отдельный SELECT для `instance_id = test:worker` вернул empty result set. Идентификаторы живых инстансов не фиксировались.
- **Итог live gate:** текущий ручной GC не имеет ни TTL-кандидатов, ни hardcoded junk-кандидата. GC не требуется и не запускался; прежних 6 отключённых строк `uBook` нет, а текущие 8 живых строк удалять нельзя. Push, переход в `done` и merge не выполнялись и не разрешены.
- **2026-08-23 — финализация:** пользователь явно разрешил переход EPIC-003 и CNV-67 в `done` и локальный merge командой «donee & merge». Push не разрешён; live GC не входит в финализацию и не запускается.

**Status:** done
