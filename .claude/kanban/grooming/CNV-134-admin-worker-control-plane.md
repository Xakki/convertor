### Привилегированный control-plane API для управления воркерами

**Criticality:** High

**TAGS:**
- feature
- admin
- worker-control
- security
- async

**Description:**
Спроектировать и реализовать защищённый admin-only control plane для адресных
команд воркерам: `pause`, `start` и `auto-update`. Карточка владеет HTTP-контрактом,
авторизацией, постановкой асинхронной команды, состоянием выполнения и аудитом;
WS-доставка и применение на remote-хосте принадлежат CNV-135, кнопки и отображение
принадлежат CNV-136.

**Problem:**
Сейчас `/api/v1/admin/workers` и `/admin/workers` дают наблюдаемость, но не
существует отдельного контракта для изменения lifecycle или версии конкретного
воркера. Нельзя безопасно отличить намеренную операторскую команду от обычного
liveness-сигнала, адресовать её стабильному `(host, workerType, instanceId)` или
вернуть честный результат долгой операции.

**Impact:**
Непредсказуемая ручная остановка/обновление может затронуть не тот инстанс,
потерять in-flight job, создать duplicate consumer либо оставить оператора без
следа команды и статуса. Прямой доступ админки к KeyDB, S3 или remote-хосту
нарушит архитектуру: remote workers остаются WS-only клиентами.

**Recommendation:**
- Канонический `ROLE_ADMIN` API: `/api/v1/admin/workers/{host}/{workerType}/{instanceId}/commands`;
  каждый path segment URI-кодируется, а в записи и lookup сохраняется точная тройка
  `(host, workerType, instanceId)`. Body не может подменить target из URI. Не
  расширять user/worker API и не переиспользовать static `WORKER_API_TOKEN` как
  admin credential.
- Принимать typed action, ожидаемую версию/состояние и client idempotency key.
  Ключ имеет scope только аутентифицированного admin principal. Точный
  fingerprint включает canonical `(host, workerType, instanceId)`, action и
  canonical payload (включая release reference). Повторное использование того
  же ключа с любым отличающимся target/action/payload получает `409`, не новую
  команду; только точный replay возвращает исходные durable command ID и
  command/result.
- Использовать единый state machine во всех трёх карточках: active
  `accepted → queued → sent → running ↔ progress`; из active возможны
  `succeeded`, `failed`, `cancelled` или `timeout`. `timeout` означает истёкший
  deadline без подтверждённого результата и не является success; после исчерпания
  delivery/recovery evidence он переводится в terminal `unknown` (или в
  подтверждённый terminal result). Terminal states: `succeeded|failed|cancelled|unknown`.
  `expired` — только retention-состояние записи, не execution result; оно сохраняет
  terminal summary и idempotency tombstone/result digest.
- Писать durable command record с state, progress, timestamps, actor ID, exact
  target, requested/observed version, sanitized error и rollback/status reference;
  durable idempotency record переживает restart и expiry command record в пределах
  утверждённой retention policy. Ответ POST — `202` с command ID; GET status не
  блокируется и не маскирует `timeout`/`unknown` под success.
- CNV-134 владеет append-only command audit policy и event contract (accept/reject,
  state changes и terminal outcome); physical store, retention и cleanup owner
  остаются открытыми. Это отдельный audit trail, не CNV-133 rollout audit и не
  CNV-32 installer/deploy log. События содержат actor, action, exact target,
  correlation/command ID, reason/result, без токенов, raw payload и секретов.
  Ограничить rate/concurrency и запретить массовую команду без явно выбранного
  scope.
- Для `pause` определить гарантию: новые claims прекращаются через CNV-135,
  in-flight jobs и reconnect/reclaim не получают молча выбранную drain policy.
  `start` должен быть идемпотентен. `auto-update` должен возвращать observed
  version и rollback outcome; API не объявляет обновление успешным по факту
  отправки WS-команды.

**Acceptance Criteria:**
- Не-админ получает `403` и не создаёт command/audit record; `ROLE_ADMIN` проходит
  только через штатный JWT/admin firewall.
- Нельзя адресовать команду только по worker type: canonical route и lookup
  используют URI-кодированную тройку `host/workerType/instanceId`, сверенную с
  актуальной registration epoch/identity; неизвестная, stale или заменённая
  identity получает детерминированный `409`/`404` и не отправляет команду старому
  инстансу. Replacement обязан получить новый instance identity/epoch.
