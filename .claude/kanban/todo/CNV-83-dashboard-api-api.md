### CNV-83 — Backend: персональные bearer-токены public API

**Criticality:** High

**TAGS:**
- backend
- security
- api
- authentication

**Description:**
Backend-разработчик создаёт доменную модель персональных именованных API-токенов,
выпуск/отзыв и bearer-аутентификацию только для утверждённой пользовательской
API-поверхности. Карточка не реализует dashboard UI и не создаёт audit storage.

**Problem:**
Зарегистрированный пользователь не может безопасно выдать или отозвать программный
доступ. Внутренний `WORKER_API_TOKEN` нельзя использовать как пользовательский секрет.

**Impact:**
Внешние интеграции не получают безопасный доступ, а компрометированный ключ нельзя
немедленно отозвать без затрагивания worker-инфраструктуры.

**Recommendation:**
Добавить отдельную сущность и миграцию с opaque random bearer, безопасным prefix, label
и `last_used_at`; хранить только невосстановимый verifier. Реализовать owner-scoped API
выпуска, metadata и отзыва, а также authenticator с явной route allowlist.

**Acceptance Criteria:**
- `ROLE_USER` может выпустить, перечислить metadata и отозвать только собственные токены;
  гость/аноним не имеют доступа к этим backend endpoints.
- Plaintext секрет возвращается исключительно при выпуске и не сохраняется в БД, логах,
  exception text или последующих JSON-ответах.
- Пользователь имеет максимум три активных именованных токена независимо от плана;
  четвёртый выпуск возвращает предсказуемую ошибку.
- Отозванный, malformed и неизвестный bearer не аутентифицирует запрос; JWT coexistence
  сохраняется.
- Bearer действует только для conversion, quota, history, download и preview allowlist;
  `/api/v1/admin`, `/api/v1/worker`, `/api/v1/internal` и auth-management endpoints
  недоступны.
- Через bearer сохраняются owner checks, quota, billing и действующие user/IP rate limits.
- Профильные unit/functional tests зелёные для issuance, revoke, owner isolation,
  guest denial, allowlist и отсутствия plaintext.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимость:** CNV-87; следующий обязательный шаг — CNV-84.
- `WORKER_API_TOKEN` остаётся внутренним fail-closed секретом и не меняется.
- TTL в MVP не обязателен; ротация — выпуск нового токена и отзыв старого.
- Dashboard UI выдачи и отзыва принадлежит CNV-108; запись и выдача audit history не
  принадлежат этой карточке.
