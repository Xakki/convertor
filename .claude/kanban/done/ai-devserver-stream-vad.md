### AI dev-server — WS stream re-transcribes the whole growing buffer (O(n²) CPU)

**Критичность:** Low (dev-only tester)

**TAGS:**
- ai-worker
- perf

**Описание:**
`workers/ai/devserver/routes_stream.py` аккумулирует ВСЕ бинарные webm/opus-фреймы в
один растущий буфер и на каждом тике (и на stop) прогоняет faster-whisper
`process_file()` над ПОЛНЫМ накопленным буфером. Значит каждый `partial` — это
повторная транскрипция всего аудио с нуля, а не инкремента.

**Проблема:**
- CPU на сессию ~O(n²) по длине потока (каждый тик дороже предыдущего).
- Буфер не ограничен сверху → длинная сессия = рост RAM + всё более долгие тики.

Найдено при верификации [[ai-devserver-webm-partial-decode-gate]] — вне scope той
карточки (там гейт крашей/зависаний, гейт пройден).

**Возможные направления (обсудить):**
- Скользящее окно (`stream_window_sec`/`stream_overlap_sec` уже в cfg, но не режут буфер).
- Кап на размер буфера / длительность сессии.
- Реально инкрементальный STT (сложнее; для dev-тестера, вероятно, избыточно).

**Decision (2026-07-04) — груминг, standalone-фикс dev-тестера:**
Делаем **точечный standalone-фикс dev-тестера сейчас**, БЕЗ S2-эпика и БЕЗ отдельного
spec'а (одна Python-подсистема, не subsystem-scale). Продовый audio-воркер (файловая
конвертация, живого стрима в проде нет) — **вне scope**, отдельный follow-up при нужде.

**Подход — VAD-чанкинг по PCM:**
- **Персистентный стриминговый декодер.** webm/opus MediaRecorder кладёт заголовок
  контейнера ТОЛЬКО в первый чанк; серединные чанки по отдельности не декодируются,
  байтовый суффикс не разрезать. Значит: ОДИН долгоживущий демуксер/декодер на сессию,
  которому скармливают непрерывный поток байт (PyAV); в памяти — только свежий PCM.
  Не открывать декодер заново на каждый тик. Сверить с реальным чанкингом клиента
  (`MediaRecorder timeslice`).
- **Standalone streaming VAD** (silero-vad / webrtcvad по PCM для границ сегментов) →
  в `requirements-ai`. `vad_filter` внутри faster-whisper O(n²) НЕ лечит (бежит по тому
  буферу, что дали). STT — только по завершённым речевым сегментам; `partial` на сегмент.
- **Ограниченный PCM-хвост** (кап = последние N сек; задействовать уже существующий, но
  сейчас мёртвый `stream_overlap_sec` для overlap). Резидентный PCM не растёт с длиной сессии.
- Починить лживую доку «overlapping sliding windows» (settings/API) под реальное поведение.

**AC теста (все три ассерта):**
1. **Работа-на-тик ограничена** — поздний фрейм НЕ вызывает ретранскрипцию всей истории;
   ассерт по **call-args мока** (`_FakeStreamingWhisper`): размер/длительность входа,
   переданного транскрайберу за тик, ограничен окном/сегментом (ловит O(n²)-регрессию).
2. **Буфер/RAM ограничен сверху** — на длинном синтетическом потоке резидентный PCM не
   превышает кап, не растёт линейно с длиной сессии.
3. **Partial'ы приходят по ходу** — текст в потоке до `stop`, по сегментам, не только в финале.

**Файлы:** `workers/ai/devserver/routes_stream.py`, `workers/ai/providers/streaming_stt.py`,
`workers/ai/config.py` (кап/knobs), `docker/workers/requirements-ai*` (VAD-дека),
`workers/tests/test_ai_devserver.py`. Таргет: `make test-python-ai`.

**Реализация (ветка `task/ai-devserver-stream-vad`):**
- `9858060` — accumulator O(n²) → `PcmStreamDecoder` (PyAV, персистентный) + `VadChunker` (webrtcvad) + `transcribe_pcm()`.
- `6659183` — hardening (log decode-errors, safe numpy PCM extraction).
- `ef4f9d8` — раунд 2: Med#1 (force-flush на непрерывной речи), Med#2 (`_decode_error` → `{"type":"error"}` клиенту), +6 герметичных тестов реального пути (`av` в тест-таргете).

**Ревью — APPROVE** (2 раунда). Раунд-1 APPROVE-WITH-NITS: 2 Med (bounded-mem дыра на непрерывной речи; тихий decode-fail) — исправлены в `ef4f9d8`. Раунд-2 дельта — APPROVE, трассировка чистая. Оба Med закрыты, все 3 AC реально ассертятся (per-tick bound по call-args мока, resident PCM ≤ cap, partial'ы по ходу), B3 — регресс-гард на Med#1. `make test-python-ai`: **101 passed, 2 skipped** (было 95).

**Carry-forward (Low, follow-up — не блокер):** per-tick `drain()` не проверяет `_decode_error`; при мгновенном фейле `av` клиент получит N пустых partial'ов до error-фрейма на `stop`. Приемлемо для dev-тестера (ошибка ВСЁ РАВНО всплывает); прошивка per-tick добавила бы неформальное кросс-thread чтение поля без лока. → [[stream-decode-error-per-tick-check]].

**Status:** ready — ревью APPROVE (2 раунда), `make test-python-ai` 101 passed. Ждёт финального ready→done пользователя.
