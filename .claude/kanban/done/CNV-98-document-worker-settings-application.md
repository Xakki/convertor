### Document worker settings application

**Criticality:** High

**TAGS:**
- feature
- documents
- document-worker

**Description:**
Применить нормализованные profiles CNV-97 в document-worker; не менять backend contract или frontend.

**Problem:**
Profile validation не гарантирует фактического использования page/layout/serialization options worker-ом.

**Impact:**
Успешная конвертация проигнорирует выбранные параметры и создаст недостоверный результат.

**Recommendation:**
Применять `pageRange` и `orientation` только при поддерживаемом PDF output; для TXT/Markdown писать UTF-8 и выбранный разрешённый (whitelisted) dialect — dialect влияет только на Markdown output, TXT его не получает. Для DOCX/ODT options не читать и не добавлять. Использовать только нормализованный job payload CNV-97. Catalog `pageRange` использует разделитель `,` (запятая); LibreOffice FilterData `PageRange` ждёт `;` — worker транслирует разделитель при построении команды.

**Acceptance Criteria:**
- Worker применяет page range и orientation к поддерживаемому PDF result.
- Reversed `pageRange` (например, `5-3`, start>end) — catalog-грамматика CNV-97/85 не может выразить start≤end и такое значение пропускает валидацию; worker обязан либо отклонить его предсказуемой ошибкой, либо нормализовать (переставить границы), поведение зафиксировано тестом.
- TXT и Markdown result создаются в UTF-8; выбранный dialect влияет только на Markdown output.
- DOCX/ODT conversion не получает новых options и сохраняет текущую семантику/поведение без settings.
- Worker-тесты покрывают каждую поддержанную настройку и отсутствие её эффекта на неподдерживаемых targets; fixture tests проверяют PDF output и TXT/Markdown serialization.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Зависит от CNV-85 и CNV-97.
- Frontend controls принадлежат CNV-99 и начинаются после profile; общая frontend grammar принадлежит CNV-92.
- PDF page range + orientation и TXT/Markdown UTF-8 + dialect — единственный document MVP.
- Arbitrary document engine options вне scope.

---

## Execution Log (worker, 2026-08-24)

### Что изменено

`workers/libreoffice/worker.py`:
- `convert()` (строка ~477) читает `options = job.get("options") or {}` (тот же
  идиом, что у `workers/image/worker.py`); reversed-`pageRange`-guard — до
  начала конвертации.
- `_convert()`/`_convert_markup()` получили параметр `options`, передают его
  дальше по всем веткам, применяющим настройки.
- Новые helpers: `_reversed_page_range()`, `_pdf_convert_to()` (строит
  `pdf:writer_pdf_Export:<FilterData JSON>`, `,`→`;`), `_apply_docx_orientation()`
  (page setup intermediate `.docx` через `python-docx`).
- `docker/workers/requirements-libreoffice.txt`: добавлены `python-docx==1.2.0`
  + `lxml==6.1.1` (та же версия, что у worker-image `requirements-image.txt`).

### Reversed pageRange — REJECT, не normalize

Грамматика каталога (CNV-85/97) не может выразить `start≤end`, `5-3` доходит
до воркера. Выбрано **отклонение** permanent-ошибкой (`ValueError`), не
молчаливая перестановка границ: перестановка замаскировала бы опечатку
клиента и дала бы недетерминированный для пользователя результат (он не
просил "3-5", он прислал "5-3"). Согласуется со стилем существующих
permanent-ошибок воркера (`unsupported conversion`, `pages source requires
libetonyek`). Тест `test_reversed_page_range_rejected`
(+ `..._in_later_element_rejected`, `..._equal_page_range_bounds_not_reversed`
для границы `a==b`, не reversed).

### `,` → `;` трансляция

