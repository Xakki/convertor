### Admin stats dashboard (эпик admin-panel #2)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 2. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Dashboard с реальными метриками конвертаций и юзеров. Revenue — плейсхолдер-0
(платежи не персистятся, см. эпик).

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
- [ ] `/admin` (stats-секция) показывает реальные conversion/user метрики.
- [ ] Revenue-плитка = 0/«n/a», не падает.
- [ ] `GET /api/v1/admin/stats` под ROLE_ADMIN; 403 иначе.
- [ ] Aggregate-запросы покрыты PHPUnit (на сид-данных).
- [ ] `make phpstan` 0, `make cs-check` чисто.

**Files:** `src/Controller/Admin/StatsController.php`,
`src/Repository/{Conversion,User}Repository.php`, `templates/admin/stats.html.twig`.

**Status:** todo.
