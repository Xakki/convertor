### CNV-108 — Frontend: dashboard персональных API-токенов

**Criticality:** High

**TAGS:**
- frontend
- dashboard
- api
- authentication

**Description:**
Frontend-разработчик добавляет в `/dashboard` пользовательский интерфейс выпуска,
просмотра metadata и отзыва персональных API-токенов через контракт CNV-83.

**Problem:**
Даже при наличии backend token API пользователь не имеет безопасного самостоятельного
интерфейса для управления программным доступом.

**Impact:**
Владельцу придётся обращаться к техническим endpoint вручную, а срочный отзыв
скомпрометированного токена станет неудобным и ошибкоопасным.

**Recommendation:**
Показать список label/prefix/last-used/status, форму выпуска и подтверждённый отзыв.
Plaintext показывать в одноразовом success-state после выпуска, с явным предупреждением
о сохранении; после закрытия state не восстанавливать секрет из UI.

**Acceptance Criteria:**
- Только `ROLE_USER` видит блок управления токенами в `/dashboard`; гость и аноним не
  видят controls и не получают обращений к token API.
- Пользователь выпускает именованный токен, видит plaintext один раз в success-state и
  может скопировать его; повторный reload/list отображает только metadata.
- UI отображает error лимита трёх токенов и не предлагает успешный четвёртый выпуск.
- Пользователь может отозвать только токен из собственного списка; после успешного
  ответа UI обновляет status/list без показа секрета.
- DOM, client logs, notifications и error UI не выводят bearer, Authorization header
  или производный секрет.
- Frontend tests зелёные для success-state, reload без plaintext, limit error, revoke,
  guest state и redaction.

**Decisions:**
- **Владелец:** frontend-разработчик.
- **Зависимость:** CNV-83 предоставляет token endpoints и metadata contract.
- UI не меняет token model, rate limits, route allowlist или audit logging.
- Dashboard содержит только краткое управление токенами; tabbed history остаётся CNV-110.
