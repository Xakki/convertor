### WS inline-порог должен быть ЕДИНЫМ для воркера и gateway

**Критичность:** Medium

**TAGS:**
- transport
- worker
- gateway
- config

**Описание:**
`WS_RESULT_INLINE_MAX` читают ДВА независимых компонента — воркер (`workers/common/ws_client.py`, выбор inline-vs-large в `_deliver`) и gateway (`workers/gateway/config.py`, приём/отклонение inline). Если пороги разойдутся (воркерский > gateway'ского), результат из диапазона `(gwMax, workerMax]` воркер отправит inline, а gateway отклонит его **без ack** (`_release_no_ack`) → задача тихо уретраится idle-reclaim'ом → в итоге DLQ, БЕЗ видимой воркеру ошибки. Симметрично: воркерский порог меньше gateway'ского — безобидно (просто раньше уходит в large-путь), но неоптимально.

Сейчас оба берут значение из одного `.env` (`WS_RESULT_INLINE_MAX=262144`), поэтому на одном хосте они совпадают. Риск — при раздельном деплое (remote-воркер со своим env-файлом, напр. `.env.worker-ai.example`) легко забыть синхронизировать. В коде добавлен предупреждающий комментарий у `_deliver`, но машинного enforcement нет.

**Задача (решение — gateway announces):**
- Gateway при `ready` возвращает воркеру свой `inlineMax` (напр. в `ready-ack`-фрейме); воркер адоптирует значение gateway для выбора inline-vs-large в `_deliver` вместо собственного env. Machine-enforced единый порог — расхождение порогов при раздельном деплое становится невозможным. Собственный `WS_RESULT_INLINE_MAX` воркера остаётся дефолтом до получения ack.
- Затрагивает: `workers/gateway/ws_server.py` (ready-ответ несёт `inlineMax`), `workers/common/ws_client.py` (`_send_ready`/приём ack → сохранить в конфиг; `_deliver` берёт effective-порог), контракт ready в `docs/`.

**Decisions (2026-07-07):**
- Enforcement: **gateway сообщает порог в ready-ack** (вариант A). Не доп-конфиг-чек, не только-доки — нужен машинный enforcement, т.к. remote-воркер (AI на своём env) — реальный источник рассинхрона.

**Известные минорные (спекой §6.6 допустимы, НЕ править сейчас, зафиксировать):**
- `ping`/`pong` без sequence-id (`ws_client.py::_ping_loop`): «протухший» pong от прошлого цикла может отсрочить детект смерти на один интервал. Спека полагается на критерий «N пропущенных» — приемлемо; ужесточение (seq-id на ping) — позже.
- stat-vs-read TOCTOU (`ws_client.py::_deliver`/`raw_size`): между `stat` (выбор ветки) и `read`/`open` файл теоретически может исчезнуть/измениться. Требует не-до-конца-записанного выхода воркером; низкий риск.

**Эпик:** `[[s1-ws-worker-transport]]`

**Execution Log (2026-07-07):**
- Gateway (`ws_server.py::_register_worker`) шлёт `ready-ack{inlineMax}` сразу после регистрации, до диспатча job'ов → воркерский reader (последовательный, WS сохраняет порядок фреймов) адоптирует порог до первого `_deliver`.
- Воркер (`ws_client.py`): `_ConnState.effective_inline_max` инициализируется env-дефолтом, обновляется из `ready-ack`; `_deliver(..., inline_max)` берёт effective-порог параметром вместо `self._cfg`. Guard: `isinstance(int) and not isinstance(bool) and >0` (защита от `inlineMax: true`).
- Старые воркеры без обработки `ready-ack` → фрейм в `else`-debug, держат env-дефолт (backward-compat).
- Доки: обновлён контракт ready в `workers/specs/2026-07-02-ws-worker-transport-design.md`.
- Коллатераль: в `test-gateway` Makefile-таргет добавлен `redis>=5.0` (gateway-код импортит redis; гейт падал без него). Структурный smell вынесен в grooming `[[workers-test-deps-requirements-file]]`.
- Ревью: APPROVE-WITH-NITS → оба nit'а (bool-guard, typed-assert в drain-тестах) применены (commit e0e7cce).
- Гейт: `make test-gateway` → **85 passed**.

**Status:** ready
