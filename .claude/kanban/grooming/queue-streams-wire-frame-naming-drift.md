### Drift в docs/queue-streams.md §5 — имя WS-фрейма результата (`completion{data}` vs `result{inline}`)

**Criticality:** Low

**TAGS:**
- tech-debt
- documentation
- queue
- gateway

**Описание:**
В `docs/queue-streams.md` §5 «Result commit sequence» (inline-путь) WS-фрейм назван `completion{data: base64}`. По факту в коде (`workers/gateway/ws_server.py`, `workers/common/ws_client.py`) фрейм называется `result{inline: <base64>}`. Это staleness именования wire-протокола (не влияет на KeyDB-атрибуцию), поэтому оставлено при хирургической правке §2–§6.

**Задача:**
Сверить имя фрейма результата с кодом (`ws_server.py`/`ws_client.py`) и поправить §5 под реальный протокол (`result{inline}`).

**Контекст:**
Найдено при переписывании §2–§6 queue-streams.md под WS-модель.

**Status:** grooming.
