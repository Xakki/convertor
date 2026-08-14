### Backend: единый каталог и контракт настроек конвертации

**Criticality:** High

**TAGS:**
- tech-debt
- architecture
- api
- backend
- conversion-options
- validation

**Description:**
Реализовать backend foundation для versioned settings catalogue, server-side validation и normalized job options. Frontend, документация и cross-layer QA выделены в CNV-92, CNV-93 и CNV-94.

**Problem:**
Текущие image-only API validation и `/formats` не выражают profile, controls, границы, defaults или access policy; новые категории иначе продублируют правила и смогут обойти server-side проверку.

**Impact:**
API-клиенты и workers получат несогласованные options, а guest/plan restrictions будут реализованы неодинаково или попадут в job без нормализации.

**Recommendation:**
Расширить `GET /api/v1/formats` versioned deduplicated settings profiles и назначать profile точной паре `from→to`. Реализовать backend grammar `range`, `select`, ограниченные `number`/`text`, `boolean`, `color`; `POST /convert` повторно валидирует pair, key, type, boundary, enum/pattern и access, после чего сохраняет effective normalized options в job и истории.

**Acceptance Criteria:**
- `GET /api/v1/formats` отдаёт conversion matrix и deduplicated versioned profiles; пара ссылается на profile либо явно не имеет settings.
- Catalog персонализирован по cookie/JWT: guest видит только default без editable settings, free — базовые поля, basic/pro — все не-AI поля; `POST /convert` повторно проверяет доступ.
- Backend отклоняет unknown keys, settings без profile, неверные types, значения вне boundary, запрещённые enum/pattern и недоступные plan values.
- Range default нормализуется до effective value до serialизации job; job и история хранят применённые values, а не sentinel `0`.
- Сохраняется image semantics: width/height 1–10000, JPEG/WebP quality 1–100, JPEG background `#RRGGBB`; worker получает только normalized options.
- Backend contract/API tests покрывают каталог, validation, access и job serialization; существующие image tests, `pytest` и `make test` зелёные.

**Decisions:**
- CNV-85 — prerequisite всех domain profile cards CNV-95, CNV-97, CNV-100, CNV-103 и CNV-106; profiles завершаются до worker/frontend.
- CNV-92 владеет generic frontend renderer, CNV-93 — русской документацией, CNV-94 — cross-layer QA; они не входят в backend scope.
- Никаких raw FFmpeg, browser renderer или serializer arguments: только server-side whitelisted fields.
- Лимит длительности media не заявляется до отдельной server-side inspection/limit реализации.
