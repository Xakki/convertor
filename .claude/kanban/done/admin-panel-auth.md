### Admin auth: ROLE_ADMIN + /admin-скелет (эпик admin-panel #1)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 1. **HARD-BLOCKS подзадачи 2–6.**

**TAGS:**
- feature

**Description:**
Завести админ-тир поверх текущего JWT-auth и базовый Twig-скелет админки. Всё
остальное в эпике зависит от этого.

**Scope:**
- **User → admin-роль.** Добавить `isAdmin` bool на `User` (`src/Entity/User.php`)
  + миграция; в `getRoles()` (L213-219) ветка → добавлять `ROLE_ADMIN`.
- **JWT-claim.** Убедиться, что роли попадают в JWT (LexikJWT, `config/jwt/`),
  чтобы фронт-мок `role`-логика (`app-front/js/app.js:46`) и серверный гейт
  сходились. Проверить фактический payload (1 строка).
- **security.yaml.** role_hierarchy: `ROLE_ADMIN: [ROLE_USER]`; access_control:
  `^/api/v1/admin` и `^/admin` → `ROLE_ADMIN`.
- **Роут + скелет.** `Controller/Admin/AdminController` c `/admin` (Twig).
  Базовый layout `templates/admin/base.html.twig` (порт дизайна из
  `app-front/admin/index.html`: header, nav, Tailwind/HTMX/Alpine через CDN) +
  пустой dashboard-стаб с nav на будущие панели (stats/users/queues/logs/toggle).
- **Назначение админа.** Console-команда `app:user:make-admin <email|id>` (или
  фикстура) — как выдать флаг первому админу. Без UI-управления ролями.

**Acceptance criteria:**
- [ ] Миграция добавляет `is_admin`; `getRoles()` отдаёт `ROLE_ADMIN` для админа.
- [ ] `^/admin` и `^/api/v1/admin` возвращают 403 для ROLE_USER/ROLE_GUEST, 200
      для админа.
- [ ] `/admin` рендерит Twig-скелет (nav + пустые секции-заглушки).
- [ ] Console-команда выдаёт админку указанному юзеру.
- [ ] `make phpstan` 0, `make cs-check` чисто, PHPUnit-кейс на гейт (403/200).

**Files:** `src/Entity/User.php`, `migrations/`, `config/packages/security.yaml`,
`config/jwt/*` (проверка), `src/Controller/Admin/AdminController.php`,
`templates/admin/base.html.twig`, `src/Command/` (make-admin).

**Execution Log (2026-07-11, agent admin-auth):**

Реализовано на ветке `epic/admin-panel`:
- `src/Entity/User.php` — поле `isAdmin` (bool, default false) + `isAdmin()`/`setIsAdmin()`;
  `getRoles()` добавляет `ROLE_ADMIN` для не-гостя с флагом (гость остаётся только ROLE_GUEST).
- `migrations/Version20260711091528.php` — `ALTER TABLE users ADD is_admin`. Прогнано на
  dev-БД и тест-БД (`make migrate`, `make test-db-setup`).
- `config/packages/security.yaml` — role_hierarchy `ROLE_ADMIN: [ROLE_USER]`; access_control
  `^/api/v1/admin` и `^/admin` → ROLE_ADMIN (admin-правило стоит ДО общего `^/api`).
- `src/Controller/Admin/AdminController.php` — `GET /admin` (Twig), `#[IsGranted('ROLE_ADMIN')]`.
- `src/Controller/Admin/Api/AdminApiController.php` — `GET /api/v1/admin/ping` (JSON, ROLE_ADMIN);
  health-эндпоинт + рабочая база для подзадач 2–6.
- `templates/admin/base.html.twig` + `admin/dashboard.html.twig` — порт дизайна из мока
  (CDN Tailwind/HTMX/Alpine), боковая nav на будущие панели + секции-заглушки.
- `src/Command/MakeAdminCommand.php` — `app:user:make-admin <email|id>` (idempotent).
- `tests/Functional/Security/AdminAccessControlTest.php` — 7 кейсов гейта.

Quality-gate (docker/Makefile):
- `make phpstan` → **0 ошибок**.
- `make cs-check` → **чисто** (0 файлов к правке).
- Новый тест: **7 tests, 21 assertions — OK**. Полный `tests/Functional`+`tests/Unit`:
  **145 tests OK** (регрессий нет).
- make-admin проверен вживую: not-found → error; grant по id → `is_admin=1`; повторный запуск
  → «уже админ». Dev-данные откатаны.

JWT-claim: LexikJWT по умолчанию кладёт роли в claim `roles` (массив) — `ROLE_ADMIN` там есть
(проверено декодом payload в тесте). Кастомный `role`-claim НЕ добавлял: серверный гейт
(`is_granted('ROLE_ADMIN')` + access_control) — источник истины; старый JS-мок читал singular
`payload.role==='admin'` (никогда не совпадал с массивом `roles`) — для Twig-пути не нужен.

Открытый вопрос для тимлида (доставка auth для веб `/admin`): полностраничная навигация браузера
на `/admin` не несёт `Authorization: Bearer`, а access-JWT по контракту живёт в JS-памяти (не в
navigable-cookie, только refresh-token httpOnly). Значит server-side гейт `^/admin` в проде по
навигации недостижим до решения. Рекомендация — **вариант B** (согласован с
`redesign-auth-access-contract`): отдавать admin-shell + client-guard редирект, реальная граница —
`^/api/v1/admin` (JWT, работает уже сейчас). Вариант A (JWT-in-cookie) отклонён как отход от
auth-контракта. Решение — за подзадачами frontend/эпика.

**Execution Log — Option B applied (2026-07-11, agent admin-auth):**

Тимлид подтвердил решение блокера (d): **вариант B** (JSON-API + client-side render).
Корректировки:
- `AdminController` `/admin` — убран `#[IsGranted('ROLE_ADMIN')]`: страница теперь ОТКРЫТАЯ
  оболочка (200 по навигации, секретов не содержит).
- `security.yaml` — убрано правило `^/admin` (делало страницу недостижимой навигацией).
  Оставлено `^/api/v1/admin` → ROLE_ADMIN + role_hierarchy `ROLE_ADMIN: [ROLE_USER]`.
- `admin/base.html.twig` — минимальный client-guard-стаб: тянет access-JWT через
  `POST /api/v1/auth/refresh`, декодит `roles`, при отсутствии ROLE_ADMIN → редирект на `/`.
  Полный shared client-setup (Bearer в fetch/htmx через `htmx:configRequest` + 401/403) —
  TODO следующей подзадачи `admin-panel-stats`, не здесь.
- Единственная реальная граница — admin-API (`^/api/v1/admin`, JWT). Публичный auth-контракт
  не тронут, navigable JWT-cookie НЕ добавлялся.

Quality-gate: `make phpstan` → **0**; `make cs-check` → **чисто**; `AdminAccessControlTest`
**7 tests / 23 assertions — OK** (веб `/admin` = 200 для всех, admin-API = 401 без auth /
403 не-админ / 200 админ); полный `make test-php` — **149 tests OK**, регрессий нет.

**Status:** ready (ревью APPROVE; в done — вместе с эпиком).