Единственная точка — `_pdf_convert_to()`: `page_range.replace(",", ";")`,
встраивается в JSON FilterData `{"PageRange":{"type":"string","value":"..."}}`,
результат — `pdf:writer_pdf_Export:<json>` как `--convert-to` spec. Подтверждено
живым пробегом на реальном soffice ДО написания финального кода (см. ниже) —
FilterData реально ограничивает страницы, это не no-op. Multi-element range
(`"1,3-4"` → `"1;3-4"`) покрыт и unit-тестом (проверка строки) и real-fixture
тестом (`test_txt_to_pdf_applies_page_range_multi_element_real`: 150 строк
→ стабильно 3 стр.; `pageRange:"1,3"` → результат 2 стр.).

### Orientation — intermediate `.docx` + `python-docx`, не FilterData

`writer_pdf_Export` FilterData не содержит свойства ориентации (подтверждено:
чтения LibreOffice-документации по PDF-export filter properties и — важнее —
живым пробегом, который бы сразу показал `TypeError`/no-op, если бы такое
свойство читалось). Единственный рычаг — page setup исходного документа перед
экспортом. `txt→pdf` и `md→pdf` уже либо тривиально расширяются до
intermediate `.docx` (md уже идёт через pandoc→docx), либо получают его только
когда orientation реально запрошена (иначе — прежний однопроходный вызов,
0 доп. накладных для непрофилированных pdf-target пар). `python-docx` не
переставляет `page_width`/`page_height` при смене `.orientation` — переставлено
руками.

**Найденный по ходу баг (пойман TDD, не архивной документацией):**
pandoc-сгенерированный `.docx` (markup-источники — md/rst/latex/wiki) не пишет
явный `<w:pgSz>` → `section.page_width`/`page_height` возвращают `None` у
python-docx → `None > None` падает `TypeError`. Soffice-сгенерированный `.docx`
(txt/office-источники) всегда пишет явный `pgSz` — там баг не проявляется.
Красный тест: `test_md_to_pdf_applies_page_range_and_orientation_real` →
`TypeError: '>' not supported between instances of 'NoneType' and 'NoneType'`.
Фикс — фолбэк `Inches(8.5)`/`Inches(11)` (Word Letter, дефолт самого
python-docx для пустого `Document()`) когда `page_width`/`page_height` — `None`.

### markdownDialect — реально влияет ТОЛЬКО на txt→md, не на pdf→md (ack team-lead)

CNV-97 назначает `markdownDialect` обеим парам `document.markdown` (`pdf→md`,
`txt→md`) одним полем — но у них РАЗНЫЕ пути в воркере:
- **txt→md**: `txt` не входит в `_PANDOC_READER` → идёт по ветке
  soffice(txt→docx)→pandoc(docx→`<dialect>`). Хардкод `"gfm"` заменён на
  `str(options.get("markdownDialect") or "gfm")` — dialect реально доходит до
  pandoc writer. Подтверждено живым пробегом ЧЕРЕЗ РЕАЛЬНЫЙ `worker.convert()`
  (не только "голый" pandoc): `gfm` оставляет `hello_world` без экранирования,
  `markdown_strict` даёт `hello\_world` — разное явно наблюдаемое поведение.
- **pdf→md**: воркер оборачивает СЫРОЙ `pdftotext -layout` вывод как `.md`
  БЕЗ прогона через pandoc (не менялось этой карточкой). Прогон через pandoc
  ради dialect был рассмотрен и ОТКЛОНЁН: `-layout` сохраняет визуальные
  отступы колонок PDF, а markdown-reader интерпретирует ≥4 пробела как code
  block — прогон превратил бы рабочую пару в источник искажённого вывода.
  **Итог: `markdownDialect` для `pdf→md` принимается и проходит серверную
  валидацию, но НЕ ИМЕЕТ ЭФФЕКТА на выводе — AC "dialect влияет на Markdown
  output" для этой конкретной пары НЕ выполнен, это сознательный trade-off
  ради целостности `-layout`-извлечения, а не забытая реализация.**
  Зафиксировано тестом `test_pdf_to_md_dialect_option_has_no_effect_on_verbatim_wrap`.
  **Нужен ack team-lead**: если ожидалось, что dialect обязан менять и
  `pdf→md`-вывод тоже — это открытый gap, требуется отдельная карточка
  (пере-экстракция текста без `-layout` или другая стратегия), не тривиальная
  правка в рамках CNV-98.

