### Result-path: both inline+resultKey bypasses pre-relay validation

**Criticality:** Medium

**Epic:** [[CNV-52]] *(опциональный residual эпика)*

**TAGS:**
- tech-debt
- worker-transport

**Description:**
Находка ревью подзадачи `CNV-37` (pre-relay DLQ/cap на result-path). После фикса
malformed/oversize/decode для **чистого** inline остаётся обход: воркер может прислать
**оба** поля — `inline` и `resultKey` — в одном WS `{type:"result"}`.

**Problem:**
Если в сообщении одновременно есть `inline` и `resultKey`, gateway выбирает large-path
(trust-ack по `resultKey`) и **не** прогоняет inline через pre-relay валидацию
(oversize / malformed base64 / decode). Воркер может обойти cap/DLQ, добавив `resultKey`
к заведомо битому или слишком большому inline.

**Impact:**
Poison-payload с oversize/битым inline, но с `resultKey`, не попадает в pre-relay DLQ
из CNV-37; idle-reclaim может крутить запись, если POST result тоже не прошёл.

**Recommendation:**
Явно зафиксировать контракт dual-payload (см. Open questions) и закрыть обход в
`ws_server.py` + тестами.

**Acceptance Criteria:**
- Dual-payload (`inline`+`resultKey`) → malformed / permanent DLQ, не trust-ack large-path.
- `make TEST=1 test-gateway` зелёный.

**Decisions:**
- (2026-08-03) Dual-payload (`inline` + `resultKey` вместе) = malformed: reject / permanent DLQ. Контракт — ровно одно из двух полей. Закрыть в `ws_server.py` + тесты.

## Execution Log

- (2026-08-03) Started; branch `task/CNV-53`.
- (2026-08-03) Dual-payload reject: `workers/gateway/ws_server.py` `_handle_result` —
  оба `inline`+`resultKey` → `_to_dlq_and_release` (permanent DLQ) **до** large-path
  trust-ack; больше не очищает `inline` и не идёт в ack-на-доверии.
- (2026-08-03) Тест: `test_malformed_result_both_inline_and_resultkey_goes_to_dlq` в
  `workers/tests/test_gateway_relay.py` (oversize inline + resultKey → DLQ, без ack/relay).
- (2026-08-03) Review APPROVE (Grok); AC met; tests 195 passed / 1 skipped; awaiting user approval for done/.
