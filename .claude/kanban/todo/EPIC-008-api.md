### Публичный API доступ идентичность и аудит

**Criticality:** High

**TAGS:**
- feature
- api
- authentication
- security
- privacy

**Description:**
Собрать единый последовательный поток публичного API: anonymous identity без
обязательных cookie/token, персональные API-токены, audit history и разделённая
OpenAPI-документация.

**Problem:**
Public API не имеет утверждённой anonymous identity без browser-cookie,
зарегистрированный пользователь не управляет API-токенами, история API-вызовов
отсутствует, а Swagger не разделён по аудиториям и доступам.

**Impact:**
Внешняя интеграция не получает безопасный предсказуемый контракт, а public/user/admin
документация расходится с фактическими auth, quota и privacy границами.

**Recommendation:**
Выполнять по порядку: сначала anonymous identity и ownership, затем token lifecycle,
потом API audit history и в финале OpenAPI, которая документирует завершённый
runtime-contract. Не смешивать worker/internal/admin token boundaries с личными
пользовательскими токенами.

**Acceptance Criteria:**
- Выполнены AC CNV-87, CNV-83, CNV-84 и CNV-86; endpoint visibility, quota,
  owner checks и retention согласованы между API, UI и документацией.
- Full quality gate проекта зелёный; проверены anonymous, guest, `ROLE_USER`,
  `ROLE_ADMIN`, personal-token, worker/internal и webhook сценарии.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- CNV-87 выполняется первой: public OpenAPI и audit нельзя считать корректными,
  пока не определён anonymous IP fallback.
- Public docs сохраняют `/api/doc` и `/api/doc.json`; private-user и private-admin
  используют отдельные URL. Worker/internal/webhook документируются только admin.

**Subtasks:**
- CNV-87 — anonymous public API identity по HMAC IP и 30-day retention
- CNV-83 — до трёх именованных персональных API-токенов
- CNV-84 — API request audit и вкладка в `/history`
- CNV-86 — OpenAPI public/private-user/private-admin areas

**Integration checklist:**
- Прогнать auth/access matrix для анонима, гостя, user, admin и personal token.
- Проверить отсутствие secrets и raw IP в responses, OpenAPI, fixtures и логах.
- Выполнить полный quality gate проекта.
