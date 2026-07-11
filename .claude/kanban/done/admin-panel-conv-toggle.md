### Admin: вкл/выкл конкретной конвертации (эпик admin-panel #6)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 6. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Из ROADMAP-спеки (`ROADMAP.md:227-228`): админ может включить/выключить конкретную
конвертацию. **Модели данных сегодня нет** — реестр статичен
(`src/Service/Conversion/ConversionRegistry.php`), нужен персистентный флаг.

**Scope:**
- **Модель данных:** персистентный toggle для (from→to)/типа конвертации. Решить
  гранулярность: по паре форматов, по категории (`conv.<type>`), или по записи
  реестра. Черновик: новая entity `ConversionToggle` (ключ + enabled) ИЛИ флаг на
  существующей конфиг-модели; + миграция.
- **Чтение флага:** `ConversionRegistry` (и/или слой валидации в `ConversionManager`/
  API) при выборе конвертора учитывает toggle → выключенная конвертация даёт
  внятную 4xx-ошибку на сабмите, не уходит в очередь.
- **Кэш:** флаги читаются на каждый сабмит — кэшировать (KeyDB DB0 cache), инвалид
  при переключении.
- **API:** `GET /api/v1/admin/conversions-toggle` (список + состояние),
  `POST .../conversions-toggle/{key}` (вкл/выкл).
- **UI:** `templates/admin/toggle.html.twig` — список конвертаций с переключателями
  (Alpine + HTMX).

**Acceptance criteria:**
- [ ] Выключенная конвертация отвергается на сабмите (4xx), не попадает в очередь.
- [ ] Переключение персистится и сразу влияет на новые сабмиты (кэш инвалид).
- [ ] Гранулярность toggle задокументирована и согласована в реализации.
- [ ] Эндпоинты под ROLE_ADMIN; 403 иначе. `make phpstan` 0, `make cs-check` чисто,
      PHPUnit на «выключено → reject».

**Files:** новая entity + миграция, `src/Service/Conversion/ConversionRegistry.php`,
`src/Service/Conversion/ConversionManager.php` (или валидация в
`ConversionController`), `src/Controller/Admin/ConversionToggleController.php`,
`templates/admin/toggle.html.twig`.

**Open note:** гранулярность toggle (пара форматов vs категория vs запись реестра) —
финализировать в начале реализации, зафиксировать в Execution Log.

**Execution Log:**

- **Гранулярность — пара `(fromFormat, toFormat)`.** Обоснование: именно так реестр
  `ConversionRegistry` выбирает конвертор (`isSupported`/`getCategory`/`isAi`/
  `streamFor`). Отключение пары режет её независимо от `ocr`-флага (та же пара
  from→to) — это осознанное следствие гранулярности «по паре», не баг. Отсутствие
  ряда = включено (пустая таблица ничего не меняет).
- **Entity + миграция:** `ConversionToggle` (таблица `conversion_toggles`:
  from_format, to_format, enabled, created_at, updated_at; UNIQUE(from,to)),
  миграция `Version20260711120000`. Применена к dev-БД (`make migrate`) и к
  тест-БД `convertor-test` (`make test-db-setup` в составе `test-php-live`).
- **Чтение + кеш:** `ConversionToggleService` (`src/Service/Conversion/`).
  Кешируется множество ОТКЛЮЧЁННЫХ пар («from>to») в том же `cache.app`
  (Symfony `CacheInterface`), что использует `ConversionRegistry` — отдельного
  Redis-подключения не заводим. Инвалидация зеркалит
  `ConversionRegistry::invalidateMatrix()`: сброс per-request memo +
  `cache->delete()`. **Deviation vs карта:** карта просила «KeyDB DB0 cache», но
  DB0-cache-pool в проекте не подключён (sessions=DB1, messenger=DB2, redis в
  `cache.yaml` закомментирован). Реюз `cache.app` = ответ на «reuse, не изобретай
  подключение»; перевод `cache.app` на KeyDB — инфра-решение вне scope.
- **Disable-check** в `ConversionManager::createConversion` — сразу после
  резолва support/OCR и ДО любых quota/S3-эффектов и постановки в очередь.
  Отключённая пара бросает `ConversionDisabledException` → контроллер
  `ConversionController::convert` отдаёт **HTTP 409** `{error:"conversion_disabled",
  message:"Конвертация временно отключена"}`. Сервис инжектится в
  `ConversionManager` как nullable-опциональный последний параметр (паттерн
  `ConversionRegistry`): в проде autowiring, unit-тесты без БД получают null.
- **Admin API** (`Controller/Admin/Api/ConversionToggleController`, `ROLE_ADMIN`):
  `GET /api/v1/admin/conversions-toggle` (пары из реестра + enabled-состояние),
  `POST /api/v1/admin/conversions-toggle` (`{from,to,enabled}` → upsert +
  инвалидация; неизвестная пара → 404).
- **UI:** `templates/admin/toggle.html.twig` (Alpine + `window.admin.fetch`,
  переключатели, фильтры по формату/категории/«только выключенные»). `base.html.twig`
  не тронут.
- **Тесты:** unit `ConversionManagerToggleTest` (выключено→reject до side-effects,
  включено→proceed); functional `ConversionToggleControllerTest` (403 не-админ,
  401 аноним, GET-список, персист+инвалидация кеша через POST-выкл→GET→POST-вкл→GET,
  404 на неизвестную пару).
- **Quality gate:** `make phpstan` 0, `make cs-check` чисто, `make test-php-live`
  зелёный (192 теста, +7; миграция применена к тест-БД).

**Status:** ready (ревью APPROVE WITH NITS; в done — с эпиком)
