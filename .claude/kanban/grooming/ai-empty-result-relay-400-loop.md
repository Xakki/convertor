### Пустой результат конвертации → relay 400 → бесконечный передиспатч (жжёт AI-компьют)

**Criticality:** High

**TAGS:**
- bug
- worker-transport
- ai
- dlq

**Description:**
Cepочка дефектов: AI-воркер отдаёт пустой (0 байт) результат конвертации →
gateway ретранслирует его в Symfony как HTTP 400 → gateway трактует 400 как
"не ack" → запись остаётся pending и передиспетчеризуется idle-reclaim'ом
бесконечно, без выхода в DLQ. Один poison-job жрёт компьют воркера
неограниченно долго.

**Chain (проверено построчно):**
1. `workers/common/ws_client.py`, `_deliver()` (~строка 863-878): если
   произведённый output — 0 байт, `size <= inline_max` выполняется,
   `signal.read_bytes()` возвращает `b""`, и во фрейм уходит
   `"inline": base64.b64encode(b"") == ""`. Пустой результат НЕ считается
   ошибкой на уровне воркера — `_send_fail`/`permanent=True` не вызывается,
   идёт обычный успешный `result`-фрейм.
2. `workers/gateway/relay.py`, `post_result()` (строки 62-81): поле `inline`
   ретранслируется дословно как `"data"` → в теле POST на Symfony уходит
   `"data": ""`.
3. `app-symfony/src/Controller/Api/InternalWorkerController.php`, `result()`
   (строка 66-68): `if ($rawData === '')` → `400 {"error": "\"data\" field is
   required"}`.
4. `workers/gateway/relay.py`, `_post_with_status()` (строки 156-184): любой
   не-2xx (включая этот 400) логируется как `"relay non-2xx — not acking"` и
   возвращает `False` — gateway трактует HTTP 400 (permanent client error) так
   же, как сетевой сбой (transient).
5. `workers/gateway/ws_server.py`, `_handle_result()` (строки 557-568): при
   `ok=False` вызывается `_release_no_ack(..., "inline relay failed —
   pending, credit released")` — запись НЕ ack'ается, кредит освобождён,
   **никакой проверки `times_delivered`/`MAX_RETRIES` здесь нет**.
6. Подтверждено чтением `workers/gateway/reclaim.py` (`_sweep_all_types`,
   строки 63-99) и `_handle_fail()` в `ws_server.py` (строки 570-627): проверка
   `times_delivered > MAX_RETRIES → DLQ` (`ws_server.py:609-620`,
   `MAX_RETRIES=3` в `workers/gateway/keydb.py:46`) реализована ТОЛЬКО в ветке
   обработки `fail`-фрейма от воркера. Idle-reclaim (`reclaim.py`) просто
   XAUTOCLAIM'ит просроченные PEL-записи по таймауту и передиспетчеризует их
   без какой-либо проверки счётчика попыток. Путь `result`→400 не проверяет
   `times_delivered` вообще и не роутится в DLQ ни при каком количестве
   попыток — **выхода в `conv.dead` для этого случая нет вообще**, retry
   бесконечен по построению.

**Impact:** реально сожгло CPU на двух хостах (on-server AI-воркер и удалённый
xBook) ~40 минут в ходе диагностики 2026-07-23; одна задача передоставлена 76
раз (`XPENDING conv.ai convertor` показал delivery-count 9-76 по 8 записям).
Не хостоспецифично — задело оба воркера одинаково.

**Repro (наблюдалось 2026-07-23):** AI-транскрипция wav-фикстуры (1 секунда,
почти тишина) даёт на выходе пустой txt. Конверсии 49-56 зациклились. Снято
вручную: `XACK`+`XDEL` восьми записей + пометка конверсий failed.

**Recommendation — два раздельных решения, на груминге:**

(a) **Контракт "пустой результат":** легитимен ли 0-байтный output? Либо
API должен принимать пустой `data` и сохранять 0-байтный результат (правка
`InternalWorkerController::result()`, убрать/смягчить проверку `$rawData ===
''`), либо воркер должен трактовать пустой output конвертации как permanent
fail (`_send_fail(..., permanent=True)` в `ws_client.py::_deliver()`) вместо
отправки пустого `result`.

(b) **Robustness основного пути:** relay 400 (permanent, non-transient
client error) не должен ретраиться бесконечно — нужен путь в DLQ/пометку
failed после N попыток для ветки `result`→non-2xx, симметричный тому, что уже
есть для ветки `fail` (`_handle_fail`, `times_delivered > MAX_RETRIES` →
`_to_dlq_and_release`). Сейчас poison-message на inline-result-пути горит
вечно.

**Status:** grooming.
