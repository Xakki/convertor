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
- Dual-payload не обходит pre-relay validation / DLQ (поведение согласовано в Decisions).
- `make TEST=1 test-gateway` зелёный.

**Open questions:**
- Отклонять dual-payload как malformed (400 / permanent DLQ)?
- Приоритет `resultKey` над `inline` (игнор inline, но валидировать его всё равно)?
- Валидировать inline в любом случае, даже если выбран large-path?

**Decisions:**
- (пусто — решить на grooming)