### UTF-8

`pdftotext -enc UTF-8` (pdf→txt/md — не менялось) и pandoc (UTF-8 по
умолчанию) уже писали UTF-8 до этой карточки; явного форс-кода не добавлялось
— зафиксировано тестами: `test_txt_pdf_txt_roundtrip_preserves_cyrillic_utf8_real`
(txt→pdf→txt, кириллица round-trip через РЕАЛЬНЫЙ soffice+pdftotext) и
`test_txt_to_md_is_utf8_and_dialect_affects_output_real` (кириллица сохраняется
в обоих dialects).

### DOCX/ODT — не получают options

Никакого source/target-специфичного гейта не потребовалось: worker читает
`orientation`/`pageRange` только при `target_fmt=="pdf"`, `markdownDialect`
только при `target=="md"` — DOCX/ODT targets никогда не входят в эти ветки.
Поскольку backend (CNV-97) не назначает профиль ни одной DOCX/ODT-паре,
`options` для них всегда `{}` — комбинация "код читает по target" + "backend
никогда не шлёт для DOCX/ODT" закрывает AC без доп. валидации (соответствует
"workers не re-implement catalog validation").

### Гейт

- `make TEST=1 test-python-libreoffice`: baseline 41 passed → **57 passed**
  (+16 новых CNV-98 тестов, can-fail-пробег ниже прогонялся на этой отметке —
  57), затем после advisor-ревью добавлен ещё 1 тест
  (`..._explicit_portrait_real`) + baseline page-count guard в двух
  СУЩЕСТВУЮЩИХ real-fixture тестах (усиление, не новые test-функции) →
  **58 passed**, подтверждено повторным прогоном. 0 regressions на всех
  стадиях.
- `make TEST=1 test-python` (все 6 target'ов воркеров — `make` не печатает
  единый итог, это СУММА 6 отдельных pytest-summary строк ОДНОГО полного
  прогона после финальных правок): data 98 + ffmpeg 18 + image 43+1xfailed +
  libreoffice **58** + metrics 16 + ai 111/2skipped = **344 passed, 1 xfailed,
  2 skipped, 0 failed** (`grep -c FAILED` по полному логу = 0).
- `make TEST=1 test-php`: **874 passing, 4637 assertions, 12 deprecations** —
  идентично заявленному baseline, не затронуто (карточка PHP не трогала).
- Python lint/type-check таргетов в проекте нет (`grep` по `workers/Makefile`,
  корневому `Makefile` — только `phpstan`/`cs` для PHP) — не запускались, не
  существуют.

### Can-fail proof (мутация → красный → откат → снова зелёный)

Все три — на РЕАЛЬНОМ `worker.py` (не на тестовом дубле), файл сохранён,
мутирован, восстановлен `cp` из бэкапа, зелёный прогон подтверждён после
каждого отката.

**(a) page range применяется.** Мутация: `_pdf_convert_to()` игнорирует
`page_range`, всегда `return _SOFFICE_FILTER["pdf"]`. Красные (правильная
причина — свойство "меньше страниц" исчезло):
- `test_txt_to_pdf_applies_page_range_multi_element_real`:
  `AssertionError: assert 3 == 2` (полный документ вместо отфильтрованного).
- `test_md_to_pdf_applies_page_range_and_orientation_real`:
  `AssertionError: assert 9 == 2` (без фильтра, landscape даёт больше страниц
  на тот же текст — 9, не 6/2; тоже правильная причина, orientation свою часть
  всё ещё применяла).
