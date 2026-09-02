### markdownDialect real effect for pdf→md

**Criticality:** Minor

**TAGS:**
- tech-debt
- documents
- document-worker

**Description:**
CNV-97 назначает профиль `document.markdown` обеим парам markdown-триангля —
`pdf→md` и `txt→md` — одним и тем же правилом `assignments`. Реализация CNV-98
показала, что у воркера (`workers/libreoffice/worker.py`) это два разных
исполнения: `txt→md` реально прогоняет диалект через pandoc writer, а `pdf→md`
— нет. Эта карточка фиксирует утверждённый публичный контракт четырёх режимов
`pdf→md` и три согласованных extraction/dialect pipeline для дальнейшей
реализации.

**Problem:**
`pdf→md` в воркере оборачивает вывод `pdftotext -layout` как `.md` без прогона
через pandoc — `markdownDialect` там физически не читается.

Код (`workers/libreoffice/worker.py`, функция `_convert()`, PDF-source ветка,
~строки 391-402):
```python
with tempfile.TemporaryDirectory(prefix="pdf-tmp-") as tmp:
    txt_path = Path(tmp) / f"{stem}.txt"
    await run_pdftotext(src, txt_path)
    if target in ("txt", "md"):
        # CNV-98: pdf→md намеренно НЕ прогоняется через pandoc — сырой
        # -layout вывод pdftotext (с фикс. отступами колонок) ломает
        # markdown-reader (≥4 пробела → code block). markdownDialect
        # для этой пары в CNV-97 назначен, но реально влияет только на
        # txt→md (см. блок «target md» ниже); scoped-решение, см.
        # Execution Log CNV-98 — ack team-lead.
        out.write_bytes(txt_path.read_bytes())
        return out, _MIME[target], target
```
Для сравнения — блок `target == "md"` (~строки 417-431), который реально
читает `options.get("markdownDialect")` и прогоняет через
`run_pandoc(..., dialect)`, отрабатывает только для источников из
`_PANDOC_READER` либо для `txt` через промежуточный
`soffice(txt→docx)→pandoc`; `pdf` в эту ветку не попадает вовсе — return
происходит раньше, в PDF-source-ветке выше.

**Impact:**
Пользователь, ожидающий выбрать Markdown-диалект (GFM/CommonMark/Pandoc
Markdown/strict) для конвертации PDF→MD, либо не увидит поле в UI, либо
получит опцию без эффекта на результат. Неявное добавление обработки также
может изменить текущий verbatim-результат и нарушить воспроизводимость задач.

**Recommendation:**
Реализовать для `pdf→md` один явный enum-контракт:
`options[pdfMode]=verbatim|plain|normalized|positional`.

- Если `pdfMode` отсутствует, он нормализуется как `verbatim`.
- `verbatim` — сохранённый legacy-путь: `pdftotext -layout`, результат без
  Pandoc; `markdownDialect` запрещён.
- `plain` — **Pipeline A**: `pdftotext` без `-layout` → Pandoc с выбранным
  `markdownDialect`.
- `normalized` — **Pipeline B**: `pdftotext -layout` → проектная нормализация
  → Pandoc с выбранным `markdownDialect`.
- `positional` — **Pipeline C**: `pdftotext -bbox`/`bbox-layout` → проектный
  структурный parser → writer выбранного `markdownDialect`.

`plain`, `normalized` и `positional` — только явные form choices для visual
тестирования; каждый такой режим принимает и требует `markdownDialect`.
`verbatim` не принимает `markdownDialect`. Контракт действует только для
`pdf→md`; семантика `txt→md`, включая существующее применение
`markdownDialect` через Pandoc, сохраняется без изменений.

`/formats` должен рекламировать для `pdf→md` четыре варианта и поведение формы:
`verbatim` — default без поля диалекта, три остальных — explicit choices с
обязательным полем `markdownDialect`. API и UI передают тот же `pdfMode`, без
новых имён полей. Queue сохраняет нормализованные `pdfMode` и
`markdownDialect` в существующих normalized options; audit/provenance должны
сохранять эффективный режим, pipeline и диалект так, чтобы повтор задачи был
воспроизводим. Неизвестные значения, режимы не для `pdf→md`,
`markdownDialect` с `verbatim`/отсутствующим `pdfMode` и отсутствие диалекта в
non-verbatim должны отклоняться fail-closed до постановки в queue.

Требования к реализации должны быть отдельными для каждого pipeline:

- **A / plain:** bounded CPU, память, временное пространство и wall time для
  `pdftotext` и Pandoc; sandbox subprocess, безопасные имена временных файлов,
  отсутствие shell-инъекций и fixture для обычного текста, колонок и таблиц.
- **B / normalized:** те же resource/security gates плюс bounded проектная
  нормализация, детерминированный нормализатор и fixture, отделяющие ложные
  отступы колонок от настоящей структуры и проверяющие таблицы.
- **C / positional:** те же базовые gates плюс bounded объём bbox-данных,
  parser-память/время и защита от pathological PDF; parser и writer должны
  быть детерминированными, а fixture должны покрывать координаты, колонки,
  таблицы, заголовки и отсутствие текста.

Для всех pipeline нужны redaction-safe audit logs, запрет сетевого доступа,
изоляция временных артефактов и cleanup после ошибки. Fidelity-пороги,
метрики, допустимое расхождение таблиц/колонок и окончательный выбор
pipeline для production не выдумываются и остаются Open questions.

