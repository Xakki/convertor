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

**Status:** todo.
