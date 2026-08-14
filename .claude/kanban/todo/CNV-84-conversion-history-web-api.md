### CNV-84 — Security: контракт безопасного аудита API-токенов

**Criticality:** High

**TAGS:**
- security
- api
- audit
- privacy

**Description:**
Security-специалист фиксирует и внедряет единый контракт audit event для вызовов,
аутентифицированных персональным API-токеном: допустимые поля, маскирование токена,
нормализацию route и запрет чувствительных данных на границе записи. Карточка не
создаёт таблицу, retention job или history endpoint.

**Problem:**
`Conversion` хранит бизнес-результат и не может быть транспортным audit-log. Без
центрального redaction-контракта будущая запись журнала рискует сохранить bearer,
Authorization header, body или файл.

**Impact:**
Audit storage и UI не имеют безопасного, согласованного формата; утечка секретов в
журнале сделает отзыв токена недостаточной защитой.

**Recommendation:**
Определить typed audit-event contract для завершённых token-authenticated HTTP-ответов,
включая ошибки: `method`, нормализованный route, `status`, `duration`, label/mask токена
и при наличии conversion ID. Встроить deny-by-default redaction на границе формирования
события и передать контракт CNV-109.

**Acceptance Criteria:**
- Контракт принимает только token-authenticated завершённые HTTP-вызовы, включая
  завершившиеся HTTP-ошибки; JWT/web и anonymous flow не создают эти события.
- Событие содержит только утверждённые method, normalized route, status, duration,
  token label/mask и optional conversion ID.
- Plaintext токен, полный Authorization header, request/response body, файлы, raw IP и
  User-Agent отклоняются или не сериализуются на границе контракта.
- Route нормализуется до стабильного шаблона без query, path-secret и пользовательских
  значений; события не используют `Conversion` как хранилище.
- Unit tests подтверждают allowlisted fields, redaction, token-only scope и обработку
  success/error response.
- Передаваемый CNV-109 контракт достаточен для append-only storage и owner-scoped
  endpoint без добавления чувствительных полей.

**Decisions:**
- **Владелец:** security-разработчик.
- **Зависимость:** CNV-83; этот security-contract является шагом audit в цепочке
  `CNV-87 → CNV-83 → CNV-84 → CNV-86`.
- Retention, migration, append-only storage и paginated endpoint принадлежат CNV-109.
- Frontend-вкладки принадлежат CNV-110; OpenAPI реализация и документационный аудит не
  входят в эту карточку.
- Срок хранения audit records — 90 дней; IP и UA не хранятся.
