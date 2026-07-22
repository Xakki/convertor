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

**Зависит от:** `[[registry-06-liveness-push]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** todo
