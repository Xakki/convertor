### AI-devserver WS-stats — семантика и уборка (хвосты ревью s1-09)

**Критичность:** Low

**TAGS:**
- worker
- ai-worker
- devserver

**Описание:**
Некритичные хвосты ревью s1-09 по devserver/config. Не блокируют приёмку s1-09 (блокеры — миграция UI, SIGTERM, классификация ошибок, тест — правятся в самой карточке); эти можно добить отдельно.

**Находки:**
1. **Семантика `connected`.** `WsRunner._run()` зовёт `stats.on_connected()` ДО `client.run()` — т.е. `connected=true` выставляется до реального WS-хендшейка и держится весь backoff при недоступном gateway (нет колбэка «connection failed» из `WsClient`). Контракт трактует `connected` = «runner-таск жив», но это вводит в заблуждение при outage. Вариант: привязать `connected` к первому `pong` (реальный live-traffic), либо добавить в `WsClient` колбэки `on_connect`/`on_disconnect` на уровне сокета.
2. **Дубли env-хелперов.** `_getenv_int`/`_getenv_float` определены идентично в `workers/ai/config.py` и `workers/common/ws_client.py` (последний прямо комментирует «по образцу config.py»). Вынести в `workers/common/` и импортировать.
3. **Двойное чтение `WORK_DIR`.** `Config.load_config()` и `WsClientConfig.from_env()` независимо читают `WORK_DIR`. Сейчас совпадает, но рассинхрон env между двумя снапшотами конфига → вход и выход в разных деревьях. Свести к одному источнику.
4. **`_build_handle_job` — приватный импорт через границу пакета.** `ws_runner.py` импортирует `workers.ai.worker._build_handle_job` (underscore = module-private). Либо сделать публичным seam (`build_handle_job`), либо принимать `cfg_getter` в контракт `HandleJob`.

**Decisions (2026-07-07):**
- #1 `connected`: **привязать к первому `pong`** (реальный live-traffic) — `connected=true` только после первого pong, не с момента старта runner-таска. При outage/backoff `connected=false`. Реализация — колбэк на pong в `WsClient`, `WsRunner` выставляет флаг по нему (минимальная правка; полноценные socket-level on_connect/on_disconnect не нужны).
- #2/#3/#4 — принять как есть (clean-up рефакторы): вынести `_getenv_int`/`_getenv_float` в `workers/common/`; свести чтение `WORK_DIR` к одному источнику; сделать `build_handle_job` публичным seam (или принимать `cfg_getter` в `HandleJob`).

**Связано:** `[[s1-09-ai-worker-migrate]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Execution Log (2026-07-07):**
- #1 `connected` привязан к первому `pong` (реальный live-traffic). `on_pong`-колбэк выставляет `connected=true`; `on_reconnect_start` (новый колбэк `WsClient`) сбрасывает `connected=false` перед backoff-сном. Ревью подтвердило корректность state-machine на ВСЕХ путях реконнекта (initial-fail / mid-session-drop / missed-pong / clean-stop), гонок нет (single-threaded loop).
- #2 `getenv_int`/`getenv_float` вынесены в `workers/common/env.py` (публичные имена), импортятся из `ai/config.py` и `common/ws_client.py`. Байт-в-байт идентичное поведение, без import-циклов.
- #3 Единый источник `WORK_DIR`: `Config.load_config()` читает+`.resolve()` один раз; `worker.py`/`ws_runner.py` передают `cfg.work_dir` в `WsClientConfig.from_env(work_dir=)`, который больше не читает env при переданном значении.
- #4 `_build_handle_job` → публичный seam `build_handle_job` (все ссылки обновлены, приватных не осталось).
- Ревью: APPROVE-WITH-NITS → оба nit'а закрыты (commit a8e05f8): устаревший WORK_DIR-комментарий обновлён; добавлен интеграционный тест `test_ws_on_reconnect_start_fires_on_disconnect` через реальный `WsClient`.
- Коммиты: 3f9ee57 (осн.) + a8e05f8 (нит-фиксы).
- Гейты: `make test-python-ai` → **110 passed, 2 skipped**; `make test-gateway` → **85 passed**.

**Status:** ready