- + 2 unit-теста (`..._translates_comma_to_semicolon...`,
  `..._orientation_routes_through_intermediate_docx...`) — `assert False` на
  `startswith("pdf:writer_pdf_Export:")`.
Откат → `make TEST=1 test-python-libreoffice` → 57/57 зелёные.

**(b) orientation применяется.** Мутация: в `_apply_docx_orientation()` убрана
перестановка `page_width`/`page_height` (оставлен только `section.orientation
= target_enum` — классический "флаг выставлен, геометрия нет" баг). Красные
(правильная причина — геометрия страницы не изменилась):
- `test_txt_to_pdf_applies_orientation_real`:
  `AssertionError: expected landscape page, got 595.304x841.89` (A4 portrait,
  как будто orientation не запрашивался).
- `test_md_to_pdf_applies_page_range_and_orientation_real`:
  `AssertionError: expected landscape page, got 612.0x792.0` (Letter portrait —
  заодно подтвердил, что None-фолбэк `Inches(8.5)/Inches(11)` реально
  применяется на этом пути).
Откат → 57/57 зелёные.

**(c) DOCX/ODT не затронуты.** Мутация: гейт `if target == "pdf":` перед
intermediate-docx-веткой заменён на `if True:` (убран guard). Красная
(правильная причина — docx target реально вошёл в orientation-ветку, и
`_apply_docx_orientation()` попыталась открыть фальшивые байты мока как
`.docx`):
- `test_docx_target_ignores_orientation_and_page_range_even_if_present`:
  `docx.opc.exceptions.PackageNotFoundError: Package not found at
  '/tmp/doc-pdf-.../in.docx'` — падает РАНЬШЕ ассерта `calls == ["docx"]`,
  но именно потому, что мутация заставила docx-target пройти через
  orientation-only код (тот самый регресс, который тест обязан ловить);
  коллатерально покраснели ещё 4 mocked doc→{docx,odt,txt,epub} теста (шире,
  чем минимально нужно — гейт `if True` затронул вообще все non-pdf targets,
  не только docx/odt).
Откат → 57/57 зелёные.

### Явно БЕЗ can-fail evidence (репортится по правилу, не тихо)

- **UTF-8 round-trip** — доказан ПОЗИТИВНЫМ real-fixture тестом (кириллица
  реально сохраняется через настоящий soffice/pdftotext/pandoc), но НЕ
  мутационным: не мутировал `-enc UTF-8`/pandoc-кодировку намеренно, т.к. это
  предсуществующее поведение (не добавлено этой карточкой) — ломать чужой уже
  работающий путь ради пробы избыточно для скоупа CNV-98.
- **pdf→md dialect no-effect** — структурное свойство (в коде этой ветки
  просто НЕТ чтения `markdownDialect`), не мутационное: сломать нечего в
  СМЫСЛЕ "убрать эффект", можно только ДОБАВИТЬ эффект и проверить что тест
  его поймает — это уже случай (a)/(b)-стиля для другой гипотетической
  фичи, не относится к текущему no-op.
- **DOCX/ODT default (`options=={}`) unaffected** — доказано ПОЗИТИВНЫМ тестом
  `test_pdf_target_no_options_stays_single_step` (docx→pdf, `options=={}` →
  один `run_soffice("pdf")`), can-fail proof для сценария "options ПРИСУТСТВУЮТ,
  но target не pdf/md" — это (c) выше.
