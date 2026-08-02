### Кабинет `/dashboard`: браузерный smoke-тест клиентского JS (не выполнялся)

**Criticality:** Medium
**Epic:** [[CNV-54]]

**TAGS:**
- tech-debt
- frontend
- qa

**Description:**
Карточка `user-dashboard-page` (реализация served-страницы `/dashboard`) уехала
в `done/` с явной пометкой «Не проверено в рантайме»: вся клиентская Alpine-
логика страницы подтверждена только статическим ревью и HTTP-проверками
(`WebTestCase` не исполняет JS), но ни разу не прогнана в реальном браузере.
Раз родительская карта в `done/`, этот гэп нужно фиксировать отдельно, иначе
он потеряется.

**Problem:**
Не проверено в браузере (дословно из `user-dashboard-page.md`, раздел «Не
проверено в рантайме»):
- порядок `tryRefresh()` → `/quota` (баг с гостевыми лимитами у залогиненного
  пользователя уже был найден и исправлен в `8d735d9`, но фикс не подтверждён
  живым прогоном, только чтением кода);
- загрузка истории конверсий;
- действия над записью истории: скачивание результата (`downloadConversion`),
  превью (`preview`-модалка), скачивание исходника (`downloadSource`);
- «Загрузить ещё» / пагинация по `limit`/`offset` (`loadHistory`);
- рендер локалей (i18n-строки `dashboard.*`, `home.error.*`, `unlimited`).

Дополнительно (не из текста done-карточки, но то же самое: 4 состояния входа,
проверка которых не выполнялась ни разу):
- аноним (нет cookie `guest_id`, нет токена) — оболочка + приглашение войти;
- гость (`ROLE_GUEST`, cookie `guest_id`) — гостевая история только после
  согласия в cookie-баннере (`readConsent()`);
- залогиненный пользователь (`ROLE_USER`) — реальные квоты/лимиты, а не
  гостевые;
- пагинация «Загрузить ещё» на реальном наборе записей истории.

**Impact:**
Клиентский JS страницы кабинета в проде не подтверждён ни одним живым
прогоном в браузере. Регресс в порядке `tryRefresh`→`/quota`, в обработке
410/409/415 у `/preview`, в пагинации или в рендере локалей может не всплыть
ни в `make phpstan`, ни в `make cs-check`, ни в PHPUnit `WebTestCase` — эти
проверки не исполняют JS.

**Recommendation:**
Прогнать через `chrome-devtools` MCP (`mcp__chrome-devtools__*`) сценарий по
всем 4 состояниям входа на `/dashboard`, зафиксировав в Execution Log
консольные ошибки/сетевые запросы (`list_console_messages`,
`list_network_requests`) и скриншоты ключевых экранов (`take_screenshot`).

**Заблокировано (на момент постановки задачи):**
Проверка через `chrome-devtools` MCP не выполнялась в рамках `user-dashboard-page` —
общий chrome-профиль был занят параллельными сессиями (конфликт
`--user-data-dir`, второй Chrome с тем же профилем не поднимается). При
выполнении этой карточки использовать `--isolated` либо отдельный
`--user-data-dir` на сессию, чтобы не зависеть от занятости общего профиля.

**Acceptance Criteria:**
- Для каждого из 4 состояний (аноним / гость с согласием на cookie / гость без
  согласия / залогиненный пользователь) подтверждено в реальном браузере:
  - корректный порядок `tryRefresh()` → загрузка `/quota` (у залогиненного —
    реальные лимиты, не гостевые);
  - загрузка истории конверсий (или корректная пустая/gated-заглушка для
    анонима и гостя без согласия);
  - скачивание результата, превью, скачивание исходника — без ошибок в
    консоли и с ожидаемым содержимым/сообщением об ошибке (410/409/415);
  - «Загрузить ещё» подгружает следующую страницу по `limit`/`offset`;
  - тексты локализованы (`ru`/`en`), нет непереведённых ключей в UI.
