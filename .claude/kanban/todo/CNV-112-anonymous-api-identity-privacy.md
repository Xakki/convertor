### CNV-112 — Privacy/security: регрессии и документация anonymous API identity

**Criticality:** High

**TAGS:**
- security
- privacy
- api
- testing
- documentation

**Description:**
Privacy/security-специалист добавляет независимые regression tests и пользовательскую
policy-документацию для anonymous IP-derived identity, реализованной CNV-87. Карточка
не меняет алгоритм identity, proxy configuration или backend ownership code.

**Problem:**
IP-derived identifier допускает корреляцию активности и особенно чувствителен к утечке
raw IP, подмене forwarded headers и раскрытию history пользователей за общим NAT/VPN.

**Impact:**
Изменение identity может незаметно ослабить privacy boundary или создать ложные обещания
в публичной документации об anonymous access и retention.

**Recommendation:**
Зафиксировать privacy contract в документации и независимом regression suite: identity
только на trusted-proxy boundary, raw IP/secret нигде не раскрываются, anonymous history
не выдаётся без cookie/token, retention records — 30 дней.

**Acceptance Criteria:**
- Regression tests подтверждают JWT/token/valid `guest_id` precedence, no-cookie access,
  same-IP stability, distinct-owner isolation и защиту от spoofed forwarded headers.
- Tests доказывают отсутствие raw IP и HMAC secret в persistence, API responses, logs и
  documentation fixtures.
- Tests подтверждают, что anonymous клиент получает ресурсы только текущей собственной
  операции и не получает list-history за shared NAT/VPN IP.
- Tests подтверждают 30-day retention IP-derived identity/anonymous conversion records и
  соответствующий cleanup lifecycle.
- Пользовательская privacy/API-документация описывает trusted proxy boundary, 30-day
  retention, ограниченный anonymous scope и отсутствие общего history; не раскрывает
  secret или внутренние сетевые детали.
- Профильные security/privacy tests и документационный check зелёные.

**Decisions:**
- **Владелец:** privacy/security-специалист.
- **Зависимость:** CNV-87 реализует identity и cleanup-hook.
- Это независимая regression/documentation задача; она не изменяет CNV-87 backend
  algorithm, CNV-83 tokens, CNV-84 audit contract или CNV-86 OpenAPI areas.
- Секрет HMAC хранится только в `.env.local`; raw IP не является продуктовым metadata.
