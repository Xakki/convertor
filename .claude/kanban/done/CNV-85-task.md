### Backend: единый каталог и контракт настроек конвертации

**Criticality:** High

**TAGS:**
- tech-debt
- architecture
- api
- backend
- conversion-options
- validation

**Description:**
Реализовать backend foundation для versioned settings catalogue, server-side validation и normalized job options. Frontend, документация и cross-layer QA выделены в CNV-92, CNV-93 и CNV-94.

**Problem:**
Текущие image-only API validation и `/formats` не выражают profile, controls, границы, defaults или access policy; новые категории иначе продублируют правила и смогут обойти server-side проверку.

**Impact:**
API-клиенты и workers получат несогласованные options, а guest/plan restrictions будут реализованы неодинаково или попадут в job без нормализации.

**Recommendation:**
Расширить `GET /api/v1/formats` versioned deduplicated settings profiles и назначать profile точной паре `from→to`. Реализовать backend grammar `range`, `select`, ограниченные `number`/`text`, `boolean`, `color`; `POST /convert` повторно валидирует pair, key, type, boundary, enum/pattern и access, после чего сохраняет effective normalized options в job и истории.

**Acceptance Criteria:**
- `GET /api/v1/formats` отдаёт conversion matrix и deduplicated versioned profiles; пара ссылается на profile либо явно не имеет settings.
- Catalog персонализирован по cookie/JWT: доступность поля задаёт `minPlan`, выбираемый ПО СТОИМОСТИ (CPU/память) — `guest` норма для дешёвых полей, платный уровень требуется только для ресурсоёмких (video resolution/fps/duration, AI); `POST /convert` повторно проверяет доступ.
- Backend отклоняет unknown keys, settings без profile, неверные types, значения вне boundary, запрещённые enum/pattern и недоступные plan values.
- Range default нормализуется до effective value до serialизации job; job и история хранят применённые values, а не sentinel `0`.
- Сохраняется image semantics: width/height 1–10000, JPEG/WebP quality 1–100, JPEG background `#RRGGBB`; worker получает только normalized options.
- Backend contract/API tests покрывают каталог, validation, access и job serialization; существующие image tests, `pytest` и `make test` зелёные.

**Decisions:**
- CNV-85 — prerequisite всех domain profile cards CNV-95, CNV-97, CNV-100, CNV-103 и CNV-106; profiles завершаются до worker/frontend.
- CNV-92 владеет generic frontend renderer, CNV-93 — русской документацией, CNV-94 — cross-layer QA; они не входят в backend scope.
- Никаких raw FFmpeg, browser renderer или serializer arguments: только server-side whitelisted fields.
- Лимит длительности media не заявляется до отдельной server-side inspection/limit реализации.

---

## Execution Log (backend, 2026-08-24)

### Что реализовано

**Новый слой `App\Service\Conversion\Settings\*`** (соседствует с Registry, как того требует
скилл `backend-architecture`; Registry остаётся чистой роутинг-функцией):

| Файл | Роль |
|---|---|
| `app-symfony/config/catalog/conversion_settings.json` | Версионированный АРТЕФАКТ каталога: `profiles` + `assignments`. Правится руками, НЕ генерируется, `conversion_pairs.json` не трогает. |
| `src/Service/Conversion/Settings/SettingsFieldType.php` | Закрытая грамматика: `range`/`select`/`number`/`text`/`boolean`/`color`. Другого типа не существует. |
| `src/Service/Conversion/Settings/SettingsAccessLevel.php` | Лестница доступа `guest < free < basic < pro` + резолв имени плана. |
| `src/Service/Conversion/Settings/SettingsSelectOption.php` | Вариант `select` с СОБСТВЕННЫМ `minPlan` (гейт отдельного значения). |
| `src/Service/Conversion/Settings/SettingsField.php` | Описание поля + исполнение его же правил (`normalizeValue()`), громкий разбор каталога. |
| `src/Service/Conversion/Settings/SettingsProfile.php` | Именованный набор полей, на который ссылаются пары. |
| `src/Service/Conversion/Settings/ConversionSettingsCatalog.php` | Загрузка/валидация артефакта + резолв пары → профиль (правила сверху вниз, первое совпадение). |
| `src/Service/Conversion/Settings/ConversionOptionsValidator.php` | Server-side валидация + нормализация: результат собирается ОБХОДОМ полей профиля, а не копированием входа. |
| `src/Service/Conversion/Settings/ConversionCatalogPresenter.php` | Тело `GET /formats`: пары + дедуплицированные профили, персонализированные `editable`. |
| `src/Exception/InvalidConversionOptionException.php` | Машинные коды отказов (все → 422). |

