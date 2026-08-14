### Видео загрузки web страницы через Chromium worker

**Criticality:** High

**TAGS:**
- feature
- browser
- video
- recording

**Description:**
Записывать начальную загрузку authenticated URL в WebM через Chromium worker, включая
server-defined slow-network presets.

**Problem:**
Пользователь не может увидеть пошаговую загрузку медленной страницы; текущий video
worker не управляет browser timeline и не защищает URL runtime.

**Impact:**
Нельзя воспроизводимо анализировать поведение страницы при медленном соединении;
неограниченная recording способна истощить CPU, память, диск и очередь.

**Recommendation:**
WebM без audio; recording начинает навигацию и длится до server cap. Presets network:
`normal|fast_3g|slow_3g`; без raw latency/downlink. Progress: fetching, navigating,
recording, encoding, uploading; large result — существующий multipart protocol.

**Acceptance Criteria:**
- URL→WebM доступен basic/pro; free/guest получают предсказуемый отказ.
- Basic: 15 s/15 FPS/25 MB; Pro: 30 s/24 FPS/50 MB; один job/context/container slot.
- Fixture создаёт playable non-empty WebM; timeout/oversize корректно проходит
retry/DLQ, результат persisted до XACK, без duplicate terminal effects.
- No audio, MP4 не входит в MVP; URL policy и consent notice из CNV-89 обязательны.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- `slow_3g` — server preset, не пользовательские network numbers; HTML recording не
  входит в MVP.
