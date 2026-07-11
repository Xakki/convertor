### Admin user management (эпик admin-panel #3)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 3. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Управление юзерами: поиск, ban/unban, ручной сброс квоты, смена плана. Все сеттеры
на `User` уже есть — не хватает admin-эндпоинтов и UI.

**Scope:**
- **Поиск/список:** `UserRepository` — поиск по email/telegram-id/id + пагинация.
- **Ban/unban:** через `User.isActive` (`src/Entity/User.php:177-187`) —
  authenticator уже фильтрует неактивных. Richer status (banReason/bannedAt) —
  out of scope (см. эпик).
- **Ручной reset квоты:** обнулить `dailyConversions/dailyAiConversions` +
  bump `quotaResetAt` (переиспользовать/дёрнуть `QuotaService`). NB: если в работе
  карта [[quota-service-hardening]] — не дублировать логику счётчиков, звать сервис.
- **Смена плана:** `User.setPlan(<free|basic|pro>)` с валидацией против `plans`.
- **API:** `GET /api/v1/admin/users` (поиск), `POST .../users/{id}/ban`,
  `.../unban`, `.../reset-quota`, `.../plan`.
- **UI:** `templates/admin/users.html.twig` — таблица + Alpine-модалки действий,
  HTMX-submit.

**Acceptance criteria:**
- [ ] Поиск юзеров по email/tg-id/id с пагинацией.
- [ ] Ban → `isActive=false`; забаненный не проходит auth. Unban обратно.
- [ ] Reset квоты обнуляет счётчики; смена плана меняет `plan` (валидный).
- [ ] Все эндпоинты под ROLE_ADMIN; 403 иначе.
- [ ] PHPUnit на ban/unban/reset/plan; `make phpstan` 0, `make cs-check` чисто.

**Files:** `src/Controller/Admin/UserController.php`,
`src/Repository/UserRepository.php`, `src/Service/Quota/QuotaService.php` (reuse),
`templates/admin/users.html.twig`.

**Status:** todo.
