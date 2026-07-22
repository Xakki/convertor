### Раздел админки Workers: список воркеров, lastSeen, матрица

**Criticality:** Minor

**TAGS:**
- feature
- admin

**Description:**
Шестой, финальный шаг Phase 2 эпика `[[registry-00-self-registration]]`. Закрывает слепую зону
наблюдаемости из `worker-registry-fragility` (D3: отдельная страница, а не расширение
`/admin/queues`) — сейчас статус воркеров виден только косвенно через consumers очереди KeyDB
или частично через `ConversionRegistry::getCapabilityWarnings()` (L196-238) в `/api/v1/admin/queues`
(`app-symfony/src/Service/Admin/QueueStatsProvider.php:136`).

**Problem:**
Оператору негде посмотреть цельный список зарегистрированных воркеров: тип, инстанс, образ/версия,
когда последний раз видели живым, сколько пар конвертации заявляет — вся эта информация после
Phase 2 существует в БД (`worker_capabilities` + `lastSeen` из `[[registry-06-liveness-push]]`),
но не выведена в UI.

**Impact:**
Диагностика «почему формат недоступен» требует ручного кросс-чтения логов/БД вместо одного
взгляда на страницу.

**Recommendation:**
- Бэкенд: `GET /api/v1/admin/workers` — новый `Controller/Admin/Api/WorkerController.php` под
  `#[Route('/api/v1/admin')]` + `#[IsGranted('ROLE_ADMIN')]` (паттерн — `QueueController.php`),
  сервис-провайдер в `Service/Admin/` (паттерн — `QueueStatsProvider`). Отдаёт список строк
  capability: `workerType`, `instanceId`, `image`/`version`, `lastSeen`, флаг «жив/устарел»
  (сравнение `lastSeen` с TTL из `[[registry-06-liveness-push]]`), количество заявленных пар.
- Фронт: `templates/admin/workers.html.twig` по образцу `templates/admin/queues.html.twig`
  (Alpine + HTMX-поллинг клиента), вписан в существующий SPA-шелл
  (`AdminController::dashboard()`, `app-symfony/src/Controller/Admin/AdminController.php:25`,
  Option B — открытая оболочка + JWT-guard на JSON-API).
- Разворачиваемая (expandable) секция с полной матрицей пар по каждому воркеру (source→target
  из `capabilities.matrix`).
- НЕ дублировать `getCapabilityWarnings()` из `/admin/queues` — при желании сослаться на неё
  из новой страницы (напр. ссылкой/бейджем «см. Queues»), не переносить и не копировать логику.

**Acceptance Criteria:**
- `GET /api/v1/admin/workers` доступен только `ROLE_ADMIN` (403 иначе), отдаёт актуальный список
  из `worker_capabilities` (workerType, instanceId, image, version, lastSeen, alive/stale, pair count).
- Страница `/admin` получает раздел Workers, доступный через клиентский роутинг SPA-шелла,
  без отдельного полного релоада.
- Разворачиваемая матрица пар отображается по клику на воркера.
- Существующий `/admin/queues` не изменён по функциональности (warnings остаются там же).
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- Груминг 2026-07-22 (D3): отдельная страница Workers, а не расширение `/admin/queues` —
  разные аудитории вопроса («что с очередями» vs «какие воркеры вообще есть»).
- Реализация 2026-07-23: seed-строки (`instanceId='__seed__'`) — честные данные + флаг
  `isSeed`, а не отдельный API-путь. Бэкенд считает `stale` одинаково для всех строк
  (сырые данные не лгут); ПРЕЗЕНТАЦИЯ решает разницу — фронт показывает seed-строкам
  нейтральный бейдж "Seed" вместо alive/stale-индикатора (они не воркер, а статичный
  снимок матрицы registry-03, никогда не получают liveness-пуш, поэтому по `lastSeen`
  всегда выглядели бы "устаревшими" — это не полезный сигнал для оператора, а шум).
  `status`/`stale` показаны РАЗДЕЛЬНЫМИ бейджами (не схлопнуты в один светофор) — это
  два разных факта (liveness-статус vs возраст lastSeen относительно GC-TTL).

