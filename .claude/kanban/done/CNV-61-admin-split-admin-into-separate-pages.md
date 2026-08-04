### Admin: разбить `/admin` на отдельные страницы

**Criticality:** Medium

**TAGS:**
- tech-debt
- feature

**Description:**
Админка `https://convertor.xakki.pro/admin` сейчас — одна SPA-оболочка
(`AdminController::dashboard` → `templates/admin/dashboard.html.twig`), которая
через `{% include %}` вшивает **все** панели сразу: stats, users, queues,
workers, logs, toggle, examples. Боковая навигация — якоря (`#overview`,
`#users`, …). Секции уже живут в отдельных Twig-файлах и тянут данные из своих
`/api/v1/admin/*` (Option B, client-side Alpine) — серверный роутинг на секцию
отсутствует.

**Problem:**
При открытии `/admin` браузер парсит и инициализирует Alpine-компоненты всех
панелей (в т.ч. pollers вроде workers/queues), подгружает Chart.js даже если
админ идёт в Users. Страница тяжёлая, растёт с каждой новой панелью; лишние
API-запросы и DOM мешают UX и отладке. На mobile сайдбар скрыт (`hidden lg:block`)
— без отдельного меню секции недоступны.

**Impact:**
Чем больше панелей добавляем (уже 7), тем медленнее первый paint и тем больше
шума в Network/console. Риск регрессий при правке одной секции из-за общей
страницы. На узких экранах навигация по секциям фактически отсутствует.

**Recommendation:**
Одна карта. Оставить `admin/base.html.twig` (layout + client-guard +
`window.admin.*`). Отдельный Symfony-роут на каждую секцию; страница рендерит
только свою. Сайдбар — path-ссылки + mobile nav (select или бургер) в рамках
этой же задачи. Chart.js — только на Обзоре. JSON-API и Option B auth не
трогаем. Hash-совместимость не делаем.

**Acceptance Criteria:**
- URL-карта:
  - `GET /admin` → Обзор (stats)
  - `GET /admin/users` → Пользователи
  - `GET /admin/queues` → Очереди
  - `GET /admin/workers` → Воркеры
  - `GET /admin/logs` → Логи
  - `GET /admin/conversions` → Тумблер конвертаций
  - `GET /admin/examples` → Примеры
- Открытие страницы секции НЕ включает HTML/Alpine других секций.
- Сайдбар (desktop) + mobile-навигация: активная секция подсвечена/выбрана;
  переходы — полноценная навигация по path.
- Per-page `<title>` и `<h1>` (уникальные для секции).
- Старые hash-ссылки (`/admin#users` и т.п.) **не** поддерживаются (осознанный
  отказ от совместимости).
- Функциональные тесты оболочки обновлены под новые роуты (workers и пр. —
  на своих URL, не внутри монолита `/admin`).
- QA: `make phpstan`, `make cs-check`, PHPUnit admin-тесты зелёные.

**Decisions:**
- **URL (1A):** `/admin` = Обзор (stats); остальные —
  `/admin/users|queues|workers|logs|conversions|examples`.
- **Hash (2B):** совместимость якорей не делаем — `/admin#users` просто
  перестаёт вести на секцию.
- **Заголовки (3A):** уникальные `<title>` + `<h1>` на каждую страницу.
- **Mobile nav (4A):** в scope этой задачи — select или бургер (сейчас сайдбар
  `hidden lg:block`).
- **Формат (5A):** одна карта CNV-61, без мини-эпика.
- Подтверждено @user 2026-08-03: `1A 2B 3A 4A 5A`.

**Grounding (2026-08-03):**
- Контроллер: `app-symfony/src/Controller/Admin/AdminController.php` — один роут
  `GET /admin` → `admin/dashboard.html.twig`.
- Dashboard includes: stats, users, queues, workers, logs, toggle, examples
  (`dashboard.html.twig:23-29`).
- Nav: `base.html.twig` — hash-hrefs `#overview`…`#examples`.
- API уже посекционный (`/api/v1/admin/{stats,users,queues,workers,conversions,…}`).
- Тесты: `tests/Functional/Controller/Admin/AdminControllerTest.php` ждут workers
  секцию внутри `/admin` SPA-шелла.
- Дубликатов в grooming/todo нет; эпик `admin-panel` и `registry-07-admin-workers-page`
  — done (закладывали «отдельную страницу Workers», фактически сделали секцию в
  том же `/admin`).

## Execution Log

- 2026-08-03: started — branch task/CNV-61, team: layout/pages/test/qa/reviewer (Grok→composer)
- 2026-08-03: layout-dev — path nav + mobile select (`b8bd1bf`)
- 2026-08-03: pages-dev — 6 section routes + slim overview (`31e83c4`)
- 2026-08-03: test-dev — Admin shell tests per-section (`07e534b`); 20 tests OK
- 2026-08-03: qa — phpstan / cs-check / Admin PHPUnit OK
- 2026-08-03: reviewer — PASS; moved test→ready
- 2026-08-03: follow-up users — guest-only toggle «Анонимы» + quota matrix transpose (`6effcec`); redeployed
- 2026-08-04: follow-up превью — иконки типа файла + модалка (img/audio/video/text) для исходника и результата на /history, главной и /admin/logs; API preview?side=source + админский стрим файлов (`1d12da5`)
