### uBook/project-owned Fluent Bit sidecar: потери доставки GELF и границы ответственности

**Criticality:** High

**TAGS:**
- bug-fix
- tech-debt
- logging
- fluent-bit
- graylog
- ubook

**Description:**
Разобрать и устранить потери доставки логов из uBook через project-owned Fluent Bit
sidecar в Graylog GELF. После rollout зафиксированы активные retries `2512`,
failed `163`, dropped `2595` на POST `/gelf` к `log.variantgood.com:443`.
DNS, TLS и TCP connect до приёмника в проверке здоровы; симптом похож на медленное
подтверждение POST и обратное давление downstream. Источник сигнала — runtime
метрики и логи активного uBook sidecar; точная идентичность unit/container и
метрик пока не верифицирована. Proxy и Graylog ingress — downstream scope,
а не владелец uBook collector.

**Problem:**
- Приёмник или стоящий перед ним proxy не даёт достаточно быстрого ACK, а текущая
  доставка теряет сообщения после исчерпания retry.
- Не зафиксировано, кому принадлежат uBook sidecar, его конфигурация, restart
  policy, метрики и алерты; shared proxy/Graylog ingress остаются отдельной
  downstream зоной владельцев observability.
- Нет проверенного bounded filesystem buffer: при временной недоступности
  Graylog нужно сохранять сообщения на диске, но не разрешать неограниченный рост
  и не создавать риск заполнения диска.
- Без раздельной диагностики proxy/Graylog ingress нельзя отличить медленный
  upstream, лимит тела/частоты, таймаут или backpressure от дефекта collector.

**Impact:**
Высокий: часть межсервисных и эксплуатационных логов uBook теряется (`2595` dropped),
что ухудшает расследование инцидентов и может скрыть ошибки rollout. Изменение
runtime, конфигурации или политики инфраструктуры этой карточкой не выполняется;
карточка фиксирует требование на согласованную ops-работу.

**Recommendation:**
Владельцу uBook project-owned sidecar совместно с владельцами proxy/Graylog провести
сквозную диагностику `/gelf`: latency до ACK, HTTP-коды, request/response timeout,
лимиты и очереди на proxy и Graylog, а также корреляцию входных/выходных счётчиков
Fluent Bit sidecar по проекту. Затем выбрать и внедрить подтверждённую политику
bounded filesystem buffering (явные лимиты объёма и диска, поведение при полном
буфере, recovery и метрики), не скрывая drops. Оформить owner/runbook/alerting
для uBook sidecar; uBook не должен подменять диагностику или менять shared
proxy/Graylog ingress локальным обходом.

**Acceptance Criteria:**
- Документирован source-of-truth для uBook project-owned Fluent Bit sidecar:
  хост, unit/container, конфигурация, владелец, restart/update path и канал
  эскалации; proxy/Graylog ingress указаны как downstream dependencies.
- Для тестового окна есть корреляция Fluent Bit → proxy → Graylog GELF с
  измеренными ACK latency, HTTP-кодами, timeout/retry/failed/dropped и причиной
  backpressure; отдельно подтверждено, что DNS/TLS/connect не являются причиной.
- Определена и согласована bounded filesystem buffer policy: абсолютный/процентный
  лимит, минимум свободного диска, retention, поведение при заполнении и
  наблюдаемые counters; неограниченный buffer и silent drop запрещены.
- После реализации ops-изменения контролируемая нагрузка с uBook доставляется
  без необъяснимых drops; при искусственной задержке downstream видны bounded
  retries/buffer и явный alert, а после восстановления происходит drain без
  повторной потери сверх принятой политики.
- Graylog/proxy ingress diagnostics и collector runbook обновлены; секреты и
  реальные override из untracked env не попадают в репозиторий.
- Не изменяются исходники приложения, project Make targets или runtime/config до
  явного решения владельцев; любое изменение shared infrastructure проходит
  отдельным согласованным ops-релизом с rollback и проверкой.

**Open questions:**
- Кто принимает решение и несёт эксплуатационное владение shared host-level
  Fluent Bit: владелец хоста/ops, команда Graylog или отдельный observability owner?
- Какой proxy и какой Graylog ingress обслуживает `log.variantgood.com:443/gelf`,
  и кто даст read-only доступ к их latency/status/queue метрикам?
- Каковы допустимые границы buffer/drop policy: максимальный объём/время на диске,
  минимум свободного места, допустимая потеря при полном буфере и ответственное
  лицо за согласование этих SLO?
- Нужен ли отдельный rollout для uBook и остальных проектов либо единое изменение
  shared collector; кто подтвердит окно, нагрузочный тест и rollback?

**Decisions:**
- 2026-09-01: создана отдельная grooming-карточка после runtime-наблюдения;
  CNV-17/CNV-57/CNV-122 и `fluent-logging-setup` признаны смежными завершёнными
  работами (bind, crash-loop, подключение и базовая схема), но не дубликатом
  текущей проблемы downstream drops и shared ownership.
- 2026-09-01: scope ограничен диагностикой и согласованным ops-дизайном;
  source code, project-specific Make targets, секреты и самостоятельные runtime
  изменения исключены.
- 2026-09-01: card остаётся в `grooming/`, пока владельцы не ответят на вопросы
  о shared collector, ingress-доступе, bounded buffer/SLO и rollout/rollback.

**Execution Log:**
- 2026-09-01: выполнен поиск активных и архивных карточек по Fluent Bit,
  Graylog, GELF, logrotate и logging; точного или split-дубликата не найдено.
- 2026-09-01: выделены смежные записи CNV-17, CNV-57, CNV-122 и
  `fluent-logging-setup`; новая карточка не расширяет их завершённый scope.
- 2026-09-01: ID CNV-138 выделен через `kanban-new.sh --prefix CNV`; изменены
  только Kanban metadata/card paths. Runtime/config/source не трогались.
- 2026-09-01 evidence metadata (currently observed/unverified, not a reproducible
  claim): reported counters were retries `2512`, failed `163`, dropped `2595`.
  Observation timestamp/TZ: date recorded, exact time and timezone not captured.
  Collector identity: uBook project-owned Fluent Bit sidecar; exact unit/container
  identity is unverified. Metric names/query endpoint: labels `retries`, `failed`,
  and `dropped` were reported, but exact metric names and query endpoint are
  unverified. Bounded observation window: not recorded. Correlation method for
  follow-up: compare sidecar counters/logs with proxy and Graylog `/gelf` ingress
  status, ACK latency, timeout, and queue metrics over one shared bounded window;
  that correlation has not yet been verified.
