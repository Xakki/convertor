### Хедер шлёт 4×/api/v1/auth/refresh вместо 2 (лишний round-trip)

**Criticality:** Low

**TAGS:**
- frontend
- tech-debt

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
дедуплицировать (общий однократный refresh-промис на страницу). Косметика —
лишние сетевые round-trip'ы, не баг.
