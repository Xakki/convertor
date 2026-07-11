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

**Status:** todo.
