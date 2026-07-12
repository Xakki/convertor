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

**Decisions (2026-07-08, премис сдвинулся — registry Phase 1 приземлился ПОСЛЕ груминга):**
- Карточка грумилась как «PHP-only на hardcoded-матрице», но Phase 1 сделал DB-путь
  (`buildMatrixFromCapabilities`, читает register от Python) основным, hardcode — fallback.
  → чтобы `/formats` был идентичен на обоих путях, задача расширяется на **Python-зону**.
- **Механизм = declared capability (оба пути).** [USER 2026-07-08]
  - PHP: AI-блок в `workerCapabilities()` с пер-парной категорией (STT-пары → `FileCategory::Audio`,
    TTS-пары → `FileCategory::Document`; **НЕ** добавлять `FileCategory::Ai` — сломает `assertMimeAllowed`).
    Расширить формат pair-группы `[fromList,toList]` → опц. `[fromList,toList,?FileCategory]`;
    `buildMatrixFromHardcode()` читает пер-групповую категорию (backward-compat: нет 3-го эл. → как раньше).
  - PHP DB-путь: **убрать `if ($isAi) continue`** в `buildMatrixFromCapabilities()`; для isAi-воркеров
    брать категорию из нового поля blob `matrix_categories[$from]` (audio/document).
  - Python: `workers/ai/worker.py` `CAPABILITIES.matrix` — заполнить плоскими парами; добавить
    поле `matrix_categories` (from-формат → 'audio'|'document'); обновить комментарий L35-37.
    `routing_keys` остаётся `["ai"]`.
- **flac включаем** [USER 2026-07-08]: STT-входы = mp3/wav/ogg/m4a/opus/**flac** → txt/srt/vtt
  (convert.py `STT_INPUTS` уже умеет flac). TTS = txt/md → mp3/wav/ogg. txt → json.
- Удаления: virtual-key инъекция в `buildFullMatrix()` (~L188-203); `resolveSubType()`
  (ConversionManager ~L204-213) + её вызов в `dispatch()` L178 (subType→null литерал).
- drift-тест `test_routing_drift.py:214-218` — убрать `_stt`/`_tts` skip-фильтр (плоские пары
  без суффикса). Голден `conversion_matrix.golden.txt` — регенерить `php bin/dump-matrix.php --write`.
  `ConversionRegistryFallbackTest` L33/34/91 — виртуальные ассерты → плоские (`mp3→txt` isAi=true).

**Status:** ready. Verified: all 3 AI pairs unambiguous; no AI block in workerCapabilities today.

**Реализация (`ae73487` + ниты `430d079`):**
- PHP `ConversionRegistry`: AI-блок в `workerCapabilities()` с пер-групповой `FileCategory` (STT→Audio, TTS/embed→Document); `buildMatrixFromHardcode` читает опц. 3-й элемент pair-группы; DB-путь `buildMatrixFromCapabilities` — убран `if($isAi)continue`, категория из `matrix_categories[$from]`, non-AI precedence-гард; virtual-key инъекция удалена, `buildFullMatrix` заинлайнен.
- PHP `ConversionManager`: `resolveSubType()` + вызов удалены, `subType: null` литерал.
- Python `workers/ai/worker.py`: `CAPABILITIES.matrix` заполнена плоскими парами + `matrix_categories` (audio/document); `routing_keys=["ai"]`; коммент обновлён.
- Плоская матрица: STT `mp3/wav/ogg/m4a/opus/flac→txt/srt/vtt`, TTS `txt/md→mp3/wav/ogg`, embed `txt→json` — все isAi=true, stream 'ai'. Голден регенерён.
- Тесты: parity-тест обоих путей, submit-тесты (`mp3→txt`/`txt→mp3`/`txt→json` → conv_ai), негативные (виртуальные ключи больше не supported), graceful-degradation (blob без `matrix_categories` → drop+warning), drift skip-фильтр убран.

**Ревью (reviewer-aikey):** APPROVE-WITH-NITS → оба нита закрыты (`430d079`): inline `buildFullMatrix`, тест на отсутствие `matrix_categories`. Критично подтверждено: обе ветки идентичны (parity), non-AI `txt` пары целы (ноль изменённых non-AI строк голдена), удаления полные, `FileCategory::Ai` не добавлялся.

**Гейты (все зелёные):** phpstan No errors; PHPUnit 100 tests / 386 assertions; test-python 97/18/33/31/15/110; test-drift 2 passed; test-gateway 96 passed.

**Разблокирует:** registry Phase 2 (`[[registry-00-self-registration]]`) — AI-воркер теперь объявляет реальную плоскую матрицу.