**Acceptance Criteria:**
- Публичный контракт `options[pdfMode]` для `pdf→md` содержит ровно
  `verbatim`, `plain`, `normalized`, `positional`; отсутствие значения даёт
  effective `verbatim`.
- `verbatim` использует `pdftotext -layout`, не вызывает Pandoc и отклоняет
  `markdownDialect`; прежний результат не меняется silently.
- `plain` реализует Pipeline A (`pdftotext` без `-layout` → Pandoc),
  `normalized` — Pipeline B (`-layout` → project normalization → Pandoc),
  `positional` — Pipeline C (`-bbox`/`bbox-layout` → project structural parser
  → dialect writer); все три требуют явный `markdownDialect`.
- `/formats` возвращает четыре варианта `pdf→md`, а форма показывает default
  verbatim без dialect-поля и показывает/требует `markdownDialect` для A/B/C;
  API/UI используют только согласованный `pdfMode` и существующее имя
  `markdownDialect`.
- Queue сохраняет effective `pdfMode`, pipeline и `markdownDialect` в
  normalized options; audit/provenance содержит те же значения для
  воспроизводимой повторной обработки.
- Неизвестный `pdfMode`, `pdfMode` вне `pdf→md`, dialect с verbatim или
  отсутствующим mode, а также non-verbatim без `markdownDialect` отклоняются
  fail-closed до постановки задачи.
- `txt→md` сохраняет текущую семантику `markdownDialect` и не получает
  побочных изменений от разделения PDF-режимов.
- Real fixtures и bounded visual tests покрывают для A/B/C обычный текст,
  многоколоночный PDF, таблицы, заголовки и pathological/empty-text случай;
  отдельные resource/security/cleanup проверки существуют для каждого
  pipeline. Fidelity thresholds не считаются acceptance-гейтом до решения
  Open questions.
- После реализации профильные tests/QA green: `make TEST=1
  test-python-libreoffice`, `make TEST=1 test-php`, `make phpstan`.

**Open questions:**
- Какие из Pipeline A/B/C выбрать для production default/поддержки после
  проверки real fixtures и bounded visual tests? До этого все три остаются
  явно документированными form choices; `verbatim` остаётся default.
- Каковы fidelity-метрики и пороги отдельно для verbatim, A, B и C, включая
  допустимое расхождение таблиц и колонок? Пороги не выбраны до benchmark.
- Какой точный ресурсный бюджет и fixture corpus нужны для каждого pipeline?
  Границы CPU/памяти/wall time/временного пространства должны быть измерены,
  а не придуманы в этой карточке.

**Decisions:**
- 2026-09-02: решение `options[pdfMode]=verbatim|dialect` superseded по
  утверждённому пользовательскому контракту. Публичный enum CNV123 теперь
  ровно `verbatim|plain|normalized|positional`; `dialect` больше не является
  допустимым значением.
- 2026-09-02: отсутствие `pdfMode` означает effective `verbatim`;
  `verbatim` использует `pdftotext -layout`, не применяет `markdownDialect` и
  сохраняет legacy-результат. Все non-verbatim режимы — explicit form choices
  для visual testing и требуют `markdownDialect`.
- 2026-09-02: утверждены pipeline choices: A = `plain`, `pdftotext` без
  `-layout` → Pandoc; B = `normalized`, `-layout` → project normalization →
  Pandoc; C = `positional`, `-bbox`/`bbox-layout` → project structural parser
  → dialect writer. Их production fidelity и ресурсные пороги не выбраны.
- 2026-09-02: контракт действует только для `pdf→md`; `txt→md` сохраняет
  существующий Pandoc-путь `markdownDialect`. `/formats`, API и UI показывают
  четыре варианта и передают `pdfMode` плюс существующий
  `markdownDialect`; новые имена полей не вводятся.
- 2026-09-02: normalized options queue и audit/provenance обязаны сохранять
  effective `pdfMode`, pipeline и dialect для воспроизводимости. Неизвестные
  режимы и несовместимые/неполные комбинации отклоняются fail-closed.
- 2026-09-02: для A/B/C обязательны отдельные resource, security, fixture,
  visual-test и cleanup gates; thresholds и final production selection остаются
  открытыми до измерений. Карточка остаётся в `grooming/` и не перемещается.
- CNV-98 (repair-раунд, 2026-08-24) выбрал НЕ чинить это в своём скоупе —
  вместо этого каталог перестал рекламировать `markdownDialect` для `pdf→md`
  (см. `.claude/kanban/done/CNV-98-document-worker-settings-application.md`,
  раздел Execution Log "Нужен ack team-lead").

**Execution Log:**
- Authorization: explicit user approval in task hand-off; scope ограничен
  изменением этой grooming-карточки, без source/runtime/config/deploy.
- Agent/zone: convertor/docs-kanban; Gate: `git diff --check` → clean.
- Gate: targeted/full `kanban-lint.sh` attempted → command unavailable in the
  current PATH (exit 127); canonical lint result не заявляется.
- Reviewer: self-review against requested enum, A/B/C pipelines, fail-closed,
  queue/audit, resource/security/fixture gates and retained Open questions;
  Commit: `d8a3651`.
- Prompt evidence (optional): sanitized task hand-off; full prompts/secrets не
  записываются.
