### Разделить OpenAPI по public user admin аудиториям

**Criticality:** High

**TAGS:**
- tech-debt
- api
- openapi
- documentation
- security
- nelmio

**Description:**
Разделить Swagger UI и JSON-спеки API по трём аудиториям: public без входа,
private user для `ROLE_USER` и private admin для `ROLE_ADMIN`. Документация должна
отражать реальные runtime-границы `PUBLIC_ACCESS`, `ROLE_GUEST`, `ROLE_USER` и
`ROLE_ADMIN`; служебные worker/internal API и Telegram webhook входят только в
private-admin документацию.

**Problem:**
Единственная Nelmio area `default` включает всё `/api/v1`, кроме `/internal` и
`/worker`, но включает `/api/v1/admin/*`. При этом `/api/doc` и `/api/doc.json`
доступны через `PUBLIC_ACCESS`; видимость документации не соответствует
разграничению API.

**Impact:**
Публичная Swagger-спека раскрывает административную поверхность, а интегратор не
видит ясной границы между анонимным, гостевым, зарегистрированным и admin-доступом.

**Recommendation:**
Создать Nelmio areas `public`, `private_user`, `private_admin` с разметкой actions
через `#[Nelmio\ApiDocBundle\Attribute\Areas]`; не использовать хрупкие отрицательные
regex по ролям. Добавить отдельные Swagger UI/JSON-маршруты и закрыть user/admin
документацию в `access_control` до общего правила `^/api`. Worker/internal и
provider webhooks не включать в public/private-user спеки.

**Acceptance Criteria:**
- Есть areas `public`, `private_user`, `private_admin` и отдельные UI/JSON-маршруты;
  legacy `/api/doc` и `/api/doc.json` сохраняют либо получают документированную
  замену согласно решению grooming.
- Public UI/JSON доступны анониму; user docs недоступны анониму и гостю; admin docs
  недоступны `ROLE_USER`. Каждый раздел содержит только утверждённые endpoints.
- Public/user спеки не содержат `/api/v1/admin/*`, `/api/v1/worker/*`,
  `/api/v1/internal/*` и Telegram webhook; private-admin содержит административные
  и служебные worker/internal/webhook contracts отдельно от private-user spec.
- Каждая операция документирует реальную аутентификацию и применимые `401/403`;
  guest-cookie/`ROLE_GUEST` явно отличается от `PUBLIC_ACCESS`.
- После CNV-83 private-user docs описывают персональный API bearer token и его
  allowlist, но не доступ к admin/worker/internal/auth-management API.
- Есть functional tests доступа к UI/JSON и тесты состава каждой JSON-спеки; секреты
  не попадают в OpenAPI examples, responses, fixtures или логи.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Nelmio v4.38.7 в репозитории поддерживает named areas и `#[Areas]` на class/method;
  разметка actions нужна, поскольку сейчас атрибуты areas в application code не
  используются.
- Базовое разделение существующих endpoints можно начать независимо, но полный
  private-user контур зависит от CNV-83 и CNV-84.
- Не дубликат baseline `api-openapi-swagger` (одна общая спека), CNV-83 (tokens),
  CNV-84 (API audit) или CNV-85 (catalog settings).
- 2026-08-14: public API разрешён анониму и гостю без обязательных cookie или
  токена; если их нет, публичный flow использует идентификатор на основе хэша IP.
  Реализация и privacy/security contract вынесены в зависимую CNV-87.
- 2026-08-14: `/api/doc` и `/api/doc.json` сохраняются как public alias;
  private-user и private-admin документация публикуются отдельными URL.
- 2026-08-14: `ROLE_ADMIN` может открыть отдельный private-user URL по наследованию
  роли; private-admin и private-user specs остаются раздельными.
- 2026-08-14: Telegram webhook, worker API и internal gateway API документируются
  только в private-admin спецификации; отдельная ops-spec не создаётся.
