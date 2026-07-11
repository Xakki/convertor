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

**Status:** ready (ревью APPROVE WITH NITS; в done — с эпиком)

**Execution Log:**
- Ветка `epic/admin-panel`. Схему не трогали — `isActive`/`plan`/квота-поля уже есть.
- `UserRepository::searchPaginated(?q, limit, offset)` — поиск по email (LIKE
  `%q%`), telegramId (exact), id (exact при числовом q); пустой q → весь список;
  всё параметризовано; возвращает `{items, total}` (total по тем же условиям).
- `UserController` (`#[IsGranted('ROLE_ADMIN')]`, `/api/v1/admin/users`):
  - `GET ''` — пагинация с метаданными `{items, page, pageSize, total, pages}`
    (PAGE_SIZE=20); поля юзера: id/email/telegramId/plan/isActive/isGuest/isAdmin/
    dailyConversions/dailyAiConversions/quotaResetAt/createdAt.
  - `POST {id}/ban` → `setIsActive(false)`, `POST {id}/unban` → `true` (200; 404
    на несуществующего). Бан не мгновенный: stateless access-JWT живёт до
    истечения (≤1ч), блокировка бьёт при следующем `POST /api/v1/auth/refresh`
    (там `isActive`-guard уже есть, покрыт `AuthRefreshControllerTest`).
  - `POST {id}/reset-quota` → `QuotaService::reset()` (счётчики→0 + bump
    quotaResetAt + flush). ДОБАВЛЕН метод `QuotaService::reset(User, flush=true)`:
    вынес безусловное обнуление из `resetIfNeeded` в единую точку, контроллер
    логику счётчиков НЕ дублирует. (`quota-service-hardening` в `todo/`, не
    in-flight — конфликта нет.)
  - `POST {id}/plan` (JSON `{plan}`) → валидация против `PlanRepository::findByName`,
    400 на неизвестный план, 404 на несуществующего юзера.
- UI: `templates/admin/users.html.twig` — поиск + пагинированная таблица + кнопки
  ban/unban/reset + Alpine-модалка смены плана, всё через `window.admin.fetch`,
  `x-init`+`window.admin.ready`. `dashboard.html.twig`/`base.html.twig` НЕ трогал
  (nav/include подключит тимлид при интеграции).
- Тесты (default suite, без группы integration): `UserSearchRepositoryTest` (5),
  `UserControllerTest` (7) — 403 не-админу на всех эндпоинтах, 401 без auth,
  пагинация, ban/unban flip isActive, reset-quota обнуляет счётчики, plan
  valid/invalid(400)/404.
- Quality gate: `make cs` (0 fixes) · `make phpstan` (0) · `lint:twig` OK ·
  `make test-php-live` — 168 tests / 690 assertions, exit 0 (было ~156; +12).
- Флаг тимлиду: бан не отзывает refresh-семейство проактивно — юзер выпадает лишь
  при следующем refresh (совпадает с текущим дизайном). Если нужна мгновенная
  блокировка — отдельная доработка.
