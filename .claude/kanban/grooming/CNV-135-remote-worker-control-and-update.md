### WS-механизм pause/start и auto-update для remote workers

**Criticality:** High

**TAGS:**
- feature
- remote-workers
- ws-transport
- lifecycle
- deploy

**Description:**
Спроектировать и реализовать серверно-remote механизм доставки и применения
адресных команд `pause`, `start` и `auto-update` через существующий публичный
WS-Gateway. Карточка владеет gateway protocol, target binding, worker-side
lifecycle/update executor, reconnect/reclaim safety и подтверждением версии;
admin API принадлежит CNV-134, UI — CNV-136.

**Problem:**
Remote workers по архитектуре являются исходящими WS-only клиентами: они не имеют
прямого доступа к KeyDB/S3 и на remote-хосте не запускаются Symfony, gateway или
metrics-exporter. Существующий `re-register` — диагностический control frame, а
`deploy/install.sh update` из CNV-32 — операторский bootstrap-путь, не адресуемая
команда из админки. Нового безопасного lifecycle/update протокола нет.

**Impact:**
Непроверенный control frame может уйти другому инстансу, остановить consumer во
время обработки, повторить update после reconnect или объявить rollback успешным
без подтверждения фактической версии. Ошибка может оставить remote worker
подключённым, но не принимающим jobs, либо создать reconnect/reclaim storm.

**Recommendation:**
- Расширить существующий WS control protocol отдельными версиями/envelope для
  command, ack/progress/result; не смешивать их с `job`, `ping`, `pong` или
  `re-register`. Gateway маршрутизирует только по точному canonical target
  `(host, workerType, instanceId)` и correlation/command ID; stale connection
  получает отказ. Host из envelope обязан совпасть с host регистрации и не может
  быть выведен из одного worker type/instance ID.
- Gateway и worker используют ровно state machine CNV-134: `accepted → queued →
  sent → running ↔ progress`, затем `succeeded|failed|cancelled|unknown`; при
  истечении deadline сначала публикуется `timeout` без ложного success, а после
  recovery/reconciliation он становится `unknown` либо подтверждённым terminal
  result. `expired` — лишь retention tombstone. Дедуплицировать command ID и
  idempotency fingerprint при reconnect/restart, сохраняя at-least-once delivery
  без двойного terminal effect. Idempotency key scoped только к
  аутентифицированному admin principal; fingerprint включает canonical
  `(host, workerType, instanceId)`, action и canonical payload. Только exact
  replay возвращает исходные durable command/result; повторное использование
  ключа с любым отличающимся target/action/payload получает `409` и не
  исполняется. Никаких новых direct KeyDB/S3 каналов для worker.
- `pause` должен убрать инстанс из claims до подтверждения согласно выбранной
  drain policy; `start` восстанавливает claims только после readiness. Указать
  поведение in-flight job, disconnect, reconnect, reclaim и crash recovery.
- `auto-update` должен запускать только allowlisted immutable release reference,
  без shell-команды или URL/registry input от admin request; updater получает
  минимальные секреты через существующий host/deploy mechanism, не логирует их,
  проверяет image digest/signature (если policy выбрана), health/readiness и
  сообщает old/new version. Rollback должен быть явным, ограниченным и проверяемым.
- Наблюдаемость: structured events с host/worker/command ID и redacted outcome;
  метрики pending/running/timeout/rollback. Протокол совместим с текущими
  старшими worker builds: неизвестная команда безопасно отклоняется/логируется,
  не рвёт job loop.

**Acceptance Criteria:**
- Для каждого command envelope проверяются protocol version, command ID,
  URI-derived target `(host, workerType, instanceId)`, registration epoch, expiry,
  allowed action и одноразовое/идемпотентное применение; команда для другого,
  stale или replaced host/instance не доставляется.
