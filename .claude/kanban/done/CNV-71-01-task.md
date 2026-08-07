### Генератор каталога форматов из матриц воркеров

**Criticality:** High

**TAGS:**
- feature

**Description:**
Часть эпика CNV-71 «Каталог форматов независимо от воркеров + удаление __seed__». Собрать единый машиночитаемый каталог пар (from→to, category, isAi) из захардкоженных матриц пяти Python-воркеров, положить в репо.

**Problem:**
Каталог форматов сегодня продублирован вручную в 3 местах (`ROADMAP.md:182-207`, `CuratedConversionPairs.php:22-33`, 5 матриц воркеров), источника правды нет.

**Impact:**
Без единого каталога Stage-7/8 роадмапа (перевод /formats и др. на каталог, удаление seed) блокируются — нечем заменить `worker_capabilities` как источник форматов.

**Recommendation:**
Сгенерировать каталог из захардкоженных матриц воркеров (`workers/image/worker.py:67`, `workers/libreoffice/worker.py:135`, `workers/ffmpeg/worker.py:63`, `workers/data/worker.py:20`, `workers/ai/worker.py:43-55`) один раз на сборке и закоммитить в репо. Добавить make-таргет для регенерации и тест/проверку, падающую при расхождении закоммиченного каталога с матрицами воркеров (иначе он молча протухнет — ровно та болезнь, от которой уходим).

Учесть, что часть форматов из ROADMAP Stage-7 (архивы, CAD/DWG, presentations, svg/heic/avif) вообще не имеет кода воркера — такие в каталог не попадают, это не баг.

**Acceptance Criteria:**
- В репозитории появился машиночитаемый каталог пар (from→to, category, isAi), сгенерированный из матриц воркеров
- Есть make-таргет регенерации каталога
- Есть тест/проверка, падающая при расхождении каталога с текущими матрицами воркеров
- Tests/QA green: `make phpstan`, `make cs-check`, тесты — см. CLAUDE.md

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Источник правды — код воркеров (Python-матрицы), каталог только генерируется из него, не редактируется руками — подтверждено пользователем 2026-08-04

**Execution Log:**
- Двухстадийный генератор: Python AST-извлечение блобов возможностей воркеров (`workers/tools/capabilities_ast.py` + `gen_worker_capabilities.py` → `app-symfony/config/catalog/worker_capabilities.json`, 6 блобов) → PHP-редукция через существующий `ConversionRegistry` (команда `app:catalog:generate-conversion-pairs` → `app-symfony/config/catalog/conversion_pairs.json`, 386 пар).
- Причина двухстадийности: category/isAi/precedence — политика, она осталась только в `ConversionRegistry` (`reduceCapabilities()`), в Python её нет.
- Регенерация — `make formats-catalog` (обе стадии).
- Drift-гварды: PHP-тест `ConversionPairsCatalogDriftTest` + Python `test_catalog_drift.py`, оба под `make test`.
- Сверка с эталоном `tests/Fixtures/conversion_matrix.golden.txt`: 386 из 394, ровно 8 отсутствующих — `pages->{docx,epub,html,md,odt,pdf,rtf,txt}`, исключены сознательно (LibreOffice добавляет `pages` в матрицу рантайм-проверкой libetonyek, статический каталог такое не моделирует).
- Находка: регистрирующихся workerType шесть, а не пять — `workers/ffmpeg/worker.py` регистрируется дважды, как `audio` и как `video`.
- QA: `make phpstan` чисто, `make cs-check` чисто, `make TEST=1 test-drift` 6/6, PHP-сьют зелёный кроме 2 pre-existing падений в `ConversionTextInputControllerTest` (BillingMode enum не мокается, к этой задаче отношения не имеет).
