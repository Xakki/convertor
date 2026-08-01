### Result-path: pre-relay валидация — DLQ или cap (malformed/oversize inline всё ещё eligible для бесконечного reclaim)

**Criticality:** Medium

**TAGS:**
- tech-debt
- worker-transport
- dlq

**Description:**
Находка ревью карточки `ai-empty-result-relay-400-loop` (2026-08-01). Исправлен путь
`result`→relay→Symfony 4xx, но **до** relay в `ws_server.py` отклонения (malformed
inline, oversize, decode error) по-прежнему идут через `_release_no_ack` без cap/DLQ —
запись остаётся в PEL и idle-reclaim может крутить её бесконечно.

**Problem:**
Pre-relay rejections на result-path не учитывают `times_delivered` / `MAX_RETRIES` и не
роутятся в `conv.dead`, в отличие от post-relay 4xx (уже DLQ) и fail-ветки.

**Impact:**
Poison-job с битым/слишком большим inline может бесконечно передиспетчеризоваться и
жечь кредиты/CPU, аналогично исходному багу с пустым результатом.

**Recommendation:**
Симметрично post-relay: permanent pre-relay ошибки → DLQ сразу; transient → capped
retry → DLQ. Покрыть тестами.

**Acceptance Criteria:**
- Pre-relay malformed/oversize/decode на result-path → DLQ или capped retry (как fail-ветка).
- Idle-reclaim не крутит такие записи бесконечно.
- `make TEST=1 test-gateway` зелёный.

**Open questions:**
- Какие pre-relay кейсы считать permanent vs retryable?
- Нужен ли единый helper для cap/DLQ на всех result-path отказах?

**Decisions:**

**Status:** grooming
