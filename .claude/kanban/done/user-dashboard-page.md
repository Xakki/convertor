### Личный кабинет: полноценная страница (история + квоты + аккаунт)

**Criticality:** Medium

**TAGS:**
- feature
- frontend
- backend

**Description:**
Бэкенд для дашборда пользователя уже есть (`GET /api/v1/convert/history`,
`GET /api/v1/quota`), и существует статичный мок `app-front/dashboard.html`, но
в Symfony **нет отдаваемой** пользовательской страницы кабинета/аккаунта —
только страница загрузки `/`. ROADMAP Stage 5 помечает «История конвертаций со
ссылками (S3 presign)» как невыполненную (ROADMAP.md:106), а Dashboard/Profile —
как reference-only.

**ВАЖНО:** минимальный вариант «список истории на главной» чинится в этой сессии
ОТДЕЛЬНО. Эта карта — про **полный личный кабинет**: выделенная страница
(история + квоты/лимиты + аккаунт + повтор/удаление конверсий), а не про
список на главной.

**Problem:**
Нет served-страницы кабинета: пользователь не может в одном месте видеть
историю (с presign-ссылками), свои квоты/лимиты, данные аккаунта и управлять
конверсиями (повтор/удаление).

**Impact:**
Пропущенная функциональность Stage 5. Данные и эндпоинты есть, но пользователю
недоступны через UI (только статичный мок, не отдаётся Symfony).

**Recommendation:**
Реализовать served-страницу личного кабинета в Symfony поверх существующих
эндпоинтов:
- история конверсий со ссылками (S3 presign) — ROADMAP.md:106;
- квоты/лимиты (`GET /api/v1/quota`);
- данные аккаунта;
- действия: повтор конверсии, удаление.
Опереться на мок `app-front/dashboard.html`, эндпоинты `convert/history` +
`quota`, и контракт [[redesign-auth-access-contract]] (гейтинг ролей/гостя).

**Acceptance Criteria:**
- Symfony отдаёт страницу кабинета `/dashboard`.
- Отображаются: история конверсий, квоты/лимиты, данные аккаунта.
- Из истории доступны скачивание результата / превью / исходник — через
  существующие прокси-эндпоинты (`/api/v1/convert/{id}/download|preview|source`).
- Гейтинг доступа согласован с [[redesign-auth-access-contract]] (guest vs user) —
  зависимость удовлетворена — redesign-auth-access-contract это skill
  (auth-редизайн реализован), не карточка.
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
Стек — HTMX/Alpine поверх существующих JSON-эндпоинтов
(`/api/v1/convert/history`, `/api/v1/quota`, `/api/v1/me`) по фронт-правилам
проекта (HTMX для динамики; без тяжёлого SPA). Отдельная served-страница
`/dashboard` (или `/account`). Скоуп первой итерации — ТОЛЬКО просмотр: история
(с ссылками source/preview/download — переиспользовать уже готовые эндпоинты и
паттерны с главной), квоты/лимиты (`/quota`), данные аккаунта (`/me`). Гость
(`ROLE_GUEST`) — показываем приглашение войти + свою guest-историю если есть;
перенос истории при логине уже покрыт auth-контрактом. Действия
повтор/удаление конверсий — ВНЕ этой задачи (отдельная карточка позже).

Уточнено 2026-07-22 (по итогам разведки кода):
- **Противоречие AC↔Decisions снято** в пользу Decisions: первая итерация —
  только просмотр. Повтор/удаление вынесены в карточку
  `dashboard-conversion-actions` (grooming).
- **Presign-ссылок в проекте нет** — «presign» в AC/ROADMAP устаревшая
  формулировка. Все файлы отдаются прокси-стримом через PHP с Bearer
  (`S3Storage::downloadResponse`, `authFetch` → blob). Переиспользуем это.
- **Фронт — Alpine + fetch**, не HTMX: главная (`/`) не использует HTMX вовсе,
  API отдаёт JSON, а не HTML-фрагменты, и нужен Bearer-заголовок. HTMX в
  проекте живёт только в админке. Строка CLAUDE.md про HTMX — аспирационная.
- **Серверный гейт по роли на страничных роутах невозможен**: firewall `main`
  без аутентификатора, `app.user` в Twig всегда null. Страница — открытая
  оболочка + клиентская проверка (`/auth/refresh` → `/me`), как на `/` и
  `/admin`.
- **Пагинация** — «Загрузить ещё» (`limit`/`offset`); total/page-count API не
  отдаёт, менять бэкенд в этой итерации не будем.

**Контекст:** ROADMAP Stage 5 (ROADMAP.md:106), мок `app-front/dashboard.html`
(stale, тёмная тема, htmx ждёт HTML-фрагменты — только визуальный референс),
эндпоинты `GET /api/v1/convert/history` + `GET /api/v1/quota` + `GET /api/v1/me`.

**Execution Log:**
- Ветка `task/user-dashboard-page`. Коммиты: `3c6eb13` (страница), `8d735d9`
  (фикс порядка загрузки квоты).
- Создано: `src/Controller/Web/DashboardController.php` (`GET /dashboard`,
  `app_dashboard`), `templates/dashboard/index.html.twig`,
  `templates/partials/_dashboard_app_script.html.twig` (Alpine-компонент
  `dashboardApp()`), `tests/Functional/Controller/Web/DashboardControllerTest.php`.
- Изменено: `templates/partials/_header.html.twig` (ссылка на кабинет в блоке
  `x-show="loggedIn"`), `translations/messages.{en,ru}.yaml` (неймспейс
  `dashboard.*` + `nav.dashboard`).
- Превью-модалка `partials/_converter_preview_modal.html.twig` переиспользована
  как есть; `authFetch`/`tryRefresh`/`filenameFrom`/`humanSize` минимально
  продублированы в новый скрипт — рефактор скрипта главной не делался, чтобы не
  сломать `/`.
- Найден и исправлен баг: `loadQuota()` вызывался до резолва `tryRefresh()` —
  залогиненный пользователь видел гостевые лимиты рядом с корректной карточкой
  аккаунта.
- QA: `make phpstan` → No errors; `make cs` → Fixed 0 of 197; `make cs-check` →
  0 of 197; `make test-php-live` → OK, 417 tests / 1712 assertions.
- Ревью: APPROVE, замечаний нет.

**Не проверено в рантайме (на момент сдачи):**
`make phpstan`/`cs-check` видят только тривиальный новый PHP, а функциональный
тест — это `WebTestCase`, он НЕ исполняет JS. Вся клиентская логика страницы
(порядок `tryRefresh`→`/quota`, загрузка истории, скачивание/превью/исходник,
«загрузить ещё», рендер локалей) подтверждена только статическим ревью и
HTTP-проверками, но не прогоном в браузере: chrome-devtools-mcp был заблокирован
параллельной сессией (общий профиль chrome). Браузерный смоук четырёх состояний
(аноним / гость / залогиненный / пагинация) остаётся к выполнению.

**Status:** done.