- **`orientation:"portrait"` явное значение** (`test_txt_to_pdf_explicit_portrait_real`,
  добавлен на 3-м раунде самопроверки вместе с baseline page-count guard'ами) —
  только ПОЗИТИВНЫЙ real-fixture тест на ветку `wants_landscape=False`,
  мутационного пробега для него отдельно не запускал (покрыт тем же кодовым
  путём, что и мутация (b) — `orientation` вообще без разбора значения — но
  именно explicit-portrait ветка can-fail пробой не пройдена).

### Требуется деплой-действие (нужен ack team-lead)

`docker/workers/requirements-libreoffice.txt` — новая зависимость
(`python-docx==1.2.0` + `lxml==6.1.1`). Это изменение runtime-образа воркера
`libreoffice`/document, не только тестового `:test`-слоя — образ нужно
пересобрать (`make release-workers` или локально) и передеплоить на всех
хостах, где крутится этот воркер, иначе `import docx` в проде упадёт. Кроме
того, два real-fixture теста получили baseline page-count guard (доп. полный
прогон конвертации ДО основной проверки) — таргет `test-python-libreoffice`
стал заметно медленнее (~16.6s → ~18.9s в одном из прогонов), это сознательный
trade-off ради диагностируемости, не регрессия.

### Side findings

Ни одной вне-scope находки — единственная находка (pandoc-`.docx` без
`<w:pgSz>` → `None>None`) внутри own-scope этой карточки, исправлена сразу
(см. «Orientation» выше), grooming-запись не нужна.

### Нужен ack team-lead

- **`pdf→md` markdownDialect — принят, но не имеет эффекта** (см. раздел
  выше) — сознательный scoped-выбор ради целостности `-layout`-извлечения,
  не забытая реализация. Если ожидалось иначе — отдельная карточка.
- Мутация (c) задела больше тестов, чем минимально нужно (широкий `if True`
  вместо точечного) — сам can-fail proof валиден (ключевой тест покраснел по
  верной причине), не блокер.

---

## Repair round (закрытие CHANGES-REQUIRED, 2026-08-24)

Закрыты 5 пунктов ревью.

**1) `markdownDialect` для `pdf→md` — перестали рекламировать.** Каталог
(`app-symfony/config/catalog/conversion_settings.json`, `version` →
`2026-08-24.3`) разделён: `document.markdown` (только `txt→md`, поля
`encoding`+`markdownDialect`) и новый `document.markdown.verbatim` (только
`pdf→md`, только `encoding`, явный `"ocr": false` сохранён). Только JSON —
PHP-классы не менялись (`ConversionSettingsCatalog` полностью
data-driven). Обновлены тестовые сайты
(`ConversionSettingsCatalogTest.php:144-148`,
`ConversionCatalogPresenterTest.php:87-89`,
`ConversionOptionsValidatorTest.php` — `testProductionDocumentOptionsAreValidatedAndNormalized`
+ `documentRejectionProvider`, `ConversionSettingsCatalogApiTest.php::rejectedRequestProvider`),
добавлен тест на REJECT `markdownDialect` для `pdf→md` (unit + functional
HTTP-уровень) и ACCEPT для `txt→md`. Can-fail proof: временно откачен
JSON к до-split состоянию (single `document.markdown` на `["pdf","txt"]`)
— 4 теста покраснели по верной причине (ключевой:
`ConversionOptionsValidatorTest::testProductionDocumentRejectionsFollowClosedGrammar@markdownDialect
is unknown on the pdf-source markdown profile` → «Ожидался отказ с кодом
unknown_option»; ещё 2 — profile-id mismatch `document.markdown.verbatim`
vs `document.markdown`; 1 — functional-тест провалился на downstream
415-гейте, т.к. опция перестала отклоняться на уровне валидатора и запрос
пошёл дальше). Откат восстановлен, `make TEST=1 test-php` снова зелёный.
Обновлён устаревший комментарий `workers/libreoffice/worker.py:117-126`.

