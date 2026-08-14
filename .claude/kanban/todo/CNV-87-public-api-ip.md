### CNV-87 — Backend: анонимная IP-identity для public API

**Criticality:** High

**TAGS:**
- backend
- security
- api
- privacy
- rate-limit

**Description:**
Backend-разработчик реализует серверный fallback identity для разрешённых public API
операций без cookie и bearer-токена. Приоритет остаётся у JWT, персонального API-токена
и валидного `guest_id`; IP-derived identity применяется только при их отсутствии.

**Problem:**
Public API без браузерной cookie не имеет контрактного владельца для quota, rate-limit
и проверки владения текущей операцией. Доверие клиентскому forwarded header позволило бы
подменить identity.

**Impact:**
Анонимные интеграции либо не работают предсказуемо, либо обходят действующую модель
quota/owner checks; ошибочная обработка IP создаёт риск раскрытия данных за NAT/VPN.

**Recommendation:**
Ввести keyed HMAC-SHA-256 identifier от client IP на доверенной server-side границе.
Принимать `X-Forwarded-For` и `X-Real-IP` только от единственного trusted proxy Nginx;
не сохранять raw IP. Ограничить fallback quota, rate-limit и владением одной текущей
операцией, без list-history и management-доступа.

**Acceptance Criteria:**
- Разрешённые public endpoints без cookie/token получают identity только после проверки
  отсутствия JWT, personal token и валидного `guest_id`.
- IP identity применяет существующие quota, rate-limit и owner checks; AI/video и прочие
  guest/public ограничения не обходятся.
- Nginx определён единственным trusted proxy; spoofed client `X-Forwarded-For` не меняет
  выбранную identity.
- Новая identity не записывает raw IP или HMAC secret в БД, ответы, exception text и логи;
  секрет доступен только через `.env.local`.
- Existing guest-cookie flow, login merge и registered/API-token flow сохраняют поведение;
  операции разных owners изолированы.
- IP-derived identity и связанные anonymous conversion records получают срок хранения 30
  дней и исполнимый backend cleanup-hook для этого срока.
- Профильные backend functional/unit tests зелёные для precedence, same-IP stability,
  distinct-owner isolation и trusted-proxy защиты.

**Decisions:**
- **Владелец:** backend/security-разработчик.
- **Зависимости:** нет; это первый шаг цепочки `CNV-87 → CNV-83 → CNV-84 → CNV-86`.
- JWT, personal API token и валидный `guest_id` имеют приоритет над IP fallback.
- Анонимному API доступны formats/examples, создание, quota, status и ресурсы только
  собственной текущей операции; history list, retry/delete, payment, profile и management
  остаются user/admin-only.
- Privacy regression, пользовательская документация и проверки отсутствия утечек вне
  реализации identity принадлежат CNV-112.
