### Распределённые воркеры Stage 2 — сквозной remote rollout через WS-Gateway

**Criticality:** High

**TAGS:**
- feature
- remote-workers
- deploy
- ws-transport
- operations

**Description:**
Завершить P1 Stage 2 `distributed-workers`: обеспечить воспроизводимый запуск
отдельных контейнеров воркеров на внешнем хосте через публичный `wss://` WS-Gateway
и Symfony API, с подтверждённым сквозным выполнением задач. Карточка закрывает
именно эксплуатационный и acceptance-разрыв между уже реализованным S1 WS-транспортом,
публичным bootstrap из CNV-32 и записью в `ROADMAP.md`; новый транспорт или второй
механизм очереди не создаётся.

**Problem:**
В канбане нет отдельной implementable-карточки, которая связывает готовые части в
проверяемый Stage 2 exit. Наличие `deploy/`, remote env-шаблона и WS-кода само по
себе не доказывает, что внешний оператор может поднять выбранные воркеры, что каждый
тип получает только свой `conv.<type>`, а результаты и отказ проходят через
Symfony. Нельзя считать готовым решением прямой доступ remote-хоста к KeyDB, S3,
app-стеку или `/shared-files`.

**Impact:**
Без этой работы Stage 2 остаётся только документально заявленным: нельзя безопасно
принимать remote-хосты в пул, сравнивать их с on-server consumer'ами или выполнять
откат без риска двойного consumer ID, потерянного результата и reconnect/reclaim
штормов. CNV-32 уже покрывает публичный bootstrap и Harbor/Gist-путь, а не заменяет
сквозную проверку распределённого выполнения.

**Recommendation:**
Первая волна — ограниченный CPU-only pilot для проверки распределённого WS-транспорта;
AI/GPU/CUDA в неё не входят и оформляются отдельной последующей работой. После
разрешения остальных открытых решений реализовать rollout-гейт:

1. Зафиксировать поддерживаемую топологию первой волны и владельца каждого
   remote-хоста; для каждого типа использовать уникальный `COMPOSE_PROJECT_NAME`
   и стабильный `WORKER_ID` (для ffmpeg — отдельные `audio` и `video`).
2. Использовать только опубликованные Harbor-образы и `deploy/`/Makefile happy
   path из CNV-32. Remote-хост поднимает только выбранные CPU worker-контейнеры и
   собственный logging sidecar; `ws-gateway`, KeyDB, Symfony app, S3 и
   `metrics-exporter` остаются на основном сервере.
3. Выполнить preflight публичного `wss://` endpoint, Symfony API и Graylog,
   затем проверить handshake `ready`, регистрацию host/capabilities и отсутствие
   прямых KeyDB/S3-соединений у воркеров.
4. Провести изолированный сквозной тест для `worker-data` и `worker-image`: задачи
   идут из соответствующих `conv.<type>` через gateway к remote worker, input/result
   идут через Symfony API, gateway делает `XACK` только после terminal path; отдельно
   проверить transient reconnect, idle reclaim мёртвого воркера и идемпотентную
   повторную доставку.
5. Для CPU pilot временно разрешить одновременных consumers на on-server и `saVpn`
   только для `worker-data` и `worker-image` в их соответствующих streams. Тест
   должен подтвердить at-least-once обработку: повторная доставка и duplicate
   delivery считаются ожидаемыми и не должны приводить к двойному terminal result,
   повторному refund или конфликту владельцев output. До любого production scale-up
   отдельно зафиксировать проверку idempotency, output ownership и поведения
   in-flight задачи при rollback; drain-first политика этим решением не выбирается.
6. При rollback-триггере немедленно отключить remote consumers; remote in-flight
   entries следуют существующей gateway reclaim/idempotency семантике: gateway
   выполняет глобальный XAUTOCLAIM после idle-порога и передаёт entries любой
   eligible connection, включая on-server capacity после исключения remote.
   WS-disconnect не вызывает немедленный reclaim. Для возврата не задаётся
   bounded drain или drain SLA. Не менять production debug posture CNV-117:
   текущий dev-режим — известный контекст, не цель этой карточки.

**Dependencies:**
- `[[s1-ws-worker-transport]]` и завершённые S1-карточки транспорта, gateway,
  reclaim, relay/ack, shared WS-client и compose wiring.
- `[[CNV-32-public-worker-distribution]]` — готовые `deploy/`, Harbor/Gist и
  install/update-путь; не дублировать его scope.
- `[[validate-ai-worker]]` — рабочий AI runtime и явная проверка ограничений
  CPU/GPU; реальный GPU-прогон возможен только на доступном GPU-хосте.