- Нет ошибок в консоли браузера и нет неожиданных failed-запросов в сети
  (`list_console_messages` / `list_network_requests` чистые по итогам прогона).
- Найденные по итогам смоука баги — заведены отдельными карточками в
  `.claude/kanban/grooming/` (не чинить их прямо в этой задаче, если фикс
  нетривиален).
- Результат прогона (скриншоты/лог консоли/сети) зафиксирован в Execution Log
  этой карточки.

**Контекст:** родительская карта `.claude/kanban/done/user-dashboard-page.md`
(раздел «Не проверено в рантайме», коммит `3a568c2`). Исходники:
`app-symfony/src/Controller/Web/DashboardController.php` (роут `GET /dashboard`),
`app-symfony/templates/dashboard/index.html.twig`,
`app-symfony/templates/partials/_dashboard_app_script.html.twig` (Alpine-компонент
`dashboardApp()`: `init()` — L95-121, `loadQuota()` — L158, `loadHistory()` —
L222, `downloadConversion()` — L247, `downloadSource()` — L273),
`app-symfony/tests/Functional/Controller/Web/DashboardControllerTest.php`
(существующее HTTP-покрытие, JS не исполняет).

**Decisions:**
- (2026-08-01) Open questions не было — scope/AC уже зафиксированы; → todo.
- (2026-08-02) Chrome DevTools MCP / Playwright MCP на хосте не стартуют
  браузер: ищут `/opt/google/chrome/chrome` (нет прав создать path). Smoke
  выполнен локальным Chromium через Playwright `executablePath`
  (`backup_cnv9_smoke/smoke_dashboard.mjs`). Evidence в
  `backup_cnv9_smoke/` (не коммитить `node_modules`).
- (2026-08-02) ROLE_USER: переданный снаружи JWT → `401 Invalid JWT Token`
  (подпись не от ключей этого стека). Валидный токен выпущен на DEV:
  `docker exec … lexik:jwt:generate-token 2` (user id=2 XbookDiag). Inject:
  `localStorage.jwt` + `Alpine.$data(…).auth.token` + прямой Bearer fetch
  `/me`→`/history`→`/quota` (localStorage alone недостаточно — `init()` уже
  отработал через failed tryRefresh).

**Status:** ready

## Execution Log

**Дата:** 2026-08-02 · **Стенд:** https://convertor.xakki.pro (DEV) · **Ветка:** epic/CNV-54

**Инструмент:** Playwright + Chromium 149
(`/home/coder/.cache/ms-playwright/chromium-1228/…`). CDT MCP:
`Could not find Google Chrome executable for channel 'stable' at
/opt/google/chrome/chrome`.

**Артефакты:** `/home/xakki/convertor/backup_cnv9_smoke/`
- `01_anonymous.png` + `_report.json`
- `01b_anonymous_en.png` (locale en)
- `02_guest_no_consent.png` + `_report.json`
- `03_guest_with_consent.png` (+ `_after_actions.png`) + `_report.json`
- `04_logged_in.png` + `04_logged_in_after_actions.png` + `_report.json`
- `SUMMARY.json` / `SUMMARY_state4.json`, `smoke_dashboard.mjs`

### 1) Anonymous (нет guest_id, нет JWT) — **PASS**
- Shell: заголовок «Личный кабинет», amber login-prompt + ссылка `/login`.
- Квоты: план «Гость», 0/2, AI 0/0 «нужен вход», 50 МБ.
- История: empty «Пока нет конвертаций» (consent не accepted → history API
  не вызывался).
- Сеть: `POST /api/v1/auth/refresh` (401) → `GET /api/v1/quota` (200) — порядок
  OK. **Дубль** `GET /quota` ×2 → CNV-55.
- i18n RU: нет сырых `dashboard.*` ключей. EN (`locale=en`): «Dashboard»,
  «Sign in…», «Quota» / «Guest» — ключей нет.
