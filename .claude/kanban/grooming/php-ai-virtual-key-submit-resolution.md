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

**Open questions:**
- Где резолвить плоскую пару в виртуальный ключ — в `submit()` до валидации, или в
  `isSupported()`/`isAi()`/`streamFor()`? Нужна ли явная сигнализация выбора stream
  для неоднозначных пар (флаг `ocr` и т.п. из CLAUDE.md «Queue Architecture»)?
- Что для пар, которые умеют несколько воркеров (напр. `pdf→txt`: document-extract vs
  image-OCR) — как submit выбирает stream? (CLAUDE.md уже описывает флаг-селектор.)
- Тест-покрытие: добавить e2e submit `mp3→txt`/`txt→mp3`/`txt→json` доходит до stream `ai`.

**Влияние:**
Без этого вся AI-ветка не работает end-to-end; рефактор воркера и последующие
карточки эпика (LLM, dev-сервер, бенчмарки) не проверяемы реальной задачей.
