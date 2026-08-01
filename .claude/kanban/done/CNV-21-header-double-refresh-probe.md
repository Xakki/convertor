### Хедер шлёт 4×/api/v1/auth/refresh вместо 2 (лишний round-trip)

**Criticality:** Low

**TAGS:**
- frontend
- tech-debt

**Epic:** [[CNV-47]] — подзадача 12.

**Описание:**
При загрузке `/` анонимным гостем в консоли 4 запроса
`POST /api/v1/auth/refresh` → 401 (тихо обрабатываются, функционально
безвредны). Ожидалось 2 (один из `headerNav().init()`, один из
`converterApp().init()→tryRefresh()`). Дублей `x-data` в DOM нет (проверено
grep) — значит каждый init зовёт refresh дважды или есть ещё один вызыватель.
Найдено при финальной браузерной валидации эпика home (2026-07-21).

**Recommendation:**
Найти источник лишних вызовов (`grep -n "auth/refresh\|tryRefresh" app-symfony/
templates/partials/_converter_app_script.html.twig _header.html.twig`),
дедуплицировать через **shared page-level refresh Promise** (один inflight
refresh на страницу; повторные callers ждут тот же Promise).

**Acceptance Criteria:**
- При загрузке `/` анонимным гостем — не больше одного inflight
  `POST /api/v1/auth/refresh` (shared page-level Promise); callers
  (`headerNav`, `converterApp`, др.) переиспользуют его.
- Функциональность auth/refresh не регрессирует (гость / залогиненный).
- Браузерная проверка: число refresh-запросов на cold load `/` снижено
  (цель — 1, не 4).

**Decisions:**
- (2026-08-01) Shared page-level refresh Promise (дедуп inflight).

**Status:** ready

## Execution Log

- (2026-08-01) Shared `window.sharedAuthRefresh()` in `_header.html.twig` (inflight Promise; body parsed once; cleared after settle).
- Wired: `headerNav().init()`, `converterApp().tryRefresh()`, `dashboardApp().tryRefresh()`.
- admin/base untouched.
- Verification: code review (no JS unit tests); raw fetch only inside sharedAuthRefresh for public layout.
- Commit: frontend shared Promise (Agent: frontend).
