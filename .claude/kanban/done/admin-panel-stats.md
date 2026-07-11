### Admin stats dashboard (эпик admin-panel #2)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 2. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Dashboard с реальными метриками конвертаций и юзеров. Revenue — плейсхолдер-0
(платежи не персистятся, см. эпик).

**Shared client-setup (делается здесь, переиспускается всеми панелями — см.
эпик, Option B):** в `templates/admin/base.html.twig` добавить: (а) admin-guard
редирект (refresh→access-JWT, `roles` содержит `ROLE_ADMIN`, иначе на логин),
(б) инъекцию `Authorization: Bearer` во все fetch/htmx (`htmx:configRequest` +
общий fetch-wrapper), (в) 401/403 → redirect. Последующие подзадачи это НЕ
переделывают.

**Scope:**
- **Aggregate-запросы** в `ConversionRepository` (сейчас только
  findByUser/findPending/countTodayByUser): конвертаций/день (ряд по датам),
  by-status, by-format (from→to), AI vs non-AI, avg `processingMs`, error-rate
  (`status=Failed`). Поля есть: `Conversion` `status/category/fromFormat/toFormat/
  isAi/isOcr/processingMs/createdAt` (`src/Entity/Conversion.php:34-62`).
- **User-метрики** в `UserRepository`: всего/активных/guest, signups по датам
  (`User.createdAt/isActive/isGuest/plan`).
- **Revenue = плейсхолдер:** плитка отдаёт 0 / «n/a» (провода на Payment позже).
- **API:** `GET /api/v1/admin/stats` (JSON) — агрегаты выше. (ROADMAP.md:211.)
- **UI:** Twig-панель `templates/admin/stats.html.twig`, графики (Chart.js как в
  моке), HTMX-перезагрузка блоков. Порт из `app-front/admin/index.html`.

**Acceptance criteria:**
- [x] `/admin` (stats-секция) показывает реальные conversion/user метрики.
- [x] Revenue-плитка = 0/«n/a», не падает.
- [x] `GET /api/v1/admin/stats` под ROLE_ADMIN; 403 иначе.
- [x] Aggregate-запросы покрыты PHPUnit (на сид-данных).
- [x] `make phpstan` 0, `make cs-check` чисто.

**Files:** `app-symfony/src/Controller/Admin/Api/StatsController.php`,
`app-symfony/src/Repository/{Conversion,User}Repository.php`,
`app-symfony/templates/admin/stats.html.twig`,
`app-symfony/templates/admin/{base,dashboard}.html.twig` (shared client-setup),
`app-symfony/tests/Functional/{Repository/AdminStatsRepositoryTest,Controller/Admin/StatsControllerTest}.php`.

**Execution Log (2026-07-11):**
- **Part 1 — shared client-setup** (`templates/admin/base.html.twig`): заменён
  минимальный guard-стаб на полный слой `window.admin = { fetch, jwt, refresh,
  ready }`. admin-guard (refresh→access-JWT, проверка `ROLE_ADMIN`, иначе
  redirect `/`); Bearer-инъекция в htmx (`htmx:configRequest`) и в общий
  `admin.fetch`; 401/403 → один refresh, иначе redirect. Для htmx-панелей —
  событие `admin:ready` + `data-admin-ready="1"` на `<body>` (панели вешают
  `hx-trigger="load once, admin:ready from:body"`). Публичный JWT-контракт не
  тронут: access-JWT только в памяти JS.
- **Part 2 — stats**: агрегаты в `ConversionRepository` (countTotal/countToday/
  seriesByDay/countByStatus/topFormatPairs/countByAi/avgProcessingMs/errorRate)
  и `UserRepository` (countAll/countActive/countGuests/signupsByDay). Группировки
  — QueryBuilder; per-day-ряд — параметризованный нативный SQL (в DQL нет
  `DATE()`; тест-БД MariaDB). Empty-window guard: null AVG→null, total=0→rate 0.0.
  `GET /api/v1/admin/stats` — `StatsController` (`#[IsGranted('ROLE_ADMIN')]`,
  `days` clamp 1..90, revenue-плейсхолдер `{value:0,label:'n/a',placeholder:true}`).
  UI — `templates/admin/stats.html.twig` (Alpine + Chart.js), включён в overview-
  секцию dashboard; Chart.js через `head_scripts`.
- **Deviation:** per-day-ряд — нативный SQL (карточка просила DQL/QueryBuilder;
  `DATE()` в DQL недоступен), параметризован, снейк-кейс колонок verbatim.
- **Quality-gate:** `make phpstan` → OK, 0 errors. `make cs-check` → 0 fixable.
  `make test-php-live` → 156 tests, 640 assertions, OK (было 149; +7 новых:
  3 repo-delta + 4 controller). Единственный PHPUnit-notice — пре-существующий
  (был в baseline). Смоук: `/admin` рендерит `window.admin`, `admin:ready`,
  `htmx:configRequest`, `id="overview"`, `adminStats()`, Chart.js CDN,
  `/api/v1/admin/stats`.

**Shared JS-surface для последующих панелей** (users/queues/logs/toggle; base НЕ
трогать): `window.admin.fetch(url, opts)` — JSON-fetch с Bearer + refresh/redirect;
`window.admin.jwt`; `window.admin.refresh()`; `window.admin.ready` (Promise<bool>);
событие `admin:ready` на `<body>` + `data-admin-ready="1"` для htmx-триггеров.

**Review-нит-фиксы (2026-07-11, APPROVE WITH NITS):**
- **htmx retry симметричен admin.fetch:** `htmx:responseError` на 401/403 теперь
  делает refresh И ПЕРЕИГРЫВАЕТ упавший запрос (`htmx.ajax` по `requestConfig`),
  redirect только при провале refresh. Loop-guard: `data-admin-auth-retry` на
  элементе (снимается на `htmx:afterRequest` при успехе) — второй подряд 401/403
  после refresh → redirect, без бесконечного цикла.
- **РЕКОМЕНДУЕМЫЙ htmx-триггер для панелей — `admin:ready from:body` (БЕЗ
  `load`):** `load` бьёт синхронно на init htmx до появления Bearer =
  гарантированный первый 401; `admin:ready` (шлётся guard'ом раз после первичного
  refresh) покрывает первую загрузку. Старая рекомендация `load once, …` убрана
  из shared-контракта в base.html.twig.
- **tz-заметка** на `ConversionRepository::seriesByDay` и
  `UserRepository::signupsByDay` (без правки запроса): `:start` в PHP-tz против
  `DATE(created_at)` в tz БД — корректно, пока зоны совпадают.
- Гейт: `make phpstan` OK (0), `make cs-check` 0, `make test-php-live` 156 tests /
  640 assertions OK. Смоук `/admin`: `htmx:afterRequest`+`window.htmx.ajax`
  присутствуют, `load once, admin:ready` — 0 вхождений, stats-панель грузится.
- Stats-панель нит-2 не затронут: она тянет данные `admin.fetch`+Alpine
  `x-init` (await `admin.ready`), htmx-`load`-триггер не использует.

**Status:** ready (ревью APPROVE WITH NITS; ниты закрыты afb7667; в done — с эпиком)
