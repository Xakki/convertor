### S1-09 — Миграция AI-воркера на общий WS-клиент

**Критичность:** High

**TAGS:**
- worker
- ai-worker
- transport

**Описание:**
Перевести AI-воркер (`conv.ai`) с HTTP pull-API на общий WS-клиент `[[s1-08-shared-ws-client]]`. Логика обработки одной задачи (`_process_job` в `workers/ai/worker.py`) переиспользуется дословно через seam `handle_job`; меняется только транспорт вокруг неё. Унифицированная модель результата: вход — через `GET /jobs/{id}/input` (не прямой S3), результат — inline (≤256 KB) / large (`POST /jobs/{id}/result` + `resultKey`), fail — `{type:"fail"}`; эмиссия `progress` по ходу.

**Удаляется (§2/§8):**
- `workers/ai/pull_api.py` (весь pull-клиент).
- poll-цикл `_poll_loop` и его `hostname-pid` consumer-имя (заменено стабильным `WORKER_ID`).
- env-gate `PULL_ENABLED`, `POLL_INTERVAL`.
- панель dev-сервера «pull-stats» → минимальная **«WS-stats»** (состояние соединения + in-flight + последний pong).

**Файлы:**
- Изменить: `workers/ai/worker.py` (на `ws_client`; `_process_job` → seam `handle_job`; убрать poll-цикл).
- Удалить: `workers/ai/pull_api.py`.
- Изменить: `workers/ai/config.py` (добавить `WORKER_ID`/`GATEWAY_WS_URL`/WS-tunables; убрать `PULL_ENABLED`/`POLL_INTERVAL`).
- Изменить: `workers/ai/devserver/` (pull-stats → WS-stats; см. spec §10).
- Изменить: `.claude/skills/devserver-api-contract/SKILL.md` (переименование pull-stats → WS-stats в разделах «Pull stats», «GET /api/stats», «Architecture notes»).
- Изменить: `workers/ai/__main__.py` (запуск через WS-клиент).
- Изменить: `workers/tests/` — AI-воркер через фейковый gateway.

**Критерии приёмки:**
- AI-воркер подключается по WS, `ready{workerType:"ai"}`, обрабатывает засеянную `conv.ai`-задачу через `_process_job`, возвращает результат (inline/large), эмитит `progress`.
- Grep: НЕТ `pull_api`, `_poll_loop`, `PULL_ENABLED`, `POLL_INTERVAL`, `POST /claim`, `hostname-pid`.
- AI-воркер не открывает соединение к KeyDB/S3 напрямую (grep-ассерт); вход только через `GET /jobs/{id}/input`.
- dev-server показывает WS-stats (connection + in-flight + last pong), harness всё ещё прогоняет реальный end-to-end по WS.
- `pytest workers/tests` зелёный.
- Контракт `devserver-api-contract` обновлён под переименование pull-stats→WS-stats (house-rule: backend+UI следуют контракту verbatim).

**Зависит от:** `[[s1-08-shared-ws-client]]`, `[[s1-04-result-relay-ack]]`

**Эпик:** `[[s1-ws-worker-transport]]`

---

**Итог реализации (commits 2471f0c, 5d8b14a, 7047ca3):**
- `worker.py` переписан: транспорт убран, `_build_handle_job()` → seam `handle_job`; вход из `job["_localInput"]` (качает ws_client), `convert()` уже async (внутри `asyncio.to_thread`). SIGTERM/SIGINT → `client.stop()` (graceful drain). `FileNotFoundError` → retryable (не DLQ), `ValueError` (формат) → permanent.
- Удалены `pull_api.py`, `devserver/pull_runner.py`; `config.py` очищен (транспорт-конфиг в `WsClientConfig`); `__main__.py` через WS.
- devserver pull-stats → WS-stats (`connected`/`inflight`/`lastPong`); UI (`index.html`/`app.js`) мигрирован, мёртвый `PULL_ENABLED` убран, вкладка «WS stats». Добавлен опциональный `on_pong`-хук в `ws_client` (no-op по умолчанию, s1-08-тесты не тронуты).
- Контракт `devserver-api-contract` обновлён (pull-stats → WS-stats).

**Ревью (5 finder-углов × verify):** 4 блокера найдены и починены (UI не мигрирован, SIGTERM убран, FileNotFoundError→permanent, нет теста download-fail) + мелкая уборка. Транспортные (s1-08) находки вынесены → `[[ws-transport-hardening]]`, `[[ai-devserver-ws-stats-polish]]`.

**Grep-ассерты:** нет `pull_api`/`_poll_loop`/`PULL_ENABLED`/`POLL_INTERVAL`/`claim`/`hostname-pid`/`gethostname`; нет прямого S3/KeyDB. Вход только через `GET /jobs/{id}/input`.

**Тесты (независимо перепроверено тимлидом):** `make test-python-ai` = 105 passed / 2 skipped; `make test-gateway` = 80 passed.

**Status:** ready
