### CNV-143 — Fluent/Graylog: сохранить structured-поля anonymous identity

**Criticality:** High

**TAGS:**
- tech-debt
- logging
- privacy
- fluent-bit
- graylog

**Description:**
Постдеплойная проверка должна подтверждать не только доставку фиксированного события
об anonymous API identity, но и доступность разрешённого контекста как structured fields
в цепочке Fluent → Graylog.

**Problem:**
Постдеплойный anonymous quota request завершился HTTP 200; в Graylog найдено ровно одно
сообщение `Anonymous API identity resolved`. При этом scoped-запрос
`auth_identity_type:anonymous_ip AND identity_opaque:true` не вернул результатов.
Без queryable-полей нельзя доказать сохранение безопасного контекста после Fluent/Graylog
парсинга и отличить отсутствие полей от потери события.

**Impact:**
Наблюдаемость privacy-контракта неполна: расследование не может безопасно подтвердить
тип anonymous identity и opaque-маркер в production-пути. Нельзя исправлять это добавлением
чувствительных идентификаторов или ослаблением redaction.

**Recommendation:**
Владельцу Fluent/Graylog logging pipeline проверить parser, GELF mapping и index-field
конфигурацию и обеспечить для фиксированного события точное сохранение двух разрешённых
полей: `auth_identity_type` и `identity_opaque`. Поля должны быть queryable в Graylog
с ожидаемыми значениями; неизвестные и запрещённые контексты должны отбрасываться или
redact-иться до индексации. Зафиксировать scoped post-deploy proof и rollback-safe
изменение pipeline.

**Acceptance Criteria:**
- Для одного контролируемого anonymous quota request в Graylog находится ровно одно
  фиксированное сообщение `Anonymous API identity resolved`, а scoped-запрос
  `auth_identity_type:anonymous_ip AND identity_opaque:true` возвращает это событие
  с обоими structured fields, не полагаясь на full-text поиск.
- Значения `auth_identity_type=anonymous_ip` и `identity_opaque=true` сохраняются
  точно; отсутствие этих полей, неверный тип или незапланированное дублирование события
  делает проверку неуспешной.
- Доставка и парсинг проверены по bounded post-deploy окну с secret-free результатом;
  в карточке фиксируются только статус и агрегированные результаты scoped query.
- В indexed event отсутствуют raw IP, guest IDs, HMAC, tokens, cookie/header/body
  values и иные secret/PII-поля; отдельной проверкой подтверждено, что эти поля не
  становятся queryable через Fluent/Graylog.
- Изменение ограничено Fluent/Graylog pipeline и его проверкой; source code,
  identity algorithm, API contract, deployment rollout и unrelated logging events
  не меняются этой карточкой.
- Тесты/QA: scoped Graylog query и pipeline/config validation зелёные; доказательство
  не заменяется локальным logger test или fabricated record.

**Open questions:**
- Кто является текущим владельцем Fluent/Graylog parser и index mapping и кто принимает
  изменение и rollback?
- Какой bounded post-deploy window и read-only доступ к Graylog используются для
  повторной проверки без записи raw records или PII?
- Какой pipeline/config source of truth изменяется и какой безопасный rollout/rollback
  нужен для сохранения ровно двух разрешённых полей?

**Decisions:**
- 2026-09-06: точного текущего владельца или дубликата для этой structured-field
  observability gap в Kanban не найдено. Смежные CNV-112 (privacy contract/local
  logger evidence) и CNV-138 (uBook delivery drops) не покрывают этот post-deploy
  Fluent/Graylog parsing gap.
- 2026-09-06: scope ограничен сохранением/queryability ровно `auth_identity_type` и
  `identity_opaque` для фиксированного anonymous event. Raw IP, guest IDs, HMAC,
  tokens, cookie/header/body values, secrets и PII запрещены; source, API, deploy и
  unrelated logging changes — вне scope.
- 2026-09-06: карточка остаётся в `grooming/` до подтверждения owner, pipeline source
  of truth и bounded read-only verification window.

**Execution Log:**
- 2026-09-06: post-deploy symptom recorded in sanitized form: anonymous quota request
  returned 200; fixed Graylog message was found once; scoped structured-field query
  returned no results. Raw Graylog record, IP, headers, body and potential PII не
  сохранялись.
- 2026-09-06: ID CNV-143 выделен через canonical `kanban-new.sh --prefix CNV`; source,
  runtime, deploy и unrelated cards не изменялись.
