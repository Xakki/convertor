### `verify_webm_partial.py` тестирует удалённый путь стрима (accumulator → temp file → process_file)

**Критичность:** Low (dev-only verify-харнесс, не рантайм)

**TAGS:**
- ai-worker
- test
- tech-debt

**Описание:**
`workers/ai/verify_webm_partial.py` (коммит `9e1eb89`) писался под СТАРЫЙ путь
dev-server стрима: накопительный буфер → временный файл → `process_file()` через
child-subprocess. После рефактора [[ai-devserver-stream-vad]] этого пути больше нет —
route теперь `PcmStreamDecoder` (PyAV, персистентный декодер) + `VadChunker`
(webrtcvad) + `StreamingWhisper.transcribe_pcm()`. Докстринг харнесса всё ещё
описывает старую механику и дёргает `process_file()` по старому subprocess-пути.

**Проблема:**
Харнесс верифицирует несуществующее поведение → ложное чувство покрытия / мёртвый код.

**Возможные направления (обсудить):**
- Переписать под прямую проверку `PcmStreamDecoder` (in-memory opus → PCM) — но
  реальный decode+VAD путь уже покрывается автотестами в `test_ai_devserver.py`
  (Test A/B/C, добавлены в [[ai-devserver-stream-vad]]), так что дубль может быть лишним.
- Либо просто ретайрить харнесс (удалить), раз путь ушёл и покрытие есть в pytest.

**Open questions:**
- Ретайрить или переписать? (склоняюсь к ретайру — покрытие уже в pytest.)

**Найдено при:** реализации [[ai-devserver-stream-vad]] (флаг имплементатора).

**Status:** grooming.
