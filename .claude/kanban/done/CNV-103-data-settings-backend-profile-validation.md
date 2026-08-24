### Data settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- data
- backend
- conversion-options

**Description:**
Определить data profiles и серверную validation для CSV и JSON settings.

**Problem:**
Без strict per-target schema API может принять произвольные serializer options или передать CSV field в JSON job.

**Impact:**
Данные могут быть изменены или сериализованы несовместимо без предсказуемой ошибки.

**Recommendation:**
После CNV-85 публиковать CSV delimiter/quote и фиксированный UTF-8; JSON pretty-print и ограниченный indent. Отклонять невалидный UTF-8 строго; YAML/TOML/XML profiles не создавать.

**Acceptance Criteria:**
- Catalog назначает отдельные CSV и JSON profiles только поддерживаемым pairs.
- Backend валидирует whitelist delimiter/quote, UTF-8, pretty-print и indent; normalizes job options.
- Невалидный UTF-8, cross-target key и arbitrary serializer option отклоняются предсказуемо.
- API tests покрывают valid/invalid values и отсутствие profiles YAML/TOML/XML.

**Decisions:**
- Зависит от CNV-85; CNV-78/CNV-104 и CNV-105 начинаются после profile.
- YAML/TOML/XML отложены до подтверждённого спроса.
- Worker application принадлежит CNV-78/CNV-104.

---

## Execution Log (backend, 2026-08-24)

### TASK A — guard: select option minPlan не ниже minPlan поля

Ревью CNV-100 нашло footgun: загрузчик каталога не проверял, что `option.minPlan`
не ниже `field.minPlan` — инверсия дала бы `field.editable:false` рядом с
`option.editable:true` в `GET /formats` (submit всё равно отклоняет через
`ConversionOptionsValidator`, значит defect отображения, не дыра в безопасности).

Guard — `SettingsField::assertOptionsRespectFieldMinPlan()`
(`app-symfony/src/Service/Conversion/Settings/SettingsField.php`), вызывается из
`fromArray()` сразу после `parseOptions()`, до конструирования поля: обходит
`$options`, для каждого варианта `! $option->minPlan->isAtLeast($minPlan)` →
`\RuntimeException` (тот же громкий стиль, что у остальных malformed-catalog
проверок). Тест — `ConversionSettingsCatalogTest::testMalformedCatalogFailsLoudly
@select option minPlan below field minPlan` (data provider `malformedCatalogProvider`).

Python-подсчётом подтверждено: ни в боевом `conversion_settings.json`, ни в
`tests/Fixtures/settings_catalog_grammar.json` инверсий не было — guard добавлен
безопасно, ничего существующего не сломал.

**Can-fail proof.** Закомментировал вызов `assertOptionsRespectFieldMinPlan()` →
`testMalformedCatalogFailsLoudly@select option minPlan below field minPlan`
красный: `Failed asserting that exception of type "RuntimeException" is thrown.`
Восстановил → `make TEST=1 test-php FILTER=ConversionSettingsCatalogTest` снова
49/49 (после добавления теста — 49; после TASK B — 58).

Коммит: `5181b41` (`settings: reject select option minPlan below field minPlan`).

### TASK B — data (CSV/JSON) profile

**JSON-only** — карточка закрыта ЦЕЛИКОМ правкой
`app-symfony/config/catalog/conversion_settings.json` (+ 4 тестовых файла,
`git diff --stat` подтверждает — ни один PHP-класс не менялся).

### Классификация пар: TARGET-driven, не по домыслу

`conversion_pairs.json` (398 пар, не трогался) даёт **28** `category=data` пар.
Классификация — по `to`, тем же методом, что CNV-100:

- **CSV-target** (`to=csv`, любой `from`) — **5** пар: `json/toml/xml/yaml/yml → csv`.
- **JSON-target** (`to=json`, любой `from`) — **5** пар: `csv/toml/xml/yaml/yml → json`.
- **Остаток** (`to` ∈ `{toml,xml,yaml,yml}`) — **18** пар, вне scope, без профиля
  (карточка требует: YAML/TOML/XML не получают профиль и отклоняют settings).

Числа зафиксированы python-подсчётом по боевому `conversion_pairs.json` и
tripwire-ассертом `ConversionSettingsCatalogTest::testProductionCatalogAssignsDataProfiles`
(полный обход всех 28 пар, не выборка).

**Правила матчат ТОЛЬКО `to` (без `from`)** — назначение по ЦЕЛЕВОМУ формату,
той же логикой, что CNV-97 применила к `document.txt`/`document.markdown`
(`to=pdf` → правила PDF-output, а не «любая пара с PDF на любой стороне»):
поля `delimiter`/`quote`/`encoding` конфигурируют, КАК записывается CSV-выход,
`pretty`/`indent` — как записывается JSON-выход, независимо от того, что было
источником. Следствие: `csv→toml`/`csv→yaml` (CSV как ИСТОЧНИК) профиля не
получают — это сознательное чтение карточки («публиковать CSV
delimiter/quote» без уточнения направления трактовано как «для CSV-output»),
обратимое одной JSON-правкой, если предполагалось иначе (напр. настройки
разбора source CSV).

