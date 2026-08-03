### Finer 4xx taxonomy на result relay (401/403/429 vs permanent 400/422)

**Criticality:** Minor

**TAGS:**
- tech-debt
- worker-transport
- dlq

**Description:**
Находка ревью карточки `ai-empty-result-relay-400-loop` (2026-08-01). Текущая
реализация: blanket `400 <= status < 500 → immediate DLQ` на result-path — соответствует
AC этой карточки. Возможно позже понадобится различать recoverable 4xx (401/403/429,
временная деградация auth/rate-limit) от permanent client errors (400/422).

**Problem:**
Все 4xx трактуются как permanent; transient auth/rate-limit сбои уйдут в DLQ без retry.

**Impact:**
Низкий на MVP (internal worker→Symfony relay); при смене credentials или кратковременном
429 poison-job может преждевременно попасть в DLQ вместо retry.

**Recommendation:**
Отложить до появления реальных инцидентов или расширения surface relay. При реализации —
таблица status→{dlq, retry-cap} + тесты на граничные коды.

**Acceptance Criteria:**
- Документированная таксономия 4xx на result-path (permanent vs recoverable).
- Реализация + тесты, если принято в scope.
- `make TEST=1 test-gateway` зелёный.

**Decisions:**
- (2026-08-03) Отложено до реальных инцидентов 401/403/429 на internal result-relay. Blanket 4xx→DLQ остаётся. Карточка → freeze/.
- При разморозке: открыть заново вопросы про 401/403 (DLQ vs retry) и 429 (backoff).
