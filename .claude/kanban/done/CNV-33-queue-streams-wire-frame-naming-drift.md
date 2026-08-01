### Drift в docs/queue-streams.md §5 — имя WS-фрейма результата (`completion{data}` vs `result{inline}`)

**Criticality:** Low

**TAGS:**
- tech-debt
- documentation
- queue
- gateway

**Epic:** [[CNV-47]] — подзадача 1.

**Описание:**
В `docs/queue-streams.md` §5 «Result commit sequence» (inline-путь) WS-фрейм назван `completion{data: base64}`. По факту в коде (`workers/gateway/ws_server.py`, `workers/common/ws_client.py`) фрейм называется `result{inline: <base64>}`. Это staleness именования wire-протокола (не влияет на KeyDB-атрибуцию), поэтому оставлено при хирургической правке §2–§6.

**Задача:**
Сверить имя фрейма результата с кодом (`ws_server.py`/`ws_client.py`) и поправить §5 под реальный протокол (`result{inline}`). Пройтись grep'ом по комментариям/докам на устаревшее `completion{data}` / `completion` как имя result-фрейма и поправить.

**Acceptance Criteria:**
- `docs/queue-streams.md` §5 использует `result{inline}` (не `completion{data}`).
- Scan комментариев/доков: устаревшие упоминания `completion{data}` (как wire-фрейм результата) исправлены или помечены устаревшими.
- Имя согласовано с `ws_server.py` / `ws_client.py`.

**Decisions:**
- (2026-08-01) Scope: fix docs §5 + scan comments `completion{data}` → `result{inline}`.

**Контекст:**
Найдено при переписывании §2–§6 queue-streams.md под WS-модель.

**Status:** ready

**Execution Log:**
- 2026-08-01: todo→progress. Grep: stale `completion{data}` in `docs/queue-streams.md` §5, `docs/queue-contract.md` §5, comment in `workers/common/ws_client.py`. Code already uses `result{inline}` / `type=result` (`ws_server.py`, `ws_client.py`).
- 2026-08-01: Replaced `completion{data}` → `result{inline}` in those 3 files. Commit `73fb609` (docs: align WS result frame name to result{inline}). Historical done-card `s1-08` informal "completion" left as-is (not wire-frame name).
- 2026-08-01: QA (docs-only): grep `completion\{data\}` empty under docs/ + workers/; §5 shows `result{inline: base64}`; contract + ws_client docstring aligned. AC met.