- `[[stream-subscription-distribution]]`, `[[registry-01-worker-register]]` и
  `[[CNV-35-registry-08-worker-observability]]` — Streams/lag, capabilities и
  идентификация host.
- Доступ к согласованному remote-хосту, Harbor pull и production-like
  `WORKER_API_TOKEN`/`GATEWAY_INTERNAL_TOKEN` через секретное хранилище; реальные
  значения в карточке не фиксируются.

**Acceptance Criteria:**
- Определён и записан владелец первой волны rollout, список remote-хостов,
  worker types на каждом хосте и политика coexistence с on-server consumer'ами.
- Чистый remote-хост поднимается выбранным публичным или Makefile-путём из CNV-32;
  `make docker-check` проходит, серверные сервисы и KeyDB на remote-хосте не
  запускаются, образы берутся из разрешённого Harbor-тега.
- Для каждого заявленного CPU-типа (`worker-data`, `worker-image`) подтверждён
  `ready`-handshake, уникальный `workerId`/host и маршрутизация только в
  соответствующий `conv.<type>`. AI/GPU/CUDA не являются критерием первой волны
  и проверяются отдельной последующей работой.
- Сквозной smoke для согласованного CPU-набора типов подтверждает input через
  Symfony API, conversion, inline/large result path, terminal status и `XACK`;
  ни один remote worker не имеет прямого KeyDB/S3-доступа и общего volume.
- В coexistence pilot для `worker-data` и `worker-image` подтверждено, что
  on-server и `saVpn` consumers могут работать одновременно только в своих
  streams, at-least-once duplicate delivery не создаёт двойного terminal result,
  повторного refund или неоднозначного output owner. При rollback-триггере remote
  claims прекращаются немедленно, remote in-flight entries следуют существующей
  gateway reclaim/idempotency семантике: gateway выполняет глобальный XAUTOCLAIM
  после idle-порога и передаёт entries любой eligible connection, включая on-server
  capacity после исключения remote; WS-disconnect не вызывает немедленный reclaim.
  Rollout verifier после rollback подтверждает, что eligible on-server capacity
  получает entries без duplicate delivery, повторного refund или конфликта output.
  Эти проверки обязательны до production scale-up; bounded drain и drain SLA не
  утверждаются.
- Отдельно подтверждены: быстрый reconnect без немедленного reclaim, reclaim
  после per-type idle-порога для реально умершего worker'а, повторная доставка
  без двойного terminal persist/refund и отсутствие reconnect-штормов.
- В Graylog и gateway/admin наблюдаемы host/worker ID и ошибки подключения;
  rollback-сценарий проверяет немедленное отключение remote consumers; remote
  in-flight entries следуют существующей gateway reclaim/idempotency семантике:
  gateway выполняет глобальный XAUTOCLAIM после idle-порога и передаёт entries
  любой eligible connection, включая on-server capacity после исключения remote;
  WS-disconnect не вызывает немедленный reclaim. Сохраняются queue health и
  ownership; bounded drain и drain SLA не требуются.
- Документация remote deployment синхронизирована с проверенной топологией,
  а секреты, реальные endpoint override и токены не попали в git.
- Тестовые/проверочные команды, применимые к изменённому scope, зелёные:
  `make docker-check`, `make test-gateway`, `make test-python`, `make test-drift`
  и согласованный live smoke на remote-хосте; недоступные внешние проверки
  отмечены как блокирующие, а не объявлены зелёными.

**Decisions:**
- 2026-08-31: создана отдельная grooming-карточка CNV-133 после проверки активных
  и архивных карточек. Дубликат `distributed-workers` не найден.
- 2026-08-31: владелец утвердил первую волну как CPU-only pilot для проверки
  распределённого WS-транспорта и утвердил `saVpn` как target host первой волны.
  Это решение фиксирует только целевой хост: worker types, coexistence, rollback,
  capacity, доступ, Harbor policy, runtime-секреты и доступность внешних проверок
  не выбраны.
- 2026-08-31: владелец утвердил для первой волны на `saVpn` worker types
  `worker-data` и `worker-image`. AI/GPU/CUDA остаются вне первой волны; на момент
  этой записи coexistence с on-server consumer'ами, capacity/access verification,
  Harbor promotion policy, владельцы secrets/smoke и rollback/drain ещё не были
  выбраны. Coexistence отдельно утверждена следующей датированной записью.
- 2026-08-31: владелец утвердил временную coexistence policy для CPU pilot:
  одновременно допускаются on-server и `saVpn` consumers только для
  `worker-data` и `worker-image`, каждый в своём `conv.<type>` stream. Политика
  принимает at-least-once duplicate delivery как ожидаемый риск и требует до
  любого production scale-up проверить idempotency, output ownership и
  in-flight rollback behavior; двойной terminal result/refund запрещён.
  Это pilot-only решение и не определяет будущий exit/rollback или drain SLA;
  drain-first политика не добавляется молча и остаётся вопросом владельца.
