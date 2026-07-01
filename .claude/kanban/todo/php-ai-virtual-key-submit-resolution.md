### PHP бэк — резолв виртуальных AI-ключей на submit (mp3→txt / txt→audio / txt→json)

**Критичность:** High

**TAGS:**
- bug
- backend

**Описание:**
STT/TTS/embedding-пары присутствуют в реестре конверторов ТОЛЬКО под виртуальными
ключами (`mp3_stt`, `txt_tts`, …) — их пишет `buildMatrix()`. Но `ConversionManager::submit()`
валидирует **плоскую** пару: `isSupported('mp3','txt')` / `isAi('mp3','txt')` →
`$matrix['mp3']['txt']` не существует → бросается «Unsupported conversion».
`resolveSubType()` (ConversionManager.php:~204) только ПАРСИТ уже готовый суффикс
`_stt`/`_tts` из sourceFormat, но НЕ ПРОИЗВОДИТ виртуальный ключ из плоской пары —
ничего не преобразует `(mp3,txt)` → `mp3_stt`.

**Следствие:** реальный submit `mp3→txt` / `txt→mp3` / `txt→json` сегодня 400-reject
на бэке. AI-воркер (после рефактора ядра — local-only STT/TTS/embedding) технически
готов, но его пути недостижимы end-to-end, пока бэк не научится резолвить пару в
виртуальный ключ + выбирать stream.

**Контекст:** найдено при ревью [[ai-worker-refactor-core]] (рефактор питон-воркера,
зона `workers/ai`, PHP не трогался). Это НЕ дубль `worker-matrix-registry-drift`
(тот про обратное направление — пары воркера отсутствуют в PHP, и он resolved).
Собственный docblock `resolveSubType()` называет это «the deferred AI-routing gap».

**Влияние:**
Без этого вся AI-ветка не работает end-to-end; рефактор воркера и последующие
карточки эпика (LLM, dev-сервер, бенчмарки) не проверяемы реальной задачей.

**Decisions:**
- Approach = **Option B (flat pairs) on the CURRENT hardcoded matrix** (do NOT wait for the self-registration epic). Add AI pairs to PHP `ConversionRegistry::workerCapabilities()` as a new AI worker block with `isAi:true`: sources mp3/wav/ogg/m4a/opus → txt/srt/vtt ; txt/md → mp3/wav/ogg ; txt → json.
- DELETE the virtual-key injection block (`buildMatrix()` ~L249-262) and `resolveSubType()` (ConversionManager.php ~L199-213). `streamFor()` already returns 'ai' when isAi.
- Update the golden snapshot test (ConversionRegistryGoldenTest) + drift test as needed.
- MERGE `backend-subtype-cleanup` into this card (delete subType/resolveSubType is part of B).
- Phase 2 (generalized candidate+intent router) is OUT OF SCOPE here → lives in the registry epic.

**Status:** ready (todo). Verified: all 3 AI pairs are unambiguous; no AI block exists in workerCapabilities today.
