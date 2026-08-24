### Document settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- documents
- backend
- conversion-options

**Description:**
Определить document profiles и серверную validation для PDF, TXT и Markdown.

**Problem:**
Без domain profile API не может отличить применимые document fields от несовместимых DOCX/ODT options.

**Impact:**
Невалидные page/layout/encoding values попадут в job либо будут обещаны неподдерживаемым targets.

**Recommendation:**
После CNV-85 назначить точным supported pairs PDF fields `pageRange` и `orientation`, TXT/Markdown — фиксированный UTF-8 и whitelisted Markdown dialect. Не назначать profile DOCX/ODT и не добавлять им fields.

**Acceptance Criteria:**
- Catalog назначает profiles только поддерживаемым PDF, TXT и Markdown pairs.
- Backend валидирует page range, orientation, UTF-8 и Markdown dialect и сериализует normalized options.
- DOCX/ODT и неподдерживаемые pairs отклоняют document settings как settings без profile.
- API tests покрывают valid/invalid values и pair-specific access.

**Decisions:**
- Зависит от CNV-85; CNV-98 и CNV-99 начинаются после profile.
- Document MVP: PDF page range + orientation, TXT/Markdown UTF-8 + dialect.
- UI grammar принадлежит CNV-92, worker application — CNV-98.

---

## Execution Log (backend, 2026-08-24)

### JSON-only — extension point подтверждён

Карточка закрыта ЦЕЛИКОМ правкой `app-symfony/config/catalog/conversion_settings.json`
(+ тесты). Ни один PHP-класс из `App\Service\Conversion\Settings\*` не менялся — расширение
работает ровно так, как задумано CNV-85.

### Три новых профиля, назначение по точным парам

Каталог `conversion_pairs.json` (398 пар, не трогался) даёт ровно 6 пар, где ОБЕ стороны
входят в {pdf, txt, md} — это и есть весь scope карточки:

| Пара | Профиль | Поля |
|---|---|---|
| `txt→pdf`, `md→pdf` | `document.pdf` | `pageRange` (text), `orientation` (select: portrait/landscape) |
| `pdf→txt`, `md→txt` | `document.txt` | `encoding` (select, единственная опция `utf-8`) |
| `pdf→md`, `txt→md` | `document.markdown` | `encoding` (utf-8), `markdownDialect` (select: gfm/commonmark/markdown/markdown_strict) |

Все поля `minPlan: guest` (Guest-политика — page range/orientation/encoding/dialect дёшевы
по CPU/памяти).

**Почему `pageRange`+`orientation` НЕ достаются `pdf→txt`/`pdf→md`.** CNV-76 явно говорит
«применять pageRange и orientation только при поддерживаемом PDF OUTPUT» — т.е. только когда
`to=pdf`. Когда PDF — источник (`pdf→txt`, `pdf→md`), релевантны настройки ЦЕЛИ (encoding
TXT / encoding+dialect Markdown), а не PDF. Это сознательное чтение CNV-76, а не домысел —
если ревью не согласно, это решение обратимо правкой одной JSON-строки.

**DOCX/ODT.** Правила матчат `from`/`to` ЯВНЫМИ списками (`["txt","md"]`/`["pdf","md"]`/
`["pdf","txt"]`), а не одним `category: document` — поэтому `docx→pdf`, `odt→pdf`, `docx→txt`,
`odt→txt`, `docx→md`, `odt→md` (все реальные пары в каталоге, все `category: document`) НЕ
матчат ни одно правило и продолжают резолвиться в `null` → отказ `settings_not_supported`
(тот же путь, что уже был у `docx→pdf` до этой карточки — существующие тесты CNV-85 на этом
инварианте не сломались).

**ocr.** `pdf→txt`/`pdf→md` — `ocrCapable: true` в реестре; правила `document.txt`/
`document.markdown` несут явный `"ocr": false` (тот же стиль, что и у image-правил CNV-85),
поэтому запрос с `ocr=1` не резолвит профиль — настройки отклоняются, как и раньше.

**pageRange grammar.** Тип `text`, `pattern` (без anchors, движок оборачивает `/^(?:…)$/u`):
`[1-9][0-9]*(-[1-9][0-9]*)?(,[1-9][0-9]*(-[1-9][0-9]*)?)*` — список/диапазоны страниц без
ведущих нулей и без страницы `0` (симметрично `normalizeNumeric`'s `^-?(?:0|[1-9][0-9]*)$` и
тесту-прецеденту «leading-zero integer is not an integer»). Разделитель диапазонов — запятая
(LibreOffice `PageRange` FilterData использует `;` — тривиально транслируется в CNV-76,
явно фиксирую здесь, чтобы не открылось заново там). Порядок `a-b` (a>b, «9-3») грамматикой
НЕ проверяется — вне возможностей закрытой грамматики CNV-85, тоже на совести CNV-76/98.

**Дефолты — сознательно НЕ заданы** (`encoding`/`markdownDialect`/`pageRange`/`orientation`
все без `default`). Первая версия карточки материализовала `encoding: "utf-8"` (+`gfm` для
markdown) по умолчанию, но `pdf→txt`, `md→txt`, `pdf→md`, `txt→md` — это уже РАБОТАЮЩИЕ боевые
пары (существовали в `conversion_pairs.json` и до этой карточки, просто без настроек);
материализация default изменила бы `ConversionMessage.options`/историю для пустого запроса на
этих парах — то самое, что CNV-85 explicitly отказался делать для image-полей («Дефолты
image-полей оставлены `null`... иначе payload для боевых пар изменился бы»). Убрал `default`
у всех новых полей — пустой запрос даёт пустые `options` на всех 6 парах триангля (проверено
юнит-тестом `testProductionDocumentOptionsAreValidatedAndNormalized` по всем 6 парам + HTTP-тестом
`testNoOptionsMeansEmptyOptionsForDocumentPairs` на `pdf→txt`).

