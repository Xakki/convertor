### CNV-110 — Frontend: вкладки истории Web-конверсий и API-запросов

**Criticality:** Medium

**TAGS:**
- frontend
- history
- dashboard
- api

**Description:**
Frontend-разработчик разделяет `/history` на вкладки «Web-конверсии» и «API-запросы»;
использует существующий conversion-history contract и audit-history endpoint CNV-109.

**Problem:**
Пользователь не может отличить результат web-конверсии от действий внешнего клиента,
а отображение transport audit вместе с `Conversion` смешивает разные сущности.

**Impact:**
Диагностика API-клиента невозможна без нарушения понятности существующей web-истории.

**Recommendation:**
Оставить «Web-конверсии» активной по умолчанию и неизменной по данным/pagination.
Добавить отдельную «API-запросы» с независимым newest-first paginated list и безопасными
metadata из CNV-109.

**Acceptance Criteria:**
- `/history` по умолчанию открывает «Web-конверсии» и не регрессирует существующий
  endpoint, список или pagination web-конверсий.
- «API-запросы» запрашивает только audit-history contract CNV-109, поддерживает его
  детерминированную pagination и показывает empty state.
- В UI API-запроса отображаются лишь безопасные metadata: method, normalized route,
  status, duration, token label/mask и optional conversion ID.
- Гость не видит вкладку API-запросов и не делает audit-history request; существующая
  guest web-history остаётся без изменений.
- DOM, client state, error UI и telemetry не выводят token plaintext, Authorization,
  body, file, IP или UA.
- Frontend tests зелёные для default tab, pagination, empty state, guest state и
  redaction.

**Decisions:**
- **Владелец:** frontend-разработчик.
- **Зависимость:** CNV-109 предоставляет owner-scoped audit-history endpoint.
- Вкладки размещаются в `/history`; dashboard сохраняет краткий список без дублирования
  tabbed UI.
- Эта карточка не создаёт backend storage, endpoint, retention или OpenAPI areas.