**Зависит от:** `[[registry-06-liveness-push]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

## Execution Log (backend-php)

- **Скилл `api-design` вызван первым** (как и просил тимлид) — подтвердил конвенции:
  `{"error": ...}` форма ошибок, роутинг PHP-атрибутами, admin-контроллеры БЕЗ OpenAPI
  (зафиксировано 2026-07-18 при `dead-letter/requeue` — admin не публичный контракт).
  Карта эндпоинтов скилла обновлена (`/liveness`+`/dlq-fail` добавлены ранее в
  registry-06; в этот раз изменений карты не потребовалось — `Admin/Api/*` уже описан
  одной строкой без перечисления каждого будущего эндпоинта).
- **Backend:** `Controller/Admin/Api/WorkerController.php` (`GET /workers`,
  `#[Route('/api/v1/admin')]` + `#[IsGranted('ROLE_ADMIN')]`, буквально по образцу
  `QueueController.php`) + `Service/Admin/WorkerStatsProvider.php` (по образцу
  `QueueStatsProvider`). Источник данных — `WorkerCapabilityRepository::findAllCapabilities()`
  — тот же метод, что `ConversionRegistry` использует для матрицы (страница показывает
  РОВНО то, что участвует в маршрутизации).
- **TTL — БЕЗ второго литерала**, как потребовал тимлид: `WorkerStatsProvider`
  получает `$ttlHours` без дефолтного значения (обязательный конструкторный параметр),
  wiring в `services.yaml` — `%env(int:WORKER_CAPABILITY_GC_TTL_HOURS)%`, ТОТ ЖЕ env,
  что `WorkerCapabilityGcService` (registry-06) использует для реального удаления.
  Само чтение — cleanly injectable, вопросов/блокеров не возникло.
- **Seed-строки:** см. новый Decisions-пункт выше. Кратко — честные данные (`isSeed`
  флаг + одинаковый расчёт `stale`) на бэке, презентационное решение (нейтральный
  бейдж вместо alive/stale-тревоги) на фронте.
- **`status` vs `stale`:** оба поля в ответе, показаны раздельными бейджами в таблице
  (колонки "Статус" и "Свежесть") — НЕ схлопнуты в один индикатор, как явно требовал
  тимлид.
- **`getCapabilityWarnings()` НЕ продублирован** — ни в `WorkerStatsProvider`, ни в
  шаблоне; вместо этого текстовая ссылка `#queues` в разметке ("Предупреждения о
  matrix_categories — см. Очереди →"). `/admin/queues` не тронут ни строкой (только
  добавлен `include` соседней секции в `dashboard.html.twig` + пункт бокового меню в
  `base.html.twig`) — покрыто регрессионным тестом
  (`testDashboardStillIncludesQueuesSection`).
- **Frontend:** `templates/admin/workers.html.twig` по образцу `queues.html.twig`
  (тот же Alpine-компонентный паттерн, `window.admin.fetch`, `x-cloak`, `x-show`).
  Poll каждые 15с (не 10с, как очереди — список воркеров меняется медленнее, чем
  длина стрима, осознанный выбор, не копипаста). Разворачиваемая матрица — per-row
  `x-data="{ expanded: false }"` на отдельном `<tbody>` (несколько `<tbody>` как
  соседи внутри `<table>` — валидный HTML5), клик по строке тогглит; матрица рендерит
  `capabilities.matrix` через Alpine `x-for="(targets, from) in w.matrix"` (Alpine 3
  поддерживает `(value, key) in object`, не только массивы).
- **Wiring:** `dashboard.html.twig` — добавлен `{% include 'admin/workers.html.twig' %}`
  между queues и logs; `base.html.twig` — пункт `{ key: 'workers', href: '#workers',
  label: 'Воркеры' }` в `nav_items` между Очередями и Логами.
- **QA/верификация UI — что реально проверено, а что нет (честно, как просил тимлид):**
  - `bin/console lint:twig templates/admin/` — валидный Twig-синтаксис всех 9 файлов.
  - Новый функциональный тест `AdminControllerTest`: реальный HTTP GET `/admin` через
    `WebTestCase`, ассерты на содержимое СКОМПИЛИРОВАННОГО HTML — `id="workers"`
    присутствует, `adminWorkers()` (Alpine hook) присутствует, `href="#workers"` (nav)
    присутствует, URL `/api/v1/admin/workers` присутствует в poll-коде; плюс regression-
    проверка, что `id="queues"`/`adminQueues()` всё ещё на месте. Это доказывает, что
    шаблон реально КОМПИЛИРУЕТСЯ через Twig-inheritance/include-цепочку и попадает в
    ответ — не просто "синтаксически валиден в вакууме".
  - **ЧЕГО Я НЕ МОГ проверить без браузера** (явно, не молчком): Alpine-реактивность
    (реально ли `x-for`/`x-show`/`x-data` рендерят DOM так, как задумано), клик по
    строке реально разворачивает матрицу, HTMX/JS poll реально обновляет данные раз в
    15с без console-ошибок, визуальную вёрстку (Tailwind-классы дают ожидаемый вид,
    адаптивность grid на матрице). Это ТРЕБУЕТ живого браузера — не автоматизировано
    этим прогоном. **Рекомендация: человеку глазами открыть `/admin#workers` после
    деплоя перед закрытием эпика.**
- **QA:** `make phpstan` — `[OK] No errors`; `make cs` → `make cs-check` — чисто (211
  → 212 файлов, всё чисто); `make test-php-live` — `OK (465 tests, 1898 assertions)`,
  drift 2/2.
- Расхождений с зафиксированным тимлидом контрактом не найдено.
