### Admin conversion logs (эпик admin-panel #5)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 5. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Searchable/filterable view по конвертациям (DB-backed). Рантайм-логи воркеров
(стектрейсы, кросс-сервис) — не в БД, а в Graylog: даём линк-аут, не тянем в БД.

**Scope:**
- **DB-запросы:** `ConversionRepository` — поиск/фильтр/пагинация по `status`
  (вкл. `Failed`), `user`, `fromFormat/toFormat`, `category`, диапазону
  `createdAt`; показывать `errorMessage`, `processingMs`, `isAi/isOcr`,
  `inputFile/outputFile`. Все поля есть (`src/Entity/Conversion.php:34-62`) —
  добавить только query-методы.
- **API:** `GET /api/v1/admin/conversions` (фильтры + пагинация).
- **UI:** `templates/admin/logs.html.twig` — грид с фильтрами (Alpine),
  HTMX-пагинация, быстрый фильтр «только ошибки». Кнопка/линк «открыть в Graylog»
  для строки (по correlation/job-id, если есть) — рантайм-детали смотреть там.

**Acceptance criteria:**
- [ ] Грид конвертаций с фильтрами status/user/format/date + пагинация.
- [ ] Быстрый фильтр ошибок (`status=Failed`) показывает `errorMessage`.
- [ ] Линк-аут в Graylog для рантайм-логов (не дублируем их в БД).
- [ ] Эндпоинт под ROLE_ADMIN; 403 иначе. `make phpstan` 0, `make cs-check` чисто,
      PHPUnit на search/filter.

**Files:** `src/Controller/Admin/ConversionLogController.php`,
`src/Repository/ConversionRepository.php`, `templates/admin/logs.html.twig`.

**Execution Log:**
- 2026-07-11 (Agent: admin-logs):
  - `ConversionRepository::searchPaginated(array $filters, limit, offset)` —
    фильтры status/user(id|email)/fromFormat/toFormat/category/isAi/isOcr +
    диапазон дат (`from` >=, `to` инклюзивный день = `< to+1день`), сорт
    `createdAt DESC, id DESC`, COUNT по тем же условиям, fetch-join
    user/inputFile/outputFile (to-one, безопасно с пагинацией, гасит N+1).
    «Только ошибки» = `status=Failed`. Всё параметризовано.
  - `Controller/Admin/Api/ConversionLogController` (namespace выровнен по
    Stats/Queue, не по устаревшему пути карты) `GET /api/v1/admin/conversions`
    под `#[IsGranted('ROLE_ADMIN')]` — парсит query → фильтры, отдаёт rows
    (id, user{id,email}, status, from→to, category, isAi/isOcr, processingMs,
    errorMessage в КАЖДОЙ строке, input/outputKey, created/updatedAt) +
    пагинация + `graylogUrl` в meta. Page size 25.
  - `templates/admin/logs.html.twig` — Alpine-грид с фильтрами, пагинацией,
    тумблером «только ошибки» (показывает errorMessage), линк-аут «открыть в
    Graylog» по conversion id (полнотекст `q="<id>"`, окно 7д; correlation-поля
    в логах воркеров нет — допущение в комментарии). База-URL Graylog берётся
    из API meta (`data.graylogUrl`), скрывает ссылку при пустом значении.
  - `GRAYLOG_URL` — env-конфиг: `env(GRAYLOG_URL): ''` (services.yaml) + DI-bind
    `$graylogUrl` в контроллер + пустой плейсхолдер в `app-symfony/.env`
    (не секрет).
  - Тесты: `ConversionLogRepositoryTest` (4 — user-scope+пагинация, errors-only,
    format/category, инклюзивный date-range), `ConversionLogControllerTest`
    (4 — 403/401/200+graylogUrl, errors-only фильтр возвращает errorMessage).
    Сев скоупится выделенным владельцем (общая тест-БД, без абсолютного total).
  - Gate: `make phpstan` 0; `make cs-check` чисто; `make test-php-live` зелёный
    (185 тестов, +8 новых, регрессий нет).
  - Заметка для интеграции: индекс для лога не добавлял (миграции нет по scope);
    при росте `conversions` под фильтры пригодился бы составной индекс
    `(created_at, status)` / `(user_id, created_at)` — grooming-карта индексов
    уже запланирована. Nav/`include 'admin/logs.html.twig'` в dashboard.html.twig
    подключает тимлид на интеграции (base/dashboard не трогал).

**Status:** ready (ревью APPROVE WITH NITS; в done — с эпиком)