### Cross-category collision — найдена и закрыта explicit-скоупом `category`

`txt→json` — `category=document, isAi=true` (AI-экстракция структурированных
данных из текста) делит `to=json` с `data.json`, но НЕ `category`. Правило
`data.json` явно скоуплено `"category": "data"` (как и `data.csv`), поэтому
document-пара не резолвит data-профиль. Других коллизий на `to∈{csv,json}` вне
`category=data` в каталоге нет (python-подсчётом по всем 398 парам).

**Ordering walk.** Оба новых правила добавлены В КОНЕЦ `assignments`. Все
предшествующие правила скоуплены `category` ∈ `{image, document, video,
audio}` — ни одно не может матчить `category=data`, значит порядок ОТНОСИТЕЛЬНО
них не имеет значения (тот же инвариант, что уже зафиксирован в `$comment`
файла). Внутри своего блока `data.csv` (`to=["csv"]`) и `data.json`
(`to=["json"]`) имеют ДИЗЪЮНКТНЫЕ множества `to` — порядок МЕЖДУ ними тоже не
важен. Проверено НЕ только чтением: can-fail (b) ниже ломает именно этот
скоуп и ловится 3 тестами на трёх разных уровнях (catalog/presenter/validator).

### Поля

| Профиль | Поле | Тип | Значения | minPlan | default |
|---|---|---|---|---|---|
| `data.csv` | `delimiter` | select | `,` `;` `\t` `\|` | все `guest` | null |
| `data.csv` | `quote` | select | `"` `'` | все `guest` | null |
| `data.csv` | `encoding` | select | `utf-8` (единственная опция) | `guest` | null |
| `data.json` | `pretty` | boolean | — | `guest` | null |
| `data.json` | `indent` | number | `[1, 8]` | `guest` | null |

Все `minPlan: guest` — по прямому указанию team-lead (delimiter/quote/pretty/
indent дёшевы по CPU/памяти, та же логика, что применена к CSV-полям в
$comment файла ещё с CNV-85). `encoding` — тот же паттерн «фиксировано, но
явно», что `document.txt`/`document.markdown` (CNV-97): единственная легальная
опция `utf-8`, любое другое значение — предсказуемый `invalid_option_value`
(это и есть механизм AC карточки «невалидный UTF-8 отклоняется предсказуемо»
НА УРОВНЕ enum; проверка, что БАЙТЫ входного файла — валидный UTF-8, физически
принадлежит воркеру, CNV-104, каталог такой проверки не делает и не может).

**Дефолты сознательно НЕ заданы** (та же причина, что CNV-97/100): все 10
data-пар — уже работающие боевые пары, материализация default изменила бы
`ConversionMessage.options`/историю для пустого запроса. Проверено юнит- и
HTTP-тестом (`testNoOptionsMeansEmptyOptionsForDataPairs` + аналог в validator
test по всем 10 assigned-парам).

**`indent` не имеет эффекта при `pretty=false`** — закрытая грамматика каталога
не умеет выражать межполевые зависимости (оба поля независимы физически); это
на совести воркера (CNV-104, семантика) и фронта (CNV-105, UI enable/disable
`indent` когда `pretty` выключен) — не баг этой карточки, фиксирую, чтобы не
переоткрылось как находка там.

**Literal-символьные `value` у `delimiter`/`quote`** (`","`, `";"`, `"\t"`, `"|"`,
`"\""`, `"'"`) вместо символьных токенов (`"comma"`/`"tab"`/…) — решение,
которое стоит подтвердить team-lead: буквальный tab в `<select value>`
работает (см. can-fail proof ниже, HTTP round-trip подтверждён), но CNV-104/
CNV-105 наследуют этот контракт напрямую. Если предпочтительнее символьные
токены + маппинг токен→символ на воркере, это меняется в каталоге одной
правкой ДО того, как CNV-104/105 закрепят литеральный контракт в своём коде.

### Тесты (все против БОЕВОГО каталога)

- `ConversionSettingsCatalogTest::testProductionCatalogAssignsDataProfiles` —
  полный обход всех 28 `category=data` пар + tripwire-счётчики (5/5/18).
- `ConversionSettingsCatalogTest::testProductionCatalogAssignsDataProfilesExamples`
  (+`dataPairProvider`) — читаемые примеры + главный риск (`txt→json` document
  AI-пара остаётся без профиля).
- `ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile` —
  расширен: `csv->json`/`json->csv` + `csv->yaml`/`json->toml` (null) +
  `txt->json` (null, cross-category).
- `ConversionOptionsValidatorTest::testProductionDataOptionsAreValidatedAndNormalized`
  — valid delimiter/quote/encoding/pretty/indent нормализуются, пустой payload
  на всех 10 assigned-парах — `[]`.
- `ConversionOptionsValidatorTest::testProductionDataRejectionsFollowClosedGrammar`
  (+provider, 17 кейсов) — whitelist delimiter/quote, invalid UTF-8 enum,
  indent bounds, cross-target key (`indent`/`pretty` на csv-профиле и
  наоборот), arbitrary serializer option, YAML/TOML/XML → `settings_not_supported`,
  document AI-пара → `settings_not_supported`.