### Тесты (новые, все против БОЕВОГО каталога — синтетическая фикстура `test.grammar` не трогалась)

- `ConversionSettingsCatalogTest::testProductionCatalogAssignsDocumentProfiles` (+provider) —
  резолв профиля/полей по всем 6 парам триангля + 6 DOCX/ODT негативам + 2 OCR-негатива.
- `ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile` — расширен: 6 пар
  триангля в `GET /formats`-representation + 3 DOCX/ODT `null`.
- `ConversionOptionsValidatorTest::testProductionDocumentOptionsAreValidatedAndNormalized` —
  valid pageRange/orientation/encoding/markdownDialect нормализуются, пустой payload у всех
  6 пар триангля — `[]`.
- `ConversionOptionsValidatorTest::testProductionDocumentRejectionsFollowClosedGrammar`
  (+provider, 14 кейсов) — invalid pattern/enum/type/length, pair-specific access
  (markdownDialect на `document.pdf`, pageRange на `document.txt`, orientation на
  `document.markdown` → `unknown_option`), DOCX/ODT → `settings_not_supported`.
- `ConversionSettingsCatalogApiTest` — расширен `rejectedRequestProvider` (7 новых кейсов
  через реальный HTTP) + 2 новых позитивных теста (`testAcceptedDocumentOptionsReachTheWorkerMessageNormalized`,
  `testNoOptionsMeansEmptyOptionsForDocumentPairs`) — сериализация в `ConversionMessage.options`
  через полный стек (реальный `ConversionManager` со stub-коллабораторами, не мок).

**Инфраструктурная находка (не продовый баг):** `uploadedFile()`-хелпер этого файла пишет
фиксированные JPEG magic bytes независимо от имени файла — годится для image-пар (любой
`image/*` проходит category-гейт), но `ConversionManager::assertMimeAllowed()` для
`document`-категории требует `application/*`/`text/*`, так что позитивные document-тесты
получали настоящий 415 от РЕАЛЬНОГО (не мокнутого) `ConversionManager` внутри `stubbedManager()`
(там мокнуты только коллабораторы — repository/quota/EntityManager/S3/bus, — сам класс реальный,
гейты реально выполняются). Добавлен соседний хелпер `documentUploadedFile()` с текстовым
содержимым (`text/plain` сниффится корректно) — использован только в 2 новых позитивных тестах.

### Гейт

- `make phpstan` — OK, 0 ошибок (оба конфига).
- `make cs` / `make cs-check` — 0 из 290 файлов требуют правок.
- `make TEST=1 test-php` — **874 теста / 4637 ассертов, 0 падений** (было 836/4529 — task-prompt
  baseline, +38 тестов/+108 ассертов). 12 PHPUnit-deprecations — то же число, что и в
  baseline (CNV-85 repair round), в файлах, которые эта карточка не трогала.

### Can-fail proof

1. **DOCX/ODT no-profile.** Временно добавил `"docx"` в `from` правила `document.pdf` →
   `docx→pdf` резолвится в `document.pdf`. Красные (правильная причина — profile-id/код
   отказа):
   - `ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile` —
     `Failed asserting that 'document.pdf' is null.`
   - `ConversionOptionsValidatorTest::testProductionDocumentRejectionsFollowClosedGrammar@docx to pdf …` —
     `Ожидался отказ с кодом settings_not_supported` (исключение не бросилось — `pageRange`
     стал легальным полем для `docx→pdf`).
   - `ConversionSettingsCatalogTest::testProductionCatalogReproducesLegacyImageAllowlist@document pair has no profile` —
     `Failed asserting that 'document.pdf' is identical to null.`
   - `ConversionSettingsCatalogTest::testProductionCatalogAssignsDocumentProfiles@docx to pdf stays without a profile` —
     то же. Восстановил `from` → все 4 снова зелёные.
2. **Invalid value (pageRange).** Временно вернул широкий паттерн `[0-9]+(-[0-9]+)?(,…)*`
   (допускает `0` и ведущие нули) → красные (правильная причина — ожидаемый отказ не бросился):
   - `…@pageRange rejects leading zero` — `Ожидался отказ с кодом invalid_option_value`
   - `…@pageRange rejects page zero` — то же.
   Восстановил `[1-9][0-9]*(-[1-9][0-9]*)?(,[1-9][0-9]*(-[1-9][0-9]*)?)*` → зелёные, полный
   `make TEST=1 test-php` подтверждён 874/4637 после отката.

### Side findings

Одна, in-scope, исправлена сразу (cplx ≤3/10, не требует grooming-записи): `uploadedFile()`-хелпер
`ConversionSettingsCatalogApiTest` пишет фиксированные JPEG magic bytes независимо от имени файла —
для `document`-категории (`application/*`/`text/*`) это даёт настоящий 415 от РЕАЛЬНОГО
`ConversionManager` (см. раздел «Тесты» выше). Добавлен соседний хелпер `documentUploadedFile()`
с текстовым содержимым; использован в 2 новых позитивных document-тестах, остальные тесты файла
не тронуты.

### Нужен ack team-lead

- **Чтение CNV-76** («pageRange/orientation только при `to=pdf`») как основание НЕ давать
  `pageRange` парам `pdf→txt`/`pdf→md` (PDF-как-источник) — раздел выше. Если предполагалось
  иначе (например, page-selection и при чтении PDF), это меняется одной строкой в assignments,
  без затрагивания PHP.
- Разделитель `pageRange` — запятая, не `;` как в LibreOffice FilterData — транслируется в
  CNV-76/98, не блокер здесь.