- `POST` возвращает `202` и command ID; повтор с тем же ключом в scope
  аутентифицированного admin principal и тем же fingerprint не дублирует effect
  и возвращает исходные durable command ID и command/result. Тот же ключ с
  отличающимся canonical `(host, workerType, instanceId)`, action или canonical
  payload получает `409` и не создаёт новую команду. GET различает active states,
  terminal states, `timeout`, `unknown` и `expired` tombstone с correlation ID
  без секретов; durable key/result не теряется при restart.
- В БД/хранилище аудита есть allow/deny и terminal outcome, actor и target; логи
  и ошибки редактируют bearer/token, URL credentials и произвольный payload.
- Контрактные и security-тесты покрывают не-админа, duplicate request, stale target,
  конфликт переходов, timeout/unknown outcome и отсутствие прямых KeyDB/S3 вызовов.
- Карточка не меняет `worker_capabilities` routing matrix, существующие liveness
  endpoints, CNV-69 наблюдаемость или CNV-32 bootstrap/update installer.

**Open questions:**
- Где хранится durable command/audit state: существующая MariaDB схема, отдельные
  таблицы или уже утверждённый event/audit store; кто владеет retention и cleanup?
- Какова точная drain/rollback policy для pause и auto-update: immediate stop,
  bounded drain или другой режим; что считается безопасным terminal outcome после
  разрыва WS?
- Нужны ли отдельные admin permissions помимо `ROLE_ADMIN`, и какой лимит
  одновременных команд допускается на host/worker?

**Decisions:**
- 2026-09-01: создано после inventory CNV-69/registry-07 (наблюдаемость), CNV-32
  (публичный bootstrap/update путь) и CNV-133 (WS-only remote rollout); дубликат
  control-plane API не найден.
- 2026-09-01: команда только асинхронная, адресная по `(host, workerType, instanceId)`;
  canonical URI/lookup включает host, а stale/replaced identity отклоняется по
  registration epoch/fencing; idempotency, audit trail, admin authorization и
  честные rollback/status semantics обязательны.
- 2026-09-01: для CNV-134 принят общий command contract: active
  `accepted|queued|sent|running|progress|timeout`, terminal
  `succeeded|failed|cancelled|unknown`; `expired` — retention tombstone с
  сохранёнными summary и idempotency result. Idempotency key scoped только к
  аутентифицированному admin principal; fingerprint включает canonical
  `(host, workerType, instanceId)`, action и canonical payload. Любое отличие
  target/action/payload при том же ключе даёт `409`; только точный replay
  возвращает исходные durable command ID и command/result. Физический
  store/retention owner остаются открытыми.
- 2026-09-01: CNV-134 владеет command audit policy/event contract; audit отделён
  от CNV-133 rollout audit и CNV-32 installer/deploy log. Remote delivery не
  реализуется через KeyDB/S3 или direct host access; единственный transport
  boundary — CNV-135.

**Dependencies:**
- Предшествует CNV-135: API command schema и lifecycle states должны быть зафиксированы
  до wire-handler.
- Предшествует CNV-136: UI отображает только этот status contract и не изобретает
  client-side lifecycle.
- Использует существующие admin route/firewall и worker identity из CNV-69/registry-07;
  не дублирует их страницу или capability collection.

**Execution Log:**
- 2026-09-01: inventory выполнен; runtime/source/secrets/deploy не изменялись.
- Prompt evidence: model tier `standard`; token usage availability: unavailable;
  sanitized prompt summary: groom admin worker auto-update and pause/start into
  secure non-duplicated API/transport/UI cards; Agent docs-kanban.
- Validation: targeted Kanban lint — 3 cards checked, 0 errors, 0 warnings;
  full-board Kanban lint — 69 cards checked, 0 errors, 0 warnings; `git diff --check`
  passed. Runtime/source/secrets/deploy не изменялись.
- 2026-09-01: decision repair validated: principal-scoped idempotency key,
  canonical target/action/payload fingerprint and `409` conflict semantics
  согласованы с CNV-135/CNV-136; targeted/full-board lint и `git diff --check`
  повторно прошли без ошибок.
