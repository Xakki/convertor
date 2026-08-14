### CNV-86 — Backend: разделение OpenAPI runtime-areas и доступа

**Criticality:** High

**TAGS:**
- backend
- security
- api
- openapi
- nelmio

**Description:**
Backend-разработчик реализует отдельные Nelmio OpenAPI areas и runtime access control:
`public`, `private_user`, `private_admin`, с раздельными Swagger UI/JSON-маршрутами.
Карточка не проводит редакционный аудит описаний и examples.

**Problem:**
Единая public-доступная спецификация смешивает public, user и admin операции, поэтому
видимость документации не соответствует реальным ролям и API-границам.

**Impact:**
Публичная спека раскрывает административную поверхность, а интегратор не может
надёжно определить допустимую аутентификацию и аудиторию endpoint.

**Recommendation:**
Настроить named areas и `#[Areas]` на actions, отдельные UI/JSON routes и
`access_control` до общего правила `^/api`. Сохранить `/api/doc` и `/api/doc.json` как
public alias; исключить worker/internal/webhook из public и private-user areas.

**Acceptance Criteria:**
- Созданы `public`, `private_user`, `private_admin` areas и отдельные UI/JSON routes;
  legacy `/api/doc` и `/api/doc.json` остаются public alias.
- Public UI/JSON доступны анониму и гостю; private-user недоступны анониму/гостю;
  private-admin недоступны `ROLE_USER`; `ROLE_ADMIN` может открыть private-user route.
- Public/user specs не содержат admin, worker, internal и Telegram webhook operations;
  private-admin содержит административные и служебные contracts отдельно.
- Action-area mapping отражает фактические `PUBLIC_ACCESS`, `ROLE_GUEST`, `ROLE_USER` и
  `ROLE_ADMIN` границы, включая bearer allowlist после CNV-83 и audit API после CNV-109.
- Functional tests зелёные для доступа к UI/JSON и состава JSON-спек без секретов.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимости:** CNV-84 обязателен по цепочке `CNV-87 → CNV-83 → CNV-84 → CNV-86`;
  CNV-109 обязателен для включения audit endpoint в private-user spec.
- Nelmio v4.38.7 поддерживает named areas и `#[Areas]` на class/method.
- Telegram webhook, worker API и internal gateway API присутствуют только в
  private-admin спецификации.
- Редакционный OpenAPI-аудит, examples и проверка документационных утверждений
  принадлежат CNV-111.
