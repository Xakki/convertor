### Conversion history: вкладки Web-конверсии и API-запросы

**Criticality:** Medium

**TAGS:**
- feature
- dashboard
- api
- history
- audit

**Description:**
Разделить историю конвертаций на Web-конверсии по умолчанию и журнал вызовов,
сделанных персональным API-токеном.

**Problem:**
Текущая `Conversion` хранит бизнес-результат, но не источник вызова и не является
транспортным audit-log. Пользователь не видит, какие действия его внешний API-клиент
выполнял и с каким результатом.

**Impact:**
Невозможно отличить web-историю от программных запросов, диагностировать API-клиент
или безопасно проследить его действия без смешивания transport audit с конвертациями.

**Recommendation:**
После реализации CNV-83 создать отдельный append-only owner-scoped API request log,
отдельный paginated endpoint и вкладки в выбранном UI. Web-вкладка остаётся активной
по умолчанию и использует существующий endpoint истории конвертаций.

**Acceptance Criteria:**
- По умолчанию активна вкладка «Web-конверсии»; её pagination и данные не
  регрессируют относительно текущей `/api/v1/convert/history`.
- Вкладка «API-запросы» возвращает только записи текущего владельца, newest-first,
  с детерминированной пагинацией и индексом owner+created_at.
- Журнал не содержит plaintext токенов, полных Authorization headers, тел запросов,
  файлов или иных секретов; содержит только утверждённые безопасные metadata.
- API-token вызовы отражаются в журнале отдельно от web/JWT-потока; гость не имеет
  доступа к API request history, а существующая guest web-history не меняется.
- Тесты покрывают owner isolation, empty state, утверждённую политику ошибок,
  отсутствие секретов, пагинацию и вкладку по умолчанию.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- 2026-08-14: вкладки размещаются в `/history` как основном экране; dashboard
  сохраняет текущий краткий список без дублирования tabbed UI.
- 2026-08-14: логируются только вызовы персональным API-токеном, включая
  завершившиеся HTTP-ответы с ошибками. Хранятся method, нормализованный route,
  status, duration, label/mask токена и conversion ID; IP/UA не хранятся. Retention
  — 90 дней с автоочисткой, фильтры не входят в MVP.
- Зависит от CNV-83: текущая модель не умеет отличить token-authenticated запрос
  от JWT/web и не содержит сущности token/audit-log.
- Не смешивать API request log с `Conversion` и не менять существующую политику
  хранения файлов конвертаций.