- `ConversionSettingsCatalogApiTest` — `rejectedRequestProvider` +8 кейсов через
  реальный HTTP; `testAcceptedDataOptionsReachTheWorkerMessageNormalized`
  (включает HTTP round-trip tab-delimiter, multipart-парсинг подтверждён
  реальным запросом, не прямым вызовом валидатора) +
  `testNoOptionsMeansEmptyOptionsForDataPairs`.

### Гейт

- `make phpstan` — OK, 0 ошибок (оба конфига).
- `make cs` / `make cs-check` — 0 из 290 файлов требуют правок.
- `make TEST=1 test-php` — **949 тестов / 5405 ассертов, 0 падений** (baseline
  этой карточки — 911/5246 после TASK A; +38 тестов/+159 ассертов от TASK B).
  12 PHPUnit-deprecations — то же число, что и в baseline, файлы карточка не
  трогала.
- `make TEST=1 test-drift` — **28 passed** (каталог пар не тронут).
- `make TEST=1 test-python` — **405 passed, 1 xfailed, 2 skipped** — сумма по
  всем 6 суб-таргетам (98+77+43+60+16+111=405 passed; xfailed/skipped — из
  AI-модуля) — ИДЕНТИЧНО заявленному baseline (`workers/` не трогался).

### Can-fail proof (каждый: сломал → красный по нужной причине → восстановил → зелёный)

**(a) Invalid-value (encoding whitelist).** Временно добавил `latin1` в
`options` поля `encoding` профиля `data.csv` → красный (правильная причина —
ожидаемый отказ не бросился):
`ConversionOptionsValidatorTest::testProductionDataRejectionsFollowClosedGrammar
@encoding rejects anything but utf-8` — `Ожидался отказ с кодом
invalid_option_value`. Восстановил → зелёный.

**(b) YAML/TOML/XML no-profile.** Временно расширил `to` правила `data.json` с
`["json"]` до `["json", "yaml"]` (симулирует утечку профиля на target вне
scope) → красный (правильная причина):
`ConversionOptionsValidatorTest::testProductionDataRejectionsFollowClosedGrammar
@yaml target rejects settings as a pair without a profile` — `Ожидался отказ с
кодом settings_not_supported`; тем же изменением поймали ещё 2 теста каталога
(`testProductionCatalogAssignsDataProfiles`: `csv->yaml unexpectedly carries a
data profile`; `testProductionCatalogAssignsDataProfilesExamples@csv to yaml
stays without a profile`: `'data.json' is identical to null`). Восстановил →
все зелёные.

**(c) Cross-category scope (`txt→json` document AI-пара).** Временно снял
`"category": "data"` у правила `data.json` (голый `to`-список) → **3** красных
по нужной причине (три разных уровня — catalog/presenter/validator):
- `ConversionSettingsCatalogTest::testProductionCatalogAssignsDataProfilesExamples
  @document AI extraction pair (txt to json) stays without a data profile` —
  `Failed asserting that 'data.json' is identical to null.`
- `ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile` —
  `Failed asserting that 'data.json' is null.`
- `ConversionOptionsValidatorTest::testProductionDataRejectionsFollowClosedGrammar
  @document AI extraction pair has no configurable data settings` — `Ожидался
  отказ с кодом settings_not_supported`.
Восстановил `"category": "data"` → `make TEST=1 test-php` снова 949/5405, 0
падений.

### Explicitly no can-fail evidence

- **Whitelist rejection of `delimiter`/`quote` individually** — покрыто ТЕМ ЖЕ
  select-enum механизмом, что доказан can-fail (a) для `encoding` (тот же
  `normalizeSelect()`), но `delimiter`/`quote` сами по себе can-fail не
  упражнялись отдельной мутацией — уверенность в них выводится из механизма,
  а не подтверждена собственной красной проверкой.
- **Cross-target `unknown_option` path** (`indent`/`pretty` на csv-профиле и
  наоборот) — покрыт тем же кодовым путём `ConversionOptionsValidator::validate()`
  (`array_keys($raw)` против `$profile->field($key)`), который уже прошёл
  can-fail в CNV-85/97/100; в этой карточке отдельной мутацией не ломался.

### Side findings / нужен ack team-lead

- **Literal `\t`/`"`/`'` как `value` select-опций** (см. раздел «Поля» выше) —
  решение зафиксировано, но не проверено пользовательским консенсусом;
  work-around дешёвый (JSON-правка), если предпочтительнее символьные токены.
- **`csv→toml`/`csv→yaml` (CSV-source) без профиля** — сознательное чтение
  «CSV delimiter/quote» как настроек CSV-OUTPUT, не CSV-source-парсинга; см.
  раздел «Классификация пар» выше. Обратимо одной строкой assignments.
- Ничего вне scope не найдено; PHP не менялся вовсе (JSON-only подтверждено
  `git diff --stat`).