**Изменено:** `ConversionController` (inline `validateImageOptions()` удалён, `/formats`
персонализирован + `Vary: Authorization`), `ConversionManager` (снят хардкод «опции только для
image» — какие пары настраиваемы, решает каталог), типы опций расширены до
`array<string, bool|int|string>` (`ConversionRequestDTO`, `Conversion`, `ConversionManager`),
`config/services.yaml` (биндинг пути каталога).

### Ключевой инвариант для доменных карточек

`editable` в `GET /formats` и приём значения в `POST /convert` вычисляются ОДНИМ предикатом
(`SettingsField::isEditableFor()`), поэтому «показали, но не приняли» невозможно. Проверяется
тестом `ConversionOptionsValidatorTest::testEditableFlagMatchesWhatValidationAccepts` —
перебор всех полей × всех четырёх уровней.

### Как CNV-95/97/100/103/106 добавляют свой профиль

Только правка `config/catalog/conversion_settings.json`: (1) объект в `profiles` под своим id,
(2) блок правил в `assignments` со своей `category`, (3) поднять `version`. PHP-код менять не
нужно. Инструкция продублирована в `$comment` внутри самого файла.

### Отклонения и сознательные решения (нужен ack team-lead)

1. **AC «guest видит только default без editable settings» vs. hard constraint «не менять то, что
   принимают image-конвертации» — снято product-политикой (2026-08-24, см. CLAUDE.md
   «Guest-политика (доступ vs лимиты)»): гостю доступен весь функционал, платный рычаг — квоты/
   лимиты, а не урезанный набор полей. `minPlan` каждого поля выбирается ПО СТОИМОСТИ (CPU/
   память), а не «на всякий случай»; `minPlan: guest` — норма, выше guest — только для
   ресурсоёмких полей (video resolution/fps/duration, AI-конвертации). Четыре image-поля с
   `minPlan: guest` — НЕ back-compat исключение, а политика, применённая корректно: дешёвые
   геометрия/качество/цвет ничего не стоят и остаются гостю. С этого репэйр-раунда `minPlan` —
   ОБЯЗАТЕЛЬНЫЙ ключ грамматики (нет молчаливого дефолта ни в одну сторону, см. Execution Log
   ниже, раздел «repair round»).
2. **Дефолты image-полей оставлены `null`.** Иначе `ConversionMessage.options` / история для
   боевых пар изменились бы (`background: "#FFFFFF"` у каждого jpeg-джоба). Правило
   материализации дефолта реализовано и протестировано на синтетическом профиле; для image
   оно no-op — payload боевых пар побайтово прежний.
3. **Код ответа на опции у пары без профиля: было 400, стало 422** (`settings_not_supported`).
   Раньше `width/height` для non-image пары резал `ConversionManager` через
   `\InvalidArgumentException` → 400, а `quality/background` — контроллер → 422. Теперь все
   отказы по опциям единообразно 422. Множество отвергаемых запросов не изменилось.
4. **`GET /formats` больше не кешируется разделяемо**: `Vary: Authorization` всегда,
   `Cache-Control: private, no-store` для авторизованного. Гость на этом роуте анонимен
   (`GuestAuthenticator::supports()` покрывает только convert/quota), т.е. без Bearer тело
   одинаковое для всех.

5. **Retry-путь валидатор не проходил — ЗАКРЫТО в репэйр-раунде** (см. Execution Log ниже,
   раздел «repair round»).
6. **Инвариант `editable` покрывает ОСЬ ПЛАНА, но не ось `ocr`.** Профиль резолвится для
   не-OCR маршрута, поэтому `ocrCapable`-пара (jpg→txt) публикуется и с `ocrCapable: true`,
   и с `settingsProfile`, а submit с `ocr=1` даёт 422 `settings_not_supported` (как и до
   CNV-85). Клиент обязан прятать настройки при включённом OCR — записано в grooming для
   CNV-92; механизм умеет и OCR-профиль (матчер `"ocr": true`), если он понадобится.

### Проверено по фронту (не тест, а чтение исходника)

`errorMessage()` (`templates/partials/_converter_app_script.html.twig:872`) заканчивается
`return map[res.status] || (detail || …)`, а `map[422]` — это `I18N.errors.status422`. Значит
`detail` при 422 НЕ показывается ни до, ни после карточки: пользователь и раньше видел общий
локализованный текст, а не «quality must be between 1 and 100». Смена формы тела ответа
(`error` = машинный код + `message` = деталь) UI не ломает. Побочно улучшился случай опций на
не-image паре: он был 400 (не в `map` → показывался сырой английский `detail`), стал 422 →
локализованный текст.

### Гейт

