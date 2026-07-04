### dev-server стрим: `_decode_error` не проверяется на per-tick `drain()`

**Критичность:** Low (dev-only тестер, ошибка всё равно всплывает)

**TAGS:**
- ai-worker
- robustness

**Описание:**
В `workers/ai/devserver/routes_stream.py` `_decode_error` от фонового PyAV-потока
проверяется только в stop-хендлере (после `decoder.close()`), но НЕ на промежуточных
per-tick `pcm = decoder.drain()`. Если `av`/декод падает сразу, клиент получает N
пустых `partial`-фреймов и узнаёт об ошибке только на `stop`.

**Почему не чинили в [[ai-devserver-stream-vad]]:**
Ошибка ВСЁ РАВНО всплывает (на stop), для dev-тестера приемлемо. Прошивка per-tick
проверки добавляет неформальное кросс-thread чтение поля `_decode_error` (пишется
из фонового потока) без лока — GIL спасает, но это стоит сделать аккуратно.

**Направление (обсудить):**
- Проверять `decoder._decode_error` после каждого `drain()`; при set → сразу
  `{"type":"error"}` + break. Оформить чтение поля потокобезопасно (lock/event).

**Найдено при:** ревью [[ai-devserver-stream-vad]] (Low residual, не блокер).

**Status:** grooming.