**2) Stale-образ без `python-docx` — permanent, не бесконечный retry.**
`_apply_docx_orientation()` (`workers/libreoffice/worker.py`) теперь ловит
`ImportError` на `import docx` и переупаковывает в `ValueError` (permanent=True
по контракту `StreamConsumerBase.process_job()`) с сообщением: `"orientation
option requires python-docx, which is missing from this worker image — the
image predates CNV-98 and needs a rebuild/redeploy
(docker/workers/requirements-libreoffice.txt)"`. Тест
`test_orientation_missing_python_docx_fails_permanent_not_retried`
симулирует отсутствие модуля через `monkeypatch.setitem(sys.modules, "docx",
None)` (стандартный приём для форса `ImportError` без реального
удаления пакета) и проверяет `pytest.raises(ValueError, match="python-docx")`
на уровне `worker.convert()` — тот же паттерн, что у reversed-pageRange теста.

**3) Direct-тест на swap-back-to-portrait + can-fail proof.** Новый
`test_apply_docx_orientation_swaps_landscape_back_to_portrait` строит
landscape-defaulted `.docx` напрямую через `python-docx` (без soffice),
вызывает `_apply_docx_orientation(docx_path, "portrait")` и проверяет
геометрию. Мутация (реальный файл `workers/libreoffice/worker.py`,
`cp`-бэкап/восстановление): `if is_landscape != wants_landscape:` →
`if wants_landscape and is_landscape != wants_landscape:` — тест
покраснел: `AssertionError: expected portrait geometry after swap-back,
got 10058400x7772400 / assert 10058400 < 7772400` (width осталась больше
height — swap не произошёл). Только этот один тест покраснел (58 остальных
зелёные) — мутация узко нацелена именно на wants_landscape=False ветку.
Откат `cp` из бэкапа → `make TEST=1 test-python-libreoffice` снова 60/60.

**4) CNV-99 — заметка про порядок orientation/pageRange.** Добавлена (без
изменения AC/scope/подзадач) — см. `.claude/kanban/todo/CNV-99-document-settings-frontend-controls.md`.

**5) Grooming-карточка на реальный `markdownDialect` для `pdf→md`** —
`.claude/kanban/grooming/CNV-123-markdowndialect-real-effect-for-pdf-md.md`.

### Гейт (repair round)

- `make TEST=1 test-php`: **876 passing, 4643 assertions, 12 deprecations**
  (baseline 874/4637 + 2 новых test-case yield), 0 failures до и после
  can-fail отката.
- `make TEST=1 test-python`: **346 passed, 1 xfailed, 2 skipped, 0 failed**
  (baseline 344/1/2; libreoffice 58→60, остальные 5 таргетов без изменений).
- `make phpstan`: 0 errors (оба конфига — основной + `phpstan-migrations.neon`).
- `make cs` → 0 fixed, `make cs-check` → 0 found.

### Коммиты

- `9fc5b2d` — `api: split document.markdown into txt→md and pdf→md profiles` (Agent: backend).
- `25d1328` — `worker: fail permanent on missing python-docx; pin orientation swap test` (Agent: worker).
- `d46deeb` — `kanban: CNV-99 pageRange/orientation note; fill CNV-123 body` (Agent: kanban).

### Известная накладка (нужно решение team-lead)

Коммит `9fc5b2d` (backend) непреднамеренно прихватил `.claude/kanban.lock`
и создание файла `.claude/kanban/grooming/CNV-123-....md` (шаблонная версия
без заполненного тела) — они уже были застейджены `kanban-new.sh` (git add)
ДО того, как я запустил `git add <backend-файлы>`, и явного `git add` для
backend-файлов оказалось недостаточно, чтобы их исключить (staging area —
общий, не per-commit). Итог: у части kanban-изменений (счётчик + создание
CNV-123) висит trailer `Agent: backend` вместо `Agent: kanban`; заполнение
тела CNV-123 и заметка на CNV-99 попали в правильный `d46deeb` (Agent:
kanban). Правило "не rollback/amend без явного одобрения" — amend/reset
не выполнялся. Функционально это не проблема (файлы в правильных местах,
ничего не потеряно и не задвоено), только неточная атрибуция агента в
истории двух файлов в одном коммите.