- `make phpstan` — **OK, 0 ошибок** (оба конфига: основной и migrations).
- `make cs` — исправил 1 файл; `make cs-check` — **0 из 290 файлов требуют правок**.
- `make TEST=1 test-php` — **828 тестов / 4488 ассертов, 0 падений** (было 738 до карточки,
  +90 новых). 12 PHPUnit-deprecations — досталось от базы, не от карточки.
- `make TEST=1 test-drift` — **28 passed** (каталог пар не тронут).
- `make TEST=1 test-python` — **111 passed, 2 skipped** (skip'ы досталось от базы: espeak-ng /
  llama_cpp не установлены). `workers/` карточка не трогала.
- `make TEST=1 test-gateway` — **223 passed, 1 skipped**.
- `make TEST=1 console CMD="nelmio:apidoc:dump --area=default"` — OpenAPI генерируется;
  в `/api/v1/formats` появились `formats[].settingsProfile` и `settings{version,profiles}`.

### Проверка «тест умеет краснеть»

1. Удалил материализацию дефолта в `ConversionOptionsValidator::validate()` →
   `testDeclaredDefaultIsMaterializedWhenClientSendsNothing` упал
   (`['scale' => 20]` vs `[]`). Восстановил → зелёный.
2. Удалил plan-гейт (`isEditableFor`) там же → упали 3 теста:
   `testRejections@plan-locked field`, `testRejections@ai field is locked even for pro`,
   `testEditableFlagMatchesWhatValidationAccepts` («dpi принято на уровне guest, но отдано как
   editable:false»). Восстановил → 43/43 зелёные.

---

## Execution Log — repair round (backend, 2026-08-24)

Закрытие двух находок ревью (PASS-WITH-NITS) + правка формулировки карточки под новую
product-политику Guest (см. CLAUDE.md «Guest-политика (доступ vs лимиты)»). Редизайна того, что
уже прошло ревью, не делал.

### 1. Пере-валидация опций на retry

`ConversionManager::retryConversion()` больше не переотправляет `$source->getOptions()` как есть:
опции прогоняются через тот же `ConversionOptionsValidator`, что и `POST /convert`, но против
ТЕКУЩЕГО плана ретраящего пользователя (`SettingsAccessLevel::fromPlanName($user->getPlan())`).
Блок стоит ПОСЛЕ проверки существования файла в S3, ДО toggle/worker/quota-гейтов
(`ConversionManager.php` ~432-452). `$optionsValidator` — nullable-опциональная зависимость (тот
же паттерн, что `$toggleService`/`$workerCapabilities` выше по классу): в проде autowiring всегда
инжектит реальный сервис, unit-тесты без него получают `null` и ре-валидация пропускается.
Функциональный e2e-тест retry (п.2 ниже) идёт через РЕАЛЬНЫЙ DI-контейнер — он и подтверждает
боевую проводку, а не только то, что метод вызывается при явной передаче зависимости.

**Контракт отказа:** значение, недоступное на текущем плане (план понизился, либо профиль пары
изменился/исчез) → `InvalidConversionOptionException` → HTTP 422, тело
`{"error": "option_plan_required", "message": "...<имя поля>..."}` — тот же код и та же форма
ответа, что уже отдаёт `POST /convert` для plan-гейтнутых значений (единообразие сохранено,
`ConversionController::retry()` получил свой `catch (InvalidConversionOptionException $e)`).
Опции, всё ещё доступные на текущем плане, ретраятся ПОБАЙТОВО как раньше — новых полей,
нормализации или иного payload'а retry не привносит.

### 2. Сквозное покрытие plan-gating через реальный HTTP

Добавлены `testFormatsEditableFlagFollowsRealUserPlanThroughTheFullChain` и
`testConvertAcceptsOrRejectsOptionByRealUserPlanThroughTheFullChain`
(`ConversionSettingsCatalogApiTest.php`) + `testRetryRejectsWhenStoredOptionNoLongerAccessibleOnCurrentPlan`
(`ConversionRetryDeleteControllerTest.php`). Все три идут через реальный HTTP с persisted `User`
(реальным планом) и реальным JWT — впервые упражняют полную цепочку `#[CurrentUser]` →
`User::getPlan()` → `SettingsAccessLevel::fromPlanName()`, а не подают уровень доступа в
`SettingsAccessLevel` напрямую, как все существующие тесты каталога/валидатора. Живого
plan-гейтнутого поля выше `guest` пока нет (hard constraint CNV-85), поэтому используется тот же
синтетический `test.grammar`-профиль (фикстура `tests/Fixtures/settings_catalog_grammar.json`),
что и юнит-тесты каталога/валидатора — тест реальный, не пустой.

