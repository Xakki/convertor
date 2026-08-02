### Alpine `init()` вызывается дважды: `x-init="init()"` + magic `init()`

**Criticality:** Medium
**Epic:** —

**TAGS:**
- bug-fix
- frontend
- tech-debt

**Description:**
Найдено при browser smoke CNV-9 (`/dashboard` на prod). Alpine.js 3
автоматически вызывает метод `init()` у компонента; плюс в шаблонах стоит
явный `x-init="init()"`. В сети видно дубли `GET /api/v1/quota` и
`GET /api/v1/convert/history` с одним timestamp.

**Problem:**
Паттерн `x-data="…()" x-init="init()"` + метод `init()` внутри data-object
используется минимум на:
- `dashboard/index.html.twig` (`dashboardApp`)
- `conversion/index.html.twig`, `conversion/pair.html.twig`
- `partials/_header.html.twig` (`headerNav`)
- `partials/_cookie_consent.html.twig`
- admin: workers/examples/queues/logs/users/stats/toggle

На `/dashboard` smoke зафиксировал:
`POST /auth/refresh` → `GET /quota` ×2 (anon/guest без consent);
с consent: `GET /convert/history` ×2 и `GET /quota` ×2.

**Impact:**
Лишняя нагрузка на API при каждом page-load; при росте трафика — удвоенные
запросы к quota/history/me. Поведение в целом корректное (идемпотентно), но
шум в Network и риск гонок при будущих сайд-эффектах в `init()`.

**Recommendation:**
Выбрать один механизм:
1. Убрать `x-init="init()"` везде, где есть magic-method `init()` (предпочтительно
   для Alpine 3), **или**
2. Переименовать метод (например `boot()`) и оставить только явный `x-init`.

Проверить на `/`, `/dashboard`, admin после правки (один refresh → один quota/
history). CDN: `unpkg.com/alpinejs@3.x.x`.

**Acceptance Criteria:**
- На `/dashboard` cold load: ровно один `GET /api/v1/quota` после
  `POST /api/v1/auth/refresh` (anon); при consent — ровно один
  `GET /api/v1/convert/history`.
- То же для `/` (`converterApp`) и header (без лишнего второго `/me` refresh
  cascade, если применимо).
- Нет регрессии логина/квот/истории; `make cs-check` не требуется для twig-only,
  но browser re-smoke точечный.

**Open questions:**
- Чинить сразу все шаблоны одним PR или только dashboard/home сначала?
- Оставляем magic `init()` (убрать `x-init`) или наоборот rename?

**Decisions:**
- (2026-08-02) Заведено из CNV-9 smoke; в рамках CNV-9 не чинили (oos smoke).