- 2026-08-31: владелец утвердил rollback policy для CPU pilot, применимую только к
  coexistence `saVpn` `worker-data`/`worker-image`: при rollback-триггере remote
  consumers немедленно перестают делать claims; remote in-flight entries проходят
  существующую gateway reclaim/idempotency семантику: gateway выполняет глобальный
  XAUTOCLAIM после idle-порога и передаёт entries любой eligible connection,
  включая on-server capacity после исключения remote; WS-disconnect не вызывает
  немедленный reclaim. Duplicate terminal results/refunds и конфликт output
  ownership остаются запрещены; rollout verifier после rollback подтверждает
  queue health и ownership. Bounded drain и drain SLA не вводятся.
- 2026-08-31: repository evidence в `.claude/skills/remote-workers/hosts.md`
  описывает историческое onboarding `saVpn` от 2026-08-24 (CPU-only light host,
  ранее отмечены только `worker-data` и `worker-image`), а
  `.claude/kanban/todo/CNV-124-docker-compose-yml.md` фиксирует исторический
  compose/profile footgun на этом хосте. Это справочные записи, не текущая
  проверка: capacity, access, Harbor pull, `wss`/Symfony/Graylog reachability и
  состояние хоста для CNV-133 остаются **неверифицированными**.
- Архитектурные ограничения обязательны: public WS-Gateway + Symfony API;
  remote workers не получают прямой доступ к KeyDB/S3, app-стеку или
  `/shared-files`; remote rollout использует опубликованные Harbor-образы.
- CNV-32 считается зависимостью и traceability, а не частью scope CNV-133;
  CNV-133 не повторяет bootstrap/install-скрипт.
- CNV-117 не включается в текущий Stage 2 scope: dev-mode намеренно остаётся
  текущим рабочим режимом.
- До ответов на все вопросы выше карточка остаётся в `grooming/`; в `todo/` её
  не перемещать и политику rollout молча не выбирать.

**Execution Log:**
- Authorization: *** grooming по поручению team-lead; только документация/Kanban, без source/runtime/secrets/remote actions/push.
- Agent/zone: convertor/docs-kanban; Gate: targeted `kanban-lint.sh --repo /home/xakki/convertor CNV-133-distributed-workers-stage2.md` → 0 ошибок, 0 предупреждений; `git diff --check` → чисто. Full-board lint отдельно выявил 8 pre-existing ошибок и 1 предупреждение в `.claude/kanban/grooming/TODO.md`; CNV-133 к ним не относится.
- 2026-08-31 coexistence update: card-structure validation подтверждена для всех
  обязательных секций, status остаётся `grooming`, `todo/`-копия отсутствует,
  `git diff HEAD^ HEAD --check` чист; `kanban-lint.sh` в текущем PATH/репозитории
  недоступен, поэтому его повторный запуск для этого изменения не выполнен.
- Reviewer: не запускался; требуется отдельный read-only review перед реализацией.
- Prompt evidence: делегация `distributed-workers grooming`, model `standard`; token usage availability: unavailable; sanitized prompt summary: record owner-approved `saVpn` target host only, preserve CPU worker/coexistence/access/capacity gates.
- 2026-09-01 completed CPU pilot evidence (sanitized rollout report): main merged and pushed at `6532b923`; Harbor release tags `0.1.2` published and used instead of `latest`. Local all-CPU workers and `worker-api` were healthy. `saVpn` updated only for `worker-data` and `worker-image`; existing capabilities were preserved, VPN remained alive, and fresh provenance registrations were observed. `uBook` had all six CPU workers healthy with fresh provenance registrations; Fluent Bit was intentionally left unchanged. No secrets, token values, endpoint overrides, or image digests recorded.
- 2026-09-01 acceptance reconciliation: the first-wave topology is `saVpn` plus `uBook` CPU workers, with coexistence limited to each worker's own `conv.<type>` stream; AI/GPU/CUDA remain out of scope. Harbor promotion is represented by the published release tag `0.1.2`; runtime credentials and external-check ownership remain outside Git. The observed G4F balanced backend `HTTP 500` is an expected upstream fail-closed limitation, not a pilot rollout failure.
- 2026-09-01 lifecycle boundary: rollout evidence is recorded for handoff to `ready`; no source, runtime, deployment, secret, or push action was performed in this metadata finalization. Current repository validation: targeted and full `kanban-lint.sh` both report 0 errors and 0 warnings; no unresolved grooming questions remain.
