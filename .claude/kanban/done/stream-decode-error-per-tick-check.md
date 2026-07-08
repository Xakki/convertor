### dev-server стрим: проверять `_decode_error` на per-tick `drain()` (fail-fast)

**Критичность:** Low (dev-only тестер)

**TAGS:**
- ai-worker
- robustness

**Ветка:** off `main` (dev-тестер, НЕ часть S1-эпика). `task/stream-decode-per-tick`.

**Описание:**
В `workers/ai/devserver/routes_stream.py` `_decode_error` от фонового PyAV-потока
проверяется только в stop-хендлере (после `decoder.close()`), но НЕ на промежуточных
per-tick `pcm = decoder.drain()`. Если `av`/декод падает сразу, клиент получает N
пустых `partial`-фреймов и узнаёт об ошибке только на `stop`.

**Decisions (груминг 2026-07-05):**
- **Сделать (fail-fast).** Проверять `decoder._decode_error` после каждого `drain()`; при
  set → сразу отправить `{"type":"error", "message": _safe_err(...)}` + `break` (как в
  stop-ветке). Клиент узнаёт об ошибке на первом же тике, не после N пустых `partial`.
- **Чтение поля — потокобезопасно.** `_decode_error` пишется из фонового decode-потока;
  оформить чтение через `threading.Event`/lock (не полагаться на GIL неявно). Аккуратный
  cross-thread контракт: фоновый поток set'ит error + event, per-tick читает event.

**Файлы:**
- Изменить: `workers/ai/devserver/routes_stream.py` (per-tick проверка после `drain()`).
- Изменить: `workers/ai/devserver/pcm_decoder.py` при необходимости (threadsafe-флаг/event
  для `_decode_error`).
- Изменить: `workers/tests/test_ai_devserver.py` — тест: decode-ошибка на первом тике →
  error-фрейм приходит сразу (а не только после stop).

**Критерии приёмки:**
- После каждого `drain()` `_decode_error` проверяется; при set → error-фрейм + break на
  том же тике (не копятся пустые `partial`).
- Чтение `_decode_error` из route-корутины потокобезопасно (event/lock, не голый GIL).
- Тест на «ошибка на первом тике → немедленный error-фрейм» зелёный; существующий
  `test_ws_decode_error_surfaced` (на stop) остаётся зелёным.

**Найдено при:** ревью [[ai-devserver-stream-vad]] (Low residual, не блокер).

**Status:** todo — груминг завершён, scope ясен.

---

## Execution Log (2026-07-08, agent stream-decode)

**Что изменено:**
- `workers/ai/devserver/pcm_decoder.py`: добавлен `self._error_event = threading.Event()`;
  в `except` decode-loop порядок «сперва запись `_decode_error`, затем `_error_event.set()`»;
  новый accessor `decode_error() -> Exception | None`, гейт по `is_set()` → happens-before,
  без опоры на GIL. Старый атрибут `_decode_error` СОХРАНЁН (на него завязаны integration-тесты).
- `workers/ai/devserver/routes_stream.py`: per-tick после `drain()` (ветка декодера) —
  `dec_err = decoder.decode_error()`; при set → `{"type":"error","message":_safe_err(...)}` + `break`
  (та же форма, что в stop-ветке), клиент узнаёт на первом же тике. Stop-ветка тоже переведена
  на единый accessor `decode_error()`.
- `workers/tests/test_ai_devserver.py`: новый `test_ws_decode_error_surfaced_on_first_tick`
  (ошибка на первом тике, stop НЕ шлётся → error-фрейм из per-tick проверки). Существующему
  `test_ws_decode_error_surfaced` (stop-path) фейку добавлен метод `decode_error()`.

**Cross-thread контракт:** `threading.Event`. Decode-поток: запись поля → `set()`. Route-корутина:
`decode_error()` возвращает исключение только если `_error_event.is_set()` — событие даёт
happens-before, чтение исключения после этого гарантированно видит запись.

**Red-without-fix proof:** временно убрал per-tick проверку → новый тест виснет на `receive_json()`
(нет фрейма после bytes) → `timeout 60` дал exit 124. С правкой — зелёный. Тест реально
привязан к новому пути, а не проходит через stop-ветку.

**Gate:** `make test-python-ai` (worker-ffmpeg:test image) → **111 passed, 2 skipped**
(оба skip — espeak-ng / llama_cpp не в образе, не связаны). Python lint/type-гейта для
dev-server нет. Real-decoder note: у настоящего декодера `feed()` неблокирующий, ошибка
всплывёт на том тике, когда фоновый поток упадёт (не гарантированно на 1-м) — «первый тик»
это свойство синхронного фейка; правка удовлетворяет карте (проверка после каждого `drain()`).
