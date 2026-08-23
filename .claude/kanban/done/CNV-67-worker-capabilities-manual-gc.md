### Ручной make-таргет для GC worker_capabilities и live-проверка необходимости запуска

**Criticality:** Low

**TAGS:**
- tech-debt
- workers
- registry
- cleanup

**Description:**
Автоочистка `worker_capabilities` уже существует: `WorkerCapabilityGcService::run()` (`app-symfony/src/Service/Worker/WorkerCapabilityGcService.php:88-94`) удаляет строки с `last_seen` старше порога, TTL берётся из `WORKER_CAPABILITY_GC_TTL_HOURS` (`services.yaml:53`, сейчас `168` часов = 7 суток), а планировщик запускает её ежечасно (`app-symfony/src/Schedule.php:40`, `RecurringMessage::every('1 hour', WorkerCapabilityGcMessage)`). Карточка добавила безопасный синхронный ручной запуск с явным TTL.

Изначально, по диагностике 2026-08-04, предполагалось, что 6 строк хоста `uBook` остаются `disconnected` с 2026-07-30 и требуют разовой очистки. Read-only live SELECT от 2026-08-23 опроверг эту гипотезу: прежних строк нет, а текущие 8 записей `uBook` живы.

**Problem:**
Без ручного запуска GC можно было только ждать часовой тик планировщика с фиксированным env TTL. Это неудобно для контролируемой ops-операции по заранее подтверждённым кандидатам. Однако старые данные о 6 `uBook` больше не являются основанием для запуска: live inventory не обнаружила ни TTL-кандидатов, ни hardcoded junk-кандидата.

**Impact:**
Низкий — ручной таргет улучшает управляемость диагностики и обслуживания. При текущем live состоянии запуск не даёт полезного эффекта, а попытка целиться в живые `uBook` была бы ошибочной.

**Recommendation:**
Автоматическую ежечасную очистку с TTL=168ч оставить без изменений. Использовать ручной make-таргет только с явным операционным gate: свежая read-only инвентаризация, подтверждённые кандидаты и отдельное разрешение на live mutation. По состоянию на 2026-08-23 GC не требуется и не должен запускаться.

**Acceptance Criteria:**
- Существует make-таргет `make worker-capability-gc`, запускающий `WorkerCapabilityGcService` синхронно, без ожидания часового тика планировщика.
- Поддержан переопределяемый `TTL_HOURS=`; при отсутствии override сервис использует `WORKER_CAPABILITY_GC_TTL_HOURS`.
- `##`-описание таргета в Makefile — терсе, по правилу проекта.
- Часовой автоматический GC (`WorkerCapabilityGcMessage`, TTL=168ч) не изменён.
- Sanitized live inventory подтверждает 8 `alive` записей `uBook` с `last_seen` 2026-08-23 19:17:27..19:17:40 UTC и 8 `alive` записей `safin.variantgood.com` с `last_seen` 2026-08-23 19:17:22..19:17:41 UTC.
- Подтверждено 0 кандидатов старше 72ч, 0 кандидатов старше 168ч и отсутствие `instance_id = test:worker` (empty result set).
- Историческая гипотеза о 6 отключённых `uBook` отмечена как опровергнутая; live GC не требуется, не запускался и текущие живые записи не удалялись.

**Decisions:**
- 2026-08-04: автоочистка уже существует и остаётся как есть (168ч, ежечасно). Решено добавить только ручной таргет для разового/точечного запуска с явным TTL. В тот момент исходная гипотеза предполагала 6 отключённых строк `uBook`; она была основана на доступном тогда диагностическом снимке.
- 2026-08-23: live SELECT опроверг исходную гипотезу — старых 6 строк нет, текущие 8 `uBook` имеют статус `alive`. При 0 кандидатах 72ч/168ч и отсутствующем `test:worker` destructive scope снят; GC не нужен и не запускается.

**Контекст:** исходная гипотеза найдена в ходе диагностического прогона 2026-08-04 и пересмотрена по live inventory 2026-08-23.

## Журнал выполнения