- Тесты подтверждают pause/start claims semantics, in-flight/reconnect/reclaim
  поведение, duplicate command после reconnect и отсутствие duplicate terminal
  result/refund/output ownership conflict; exact replay того же ключа возвращает
  исходный durable command/result, а отличие canonical target/action/payload
  при том же ключе получает `409` без повторного исполнения.
- Update path принимает только утверждённый release reference, не выполняет
  произвольный shell/URL, публикует observed old/new version, readiness и
  rollback/unknown outcome; при неуспехе не выдаёт ложный success.
- Remote worker по-прежнему имеет только исходящий WSS к gateway и HTTPS к
  Symfony API; прямые KeyDB/S3 подключения, inbound listener и общий volume
  отсутствуют и проверяются contract/security tests.
- Gateway/worker logs redact tokens, credentials, command payload and private
  URLs; audit correlation ID сквозной с CNV-134.
- CNV-32 остаётся владельцем bootstrap/install/update для внешнего оператора;
  эта карточка добавляет только authenticated admin-triggered lifecycle path и
  не дублирует публичный gist/Harbor publication workflow.

**Open questions:**
- Кто фактически выполняет update на remote host: worker process, host-level
  supervisor или отдельный trusted updater; как доставляется release allowlist и
  кто владеет registry credentials?
- Выбран ли immutable digest/signature verification и какая rollback policy:
  previous digest, last-known-good или ручное вмешательство; допустим ли restart
  при in-flight job?
- Какая точная pause policy (immediate claim stop, drain window, rejection of
  new jobs) и какой deadline/timeout для reconnect/reclaim?

**Decisions:**
- 2026-09-01: создано после проверки `docs/workers-remote-deploy.md`,
  `workers/gateway/ws_server.py`, `workers/common/ws_client.py`, CNV-32 и CNV-133;
  существующий `re-register` признан диагностическим и не заменяет control plane.
- 2026-09-01: WS-Gateway остаётся единственным queue boundary; remote workers не
  получают прямой KeyDB/S3 доступ, а target identity всегда host-specific и
  проверяется вместе с registration epoch для fencing stale/replaced instance.
- 2026-09-01: CNV-135 принимает state machine CNV-134 без локальных вариантов:
  `accepted|queued|sent|running|progress|timeout` → terminal
  `succeeded|failed|cancelled|unknown`; `expired` — только retention tombstone.
  Idempotency key scoped только к аутентифицированному admin principal, а
  fingerprint включает canonical `(host, workerType, instanceId)`, action и
  canonical payload. Exact replay возвращает исходные durable command/result;
  любое отличие target/action/payload при том же ключе даёт `409` и не
  переисполняется при reconnect/restart.
- 2026-09-01: update, pause и start — asynchronous commands с подтверждаемыми
  terminal/unknown outcomes; drain, updater ownership, release verification и
  rollback policy оставлены владельцу.

**Dependencies:**
- Зависит от CNV-134: принимает только его command schema, authorization decision,
  command state и audit correlation.
- Предшествует CNV-136: UI не может считать action успешным до protocol status.
- Не заменяет CNV-32 public bootstrap/update, CNV-133 Stage 2 rollout или CNV-69/
  registry-07 worker listing/observability.

**Execution Log:**
- 2026-09-01: inventory выполнен; runtime/source/secrets/deploy не изменялись.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: groom secure WS-only remote worker pause/start/update
  mechanism with target identity, idempotency, rollback and status semantics;
  Agent docs-kanban.
- Validation: targeted Kanban lint — 3 cards checked, 0 errors, 0 warnings;
  full-board Kanban lint — 69 cards checked, 0 errors, 0 warnings; `git diff --check`
  passed. Runtime/source/secrets/deploy не изменялись.
- 2026-09-01: decision repair validated: principal-scoped idempotency key,
  canonical target/action/payload fingerprint and `409` conflict semantics
  согласованы с CNV-134/CNV-136; targeted/full-board lint и `git diff --check`
  повторно прошли без ошибок.
