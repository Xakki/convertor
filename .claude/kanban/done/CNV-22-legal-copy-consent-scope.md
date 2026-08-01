### Легал: формулировка cookie-consent устарела («на главной странице»)

**Criticality:** Low

**TAGS:**
- docs
- frontend

**Epic:** [[CNV-47]] — подзадача 4.

**Description:**
Ключ `legal.privacy.data_consent_cookie` в `translations/messages.{en,ru}.yaml`
описывает cookie-consent как относящийся «к главной странице». После появления
кабинета `/dashboard` (2026-07-22) гостевая история грузится и там, по тому же
согласию — формулировка стала неточной.

**Recommendation:**
Переформулировать текст согласия так, чтобы он покрывал сервис на устройстве,
а не только главную. Проверить заодно остальные тексты в `legal.*` на привязки
к конкретной странице.

**Acceptance Criteria:**
- Формулировка согласия (RU+EN) использует смысл «на этом устройстве в сервисе»
  (не привязана к конкретной странице).
- Проведен аудит прочих `legal.*` page-bound фраз; устаревшие привязки к
  страницам исправлены или зафиксированы.
- Тексты обновлены в обоих файлах локалей (`messages.en.yaml`, `messages.ru.yaml`).

**Decisions:**
- (2026-08-01) Редакторский MVP: формулировка «на этом устройстве в сервисе»
  (RU+EN); юридическая вычитка / counsel gate НЕ требуется.
- Аудит остальных `legal.*` page-bound фраз — в scope этой карточки.
- Audit: self-referential «на этой странице» in intro/changes kept; limits_text fixed as page-bound.

**Контекст:** обнаружено при реализации `user-dashboard-page`.

**Status:** ready.

## Execution Log
- (2026-08-01) todo→progress.
- (2026-08-01) Reformulated `legal.privacy.data_consent_cookie` RU+EN: «на этом устройстве в сервисе» / «on this device in the service».
- (2026-08-01) Audit `legal.*`: also fixed page-bound `legal.terms.limits_text` (home→«в интерфейсе сервиса» / «in the service interface»). Kept OK: privacy.intro / terms.changes_text («на этой странице» = self-ref), rights_text («страницу самообслуживания» = future feature).
- (2026-08-01) Synced comments in messages.ru.yaml + `_cookie_consent.html.twig`; bumped `legal.updated_on` → 1 Aug 2026.
- (2026-08-01) Commit `5d904fba` (docs). Grep: homepage-bound phrases gone from translations+twig.
- (2026-08-01) progress→test→ready. Copy-only — no make test.
