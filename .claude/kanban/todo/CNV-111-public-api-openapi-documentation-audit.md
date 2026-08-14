### CNV-111 — Documentation/security: аудит публичной OpenAPI-документации

**Criticality:** High

**TAGS:**
- documentation
- security
- api
- openapi
- audit

**Description:**
Documentation/security-специалист проводит редакционный и security-аудит сгенерированных
OpenAPI specs после CNV-86: аутентификация, аудитории, errors, examples и отсутствие
чувствительных данных. Карточка не меняет runtime routes или Nelmio areas.

**Problem:**
Даже разделённая runtime-спека может неверно описывать доступ, guest semantics, bearer
allowlist или error response и случайно публиковать секрет в examples/fixtures.

**Impact:**
Интегратор реализует небезопасный или неработающий клиент, а опубликованная
документация становится источником раскрытия внутренней поверхности.

**Recommendation:**
Сверить каждый public/private-user/private-admin contract с runtime access policy,
внести только документационные annotations/descriptions/examples и добавить snapshot/
contract проверки JSON-спек.

**Acceptance Criteria:**
- Public operations явно различают `PUBLIC_ACCESS`, guest-cookie и anonymous IP fallback;
  private-user операции описывают bearer token и не заявляют доступ к forbidden routes.
- Для каждой операции указаны фактическая аутентификация и применимые `401`/`403`.
- Public/user specs не содержат admin/worker/internal/Telegram webhook, а private-admin
  спецификация не смешивается с private-user.
- Audit-history endpoint описывает только безопасные metadata и 90-day retention; examples,
  responses, fixtures и generated docs не содержат bearer, headers, body, файлы, IP или UA.
- JSON-spec contract/snapshot tests зелёные для audiences, auth, errors и secret redaction.

**Decisions:**
- **Владелец:** documentation/security-специалист.
- **Зависимости:** CNV-86 реализует areas и routes; CNV-109 предоставляет audit endpoint.
- Изменения ограничены документацией и её тестами: runtime access_control, endpoint code
  и storage не изменяются.
- `/api/doc` и `/api/doc.json` документируются как public alias.
