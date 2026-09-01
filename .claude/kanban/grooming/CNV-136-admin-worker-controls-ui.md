### UI-контролы admin workers для pause/start и auto-update

**Criticality:** Medium

**TAGS:**
- feature
- admin
- frontend
- worker-control
- ux

**Description:**
Добавить в существующую `/admin/workers` витрину адресные кнопки `Pause`, `Start`
и `Auto-update` с подтверждением действия, асинхронным статусом и безопасным
отображением результата. Карточка владеет только UI/UX и интеграцией с CNV-134;
control-plane и WS/update execution принадлежат CNV-134/CNV-135.

**Problem:**
CNV-69/registry-07 уже дают список, host accordion, liveness, version и matrix,
но оператор не может инициировать lifecycle/update действие. Кнопка без ясного
target, pending state, duplicate protection и rollback/unknown display создаст
опасный one-click control и будет расходиться с серверной семантикой.

**Impact:**
Администратор может случайно воздействовать на соседний worker, повторить команду
при медленном ответе или принять `sent` за успешный update. Скрытый/неверный
permission state может раскрыть control surface не-админу, а сырой error способен
показать token/endpoint.

**Recommendation:**
- Встроить controls в уже существующий host → worker accordion CNV-69, переиспользуя
  canonical worker identity `(host, workerType, instanceId)` и version/status; не
  копировать list/aggregation provider и не менять queue UI.
- Перед отправкой показывать target и destructive-action confirmation; отправлять
  только typed command + client idempotency key. Пока command
  `accepted|queued|sent|running|progress|timeout`, блокировать повтор той же
  операции, но позволять безопасный refresh/status poll.
- Показывать раздельно desired action, canonical target, command state, observed
  liveness/version, terminal `succeeded|failed|cancelled|unknown` и rollback
  result; `timeout` показывать как неопределённость до reconciliation, а `expired`
  — только как retention tombstone. Ни один из них не маркировать success.
- Controls видимы/доступны только при admin authorization, но enforcement остаётся
  на API. Ошибки локализованы по allowlisted error code; raw payload, token и
  private URL не попадают в DOM, telemetry или notifications.
- UI принимает и сохраняет idempotency key, scoped только к authenticated admin
  principal, вместе с command ID. Fingerprint включает canonical
  `(host, workerType, instanceId)`, action и canonical payload: exact replay
  восстанавливает исходные durable command/result, а reuse того же ключа с
  отличающимся target/action/payload показывает `409` и не выполняет POST
  повторно.
- Poll/refresh сохраняет открытые host/worker sections и не сбрасывает pending
  state; после reconnect/reload UI восстанавливает command status по ID, а не
  повторяет POST. Неподтверждённый результат требует явного retry с новым или
  тем же idempotency policy, не silent повтор.

**Acceptance Criteria:**
- Админ видит controls на каждой конкретной worker row с host/type/instance ID;
  не-админ не получает control API вызовов и не видит кнопки после auth state.
- Confirmation показывает точный target и действие; double-click, refresh и
  повторный render не создают duplicate command. Exact replay idempotency key
  восстанавливает исходные durable command/result, а отличие canonical
  target/action/payload при том же ключе отображается как `409` без повторного
  POST.
- UI корректно отображает `202` и active `accepted|queued|sent|running|progress`,
  terminal `succeeded|failed|cancelled|unknown`, а также `timeout` и retention
  `expired`; `sent`/`timeout` не маркируются как completed/success, rollback
  отображается отдельно.
- После 15-секундного refresh CNV-69 открытые accordion sections, version/status
  и pending command остаются согласованными; reload восстанавливает status без
  повторного действия.
- Browser/functional tests покрывают admin/non-admin, stale target, disabled
  control, duplicate click, timeout/unknown, redacted error и pause/start/update
  status transitions.
- Страница не дублирует CNV-69 aggregation, CNV-133 rollout, CNV-32 public
  installer или WS protocol; UI остаётся thin client серверного contract.

**Open questions:**
- Нужна ли отдельная UX confirmation/reason для auto-update и pause, кто имеет
  право видеть rollback details и какие локализованные тексты/коды обязательны?
- Где показывать command history/audit link и сколько времени держать polling;
  что делает UI после terminal timeout при неизвестном фактическом результате?
- Какие действия доступны для seed/null-host/stale/disconnected строк и должен ли
  UI запрещать start/update до свежего handshake?

**Decisions:**
- 2026-09-01: создано после inventory CNV-69/registry-07 и текущего
  `templates/admin/workers.html.twig`; существующий список/accordion не заменяется.
- 2026-09-01: UI — только thin client CNV-134; server-side `ROLE_ADMIN`,
  canonical host-inclusive target, idempotency key scoped только к
  authenticated admin principal, fingerprint из canonical
  `(host, workerType, instanceId)`, action и canonical payload, async state
  machine, audit и rollback semantics обязательны. Exact replay возвращает
  исходные durable command/result; reuse ключа при отличии target/action/payload
  получает `409`.
- 2026-09-01: UI использует единые states CNV-134: active
  `accepted|queued|sent|running|progress|timeout`, terminal
  `succeeded|failed|cancelled|unknown`; `expired` отображается только как
  retention tombstone, а `timeout` не считается success.
- 2026-09-01: controls не появляются для seed/null-host без явного target policy;
  окончательные disabled/confirmation/history решения открыты и не выбираются молча.

**Dependencies:**
- Зависит от CNV-134: API routes, command schema, state/error contract и permission.
- Зависит от CNV-135: protocol statuses, observed version/liveness и rollback result.
- Расширяет CNV-69/registry-07 только UI-поверхность; не меняет их worker listing,
  host aggregation или polling semantics.

**Execution Log:**
- 2026-09-01: inventory выполнен; runtime/source/secrets/deploy не изменялись.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: groom Russian admin UI controls for secure worker
  pause/start/update without duplicating existing worker page; Agent docs-kanban.
- Validation: targeted Kanban lint — 3 cards checked, 0 errors, 0 warnings;
  full-board Kanban lint — 69 cards checked, 0 errors, 0 warnings; `git diff --check`
  passed. Runtime/source/secrets/deploy не изменялись.
- 2026-09-01: decision repair validated: principal-scoped idempotency key,
  canonical target/action/payload fingerprint and `409` conflict semantics
  согласованы с CNV-134/CNV-135; targeted/full-board lint и `git diff --check`
  повторно прошли без ошибок.