- **2026-08-23 — исследование:** commit `8dd2e620` уже добавил синхронную команду `app:worker-capability:gc`, override `WorkerCapabilityGcService::run(?int $ttlHours)`, Make-таргет и функциональные тесты. Сервис без аргумента по-прежнему использует env-конфиг; `Schedule.php` по-прежнему запускает `WorkerCapabilityGcMessage` каждый час, а `app-symfony/.env` и fallback `services.yaml` сохраняют `WORKER_CAPABILITY_GC_TTL_HOURS=168`.
- **Найденный пробел:** `make -n TEST=1 worker-capability-gc` раскрывается в `--ttl-hours=`: корневой Makefile загружает только корневые env-файлы, тогда как backend-only значение находится в `app-symfony/.env`. Значит, override работает, но обязательный env default через Make-таргет не работает.
- **Scoped plan:** (1) наблюдаемым shell-регрессионным тестом закрепить default `168`, override `72`, тестовый compose/container selector и отсутствие production selector; (2) минимально передавать пустой override так, чтобы Symfony-команда брала env default из уже сконфигурированного сервиса, сохранив явный `TTL_HOURS`; (3) прогнать структурный тест, целевые PHPUnit, `cs-check` и `phpstan` только через Make с `TEST=1`; (4) не менять авто-GC/расписание и не выполнять GC против живой БД.
- **Границы на момент реализации:** не читать `.env.local`, не запускать live DB/compose и не выполнять mutation. Историческая гипотеза о 6 отключённых `uBook` оставалась неподтверждённой до отдельной read-only инвентаризации.
- **RED:** `make TEST=1 test-php FILTER=WorkerCapabilityGcCommandTest` → 1 failure из 4: вызов без `--ttl-hours` вернул status 1 вместо 0; `bash tools/tests/worker-capability-gc-make.sh` → `FAIL: default не должен передавать пустой TTL override`.
- **GREEN:** команда без option теперь вызывает `WorkerCapabilityGcService::run()` без override, а Make передаёт `--ttl-hours` только при непустом `TTL_HOURS`. `make TEST=1 test-worker-capability-gc-make test-php FILTER='WorkerCapabilityGc(Command|Service)Test'` → Make contract `ok`, PHPUnit 11 tests / 24 assertions, OK.
- **Gate:** первый `make TEST=1 cs-check phpstan` остановился на единственном formatting diff в изменённой команде; выравнивание исправлено. Повторный `make TEST=1 cs-check phpstan` → CS Fixer 0/277 исправлений, PHPStan 157/157 и migrations 26/26 без ошибок. После исправления целевые Make/PhpUnit проверки повторно зелёные.
- **Review PASS + repair (2026-08-23):** независимое ревью завершено с PASS; два low finding закрыты точным docblock для omitted env default/explicit positive override и симметричными проверками test/production-контейнеров для override dry-run. Повторно: Make contract `ok`, PHPUnit 11/24, CS Fixer 0/277, PHPStan 157/157 и migrations 26/26 — PASS.
- **Неизменённые гарантии:** `Schedule.php` и `WorkerCapabilityGcMessage` не менялись; env/fallback остаётся `168`, расписание остаётся `every('1 hour', ...)`. Фактический GC против live не запускался.
- **Integration evidence / handoff (2026-08-23):** независимое ревью — **PASS**, mandatory findings отсутствуют; repair commit `48e71df`. Targeted gates: PHPUnit 11 tests / 24 assertions; CS Fixer 0/277; PHPStan 157/157 и migrations 26/26. Полные гейты: `make docker-check` → dev ok, test ok; `make test` → exit 0 на изолированном проекте `xakki-convertor-test`; `make test-down` → exit 0; `make TEST=1 ps` → пустой список сервисов; `make build` → exit 0, все образы собраны.
- **Production backup (2026-08-23):** `make db-dump-push` завершился с exit 0; локальный файл `backup/dump.sql.gz` загружен в `s3://convertor-dump/xakki-convertor/convertor-20260823-191535.sql.gz` (upload 19.46 KiB). Exact-key round-trip через `make db-dump-pull DUMP_KEY=xakki-convertor/convertor-20260823-191535.sql.gz` подтверждён: SHA-256 до/после `12215149470f670c7d9be08f2cdf90027efa9a78313be72b187732aeca1e9bbd`, verified size 19932 bytes.
- **2026-08-23 19:17 UTC — sanitized live inventory:** 8 строк `host=uBook`, все `alive`, диапазон `last_seen` 19:17:27..19:17:40; 8 строк `host=safin.variantgood.com`, все `alive`, диапазон `last_seen` 19:17:22..19:17:41. Кандидатов `last_seen < now-72h` — 0; кандидатов `last_seen < now-168h` — 0. SELECT для `instance_id = test:worker` вернул empty result set; идентификаторы живых инстансов не фиксировались.
- **Итог live gate:** исходная гипотеза о 6 отключённых строках `uBook` опровергнута: таких строк больше нет, текущие 8 строк живы. Ручной GC не имеет ни TTL-кандидатов, ни hardcoded junk-кандидата, поэтому не требуется и не запускался. Текущие `uBook` не удалялись.

**Status:** done
