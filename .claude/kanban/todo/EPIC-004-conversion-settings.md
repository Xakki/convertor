### Backend-контракты каталога конвертаций

**Criticality:** High

**TAGS:**
- feature
- api
- frontend
- validation
- conversion-options

**Description:**
Реализовать backend/API profiles, validation, catalog и normalization для всех
последующих conversion domains.

**Problem:**
Настройки сейчас image-only, а три доменные задачи без общего контракта продублируют
UI, server validation, plan policy и job payload.

**Impact:**
Нельзя безопасно и единообразно добавлять параметры в новые conversion categories.

**Recommendation:**
Выполнить CNV-85 первым, затем по одному domain profile: document, audio/video и
CSV/JSON. Все options остаются profile-derived и server-side whitelisted.

**Acceptance Criteria:**
- Выполнены AC CNV-85, CNV-76, CNV-77 и CNV-78; raw renderer/FFmpeg/LibreOffice/
  serializer arguments отсутствуют, plan policy проверяется сервером.
- pytest, `make test` и `make build` зелёные после интеграции.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Общая грамматика и catalog принадлежат CNV-85; доменные карточки добавляют только
  собственные profiles и применение нормализованных options worker-ом.

**Subtasks:**
- CNV-85 — backend catalog и normalization
- CNV-95 — static SVG backend catalog
- CNV-97 — document backend profile
- CNV-100 — media backend profile
- CNV-103 — data backend profile
- CNV-106 — animated SVG backend profile

**Integration checklist:**
- Проверить document, audio/video и CSV/JSON fixtures, plan policy и отказ
  невалидных preset’ов.
- Выполнить pytest, `make test` и `make build`.