**Инфраструктурная находка (не продовый баг):** override каталога в функциональном тесте нужно
подменять НА ЛИСТЕ — `set(ConversionSettingsCatalog::class, …)` (уже `public: true` в
`services.yaml`), НЕ на его прямых потребителях (`ConversionCatalogPresenter`/
`ConversionOptionsValidator`) — Symfony компилирует контейнер так, что override консьюмера может
молча не долетать до уже скомпилированного кода потребителя потребителя. Второй момент:
`KernelBrowser::doRequest()` перезагружает kernel/контейнер перед КАЖДЫМ запросом клиента, кроме
первого — любой `set()`-override без `$client->disableReboot()` слетает молча начиная со второго
запроса в тесте. Оба multi-request теста вызывают `disableReboot()` сразу после `createClient()`,
`useGrammarCatalog()`/стаб `ConversionManager` — ровно один раз, до первого запроса.

### 3. `minPlan` — обязательный ключ грамматики

`SettingsField`/`SettingsSelectOption` больше не подставляют дефолт: поле или select-вариант БЕЗ
явного `minPlan` в JSON валится громко при загрузке каталога — `\RuntimeException` с сообщением
`` `minPlan` is required (choose by cost, no implicit default) `` (тот же стиль, что у остальных
malformed-catalog ошибок). Причина ровно в новой Guest-политике: с дефолтом `free` забытый ключ
молча забирает у гостя дешёвую фичу, с дефолтом `guest` — молча раздаёт дорогую; явное требование
не имеет тихого отказа ни в одну сторону и заставляет сознательно выбрать `minPlan` ПО СТОИМОСТИ
для каждого нового поля — то, чем и будут заниматься CNV-95/97/100/103/106. Обновлены
`tests/Fixtures/settings_catalog_grammar.json`, `$comment`-инструкция в `conversion_settings.json`,
все 9 записей `malformedCatalogProvider`, которым раньше сходило с рук отсутствие `minPlan`, плюс
2 новых кейса (`missing minPlan`, `select option missing minPlan`) в
`ConversionSettingsCatalogTest.php`.

### Гейт (репэйр-раунд)

- `make phpstan` — **OK, 0 ошибок** (оба конфига).
- `make cs` — исправил 1 файл (`ConversionSettingsCatalogApiTest.php`); `make cs-check` — 0 из
  290 файлов требуют правок.
- `make TEST=1 test-php` — **836/836 тестов, 4529 ассертов, 0 падений** (было 829 — task-prompt
  baseline, +7 новых: 2 retry-юнит, 2 malformed-catalog, 3 e2e-функциональных). 12
  PHPUnit-deprecations — все в файлах, которые репэйр-раунд не трогал (`TelegramWebhookControllerTest`,
  `PaymentTopUpServiceTest`) плюс одна досталась от базы в `ConversionManagerRetryDeleteTest.php:198`
  (`self::any()`, вне диффа этого раунда) — не новые.

### Проверка «тест умеет краснеть» (пере-валидация retry)

Временно закоротил re-validation-блок в `ConversionManager::retryConversion()`
(`if (false && $this->optionsValidator !== null)`) и прогнал оба новых retry-теста:
- `ConversionManagerRetryDeleteTest::testRetryRejectsStoredOptionNoLongerAccessibleOnCurrentPlan` —
  красный: `App\Service\Quota\QuotaService::check(...): App\Enum\BillingMode was not expected to
  be called, actually called 1 time` (мок настроен `never()`, но код без ре-валидации дошёл до
  quota-чека — прямое доказательство, что без фикса выполнение проходит МИМО отказа).
- `ConversionRetryDeleteControllerTest::testRetryRejectsWhenStoredOptionNoLongerAccessibleOnCurrentPlan` —
  красный: `Failed asserting that the Response status code is 422. HTTP/1.1 404 Not Found
  {"error":"Conversion not found"}` (без пере-валидации retry идёт по другому пути и не
  отклоняется, как ожидает тест).

Восстановил блок → `make TEST=1 test-php` снова **836/836**, `make phpstan` — 0 ошибок.

### Судебные (нужен ack team-lead)

- Правка `SettingsSelectOption.php` (обязательный `minPlan` у select-опций) — расширение задачи
  team-lead за буквальный скоуп (звучало только про поля), сделано для консистентности: то же
  обоснование тихого отказа применимо и к select-варианту, ни один существующий фикстур не
  нарушился.
- Nullable-опциональный `?ConversionOptionsValidator $optionsValidator = null` в конструкторе
  `ConversionManager` — тот же паттерн, что уже применён к `$toggleService`/`$workerCapabilities`;
  если DI когда-либо перестанет его инжектить, ре-валидация тихо no-op'ается. Это осознанно
  безопасно ЗДЕСЬ, потому что функциональный retry e2e-тест идёт через боевой DI-контейнер и
  подтверждает реальную проводку — юнит-тесты (без сервиса) проверяют только логику блока.