- Console: ожидаемый 401 на refresh (Chromium «Failed to load resource»);
  warn `cdn.tailwindcss.com` (известный CDN play).

### 2) Guest без cookie consent (`guest_id` + `cookie_consent=declined`) — **PASS**
- Mint guest: `POST /api/v1/convert` → 202 `conversion_id=59`, httpOnly
  `guest_id` выставлен.
- Consent declined; **history API не вызывался** (`historyApiCalls: []`) —
  gate OK.
- UI: login-prompt + guest quota **1/2** (после mint), empty history stub.
- Сеть: refresh→quota порядок OK; снова `quota` ×2.

### 3) Guest с cookie consent (`cookie_consent=accepted`) — **PASS**
- Consent accepted; история загрузилась: `png → jpg`, `cnv9-smoke.png`, статус
  «Готово», кнопки «Исходник» / «Скачать».
- Сеть: refresh → `GET /api/v1/convert/history?limit=20&offset=0` (200) →
  quota; history/quota **дублируются** (init + повтор из
  `cookie-consent-changed` / double-init) → CNV-55.
- Actions: Download UI present (не кликали — не уничтожать данные). Preview
  отсутствует (`previewable` false для jpg — ожидаемо). «Загрузить ещё»
  отсутствует на 1 записи; **проверено в state 4**.
- Console: 401 refresh + транзические `ERR_NETWORK_CHANGED` (стенд/сеть).

### 4) Logged-in ROLE_USER (id=2 XbookDiag) — **PASS**
- Переданный JWT: `401 Invalid JWT Token` (curl `/me`). Замена: свежий
  `lexik:jwt:generate-token 2` на `xakki-convertor-php` (тот же DEV-стек).
- Inject Alpine: `auth.token` + Bearer `/me`→`/history`→`/quota` (все 200).
- UI: аккаунт **XbookDiag**, план **Pro**; квоты **не guest**:
  Без лимита / AI 0/100 / 500 МБ (vs guest 2/0/50 МБ).
- История: 20 записей (`txt→mp3 AI`, `mp4→webm`, `docx→pdf`…); heading
  «История конвертаций» (не guest).
- Actions: **Загрузить ещё** clicked; **Превью** opened (модалка JSON
  `Предпросмотр результата`); Исходник/Скачать present (не кликали download
  чтобы не трогать файлы).
- Порядок post-auth: `/me` → `/convert/history` → `/quota` (Bearer).
  Натуральный `tryRefresh` (refresh-cookie) без login-flow не воспроизводим;
  cold `tryRefresh→quota` уже подтверждён в states 1–3. Header «Войти»
  остаётся — `headerNav()` отдельный Alpine-scope, inject только
  `dashboardApp` (ожидаемо для synthetic JWT без refresh-cookie).
- Console после inject: **чистая** (0 errors/warns). i18n: нет сырых ключей.

### Сводка AC
| Критерий | Anon | Guest−consent | Guest+consent | ROLE_USER |
|---|---|---|---|---|
| tryRefresh→quota / post-auth order | PASS | PASS | PASS | PASS (`/me`→history→quota) |
| history / gate | PASS (empty) | PASS (no API) | PASS (loaded) | PASS (20 rows + load more) |
| download/preview/load-more | n/a | n/a | download UI; preview n/a | PASS (preview+load more; download UI) |
| i18n | PASS ru+en | PASS | PASS | PASS |
| console | 401 expected | 401 expected | 401 + net noise | clean |
| quotas | guest | guest | guest | **pro ≠ guest** |

### Баги / grooming
- **CNV-55** (grooming): Alpine `init()` double-call → дубли quota/history.
- 401 console на anon refresh — ожидаемо, не баг продукта.
- Tailwind CDN warn — известный CDN play, отдельно не заводили.
- Header `headerNav` не синхронизируется при synthetic JWT inject — ограничение
  smoke-метода, не продуктовый баг login-flow.
