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
- `positional` — **Pipeline C**: explicit
  `options[positionalLayout]=bbox|bbox-layout` selects the Poppler input,
  then the project structural parser → writer выбранного `markdownDialect`.
  `bbox` means the `pdftotext -bbox` XML input; `bbox-layout` means the
  `pdftotext -bbox-layout` XML input. There is no implicit default or fallback
  between these two inputs. `positionalLayout` is valid only with
  `pdfMode=positional` and is required for that mode.

`plain`, `normalized` и `positional` — только явные form choices для visual
тестирования; каждый такой режим принимает и требует `markdownDialect`.
`positional` additionally accepts exactly one explicit `positionalLayout` value;
`verbatim` and `plain`/`normalized` reject that option.
`verbatim` не принимает `markdownDialect`. Контракт действует только для
`pdf→md`; семантика `txt→md`, включая существующее применение
`markdownDialect` через Pandoc, сохраняется без изменений.

`/formats` должен рекламировать для `pdf→md` четыре варианта и поведение формы:
`verbatim` — default без поля диалекта, `plain` и `normalized` — explicit choices
с обязательным `markdownDialect`, а `positional` — explicit choice с обязательными
`markdownDialect` и `positionalLayout` (`bbox` или `bbox-layout`). API и UI
передают те же имена полей; новый alias для `positionalLayout` не вводится.
Queue сохраняет канонически нормализованные options. Для каждого job audit /
provenance хранит effective `pdfMode`, сохранённый `pipeline` и переданные
значения `markdownDialect`/`positionalLayout` по их применимости:

```json
{
  "pdfMode": "verbatim|plain|normalized|positional",
  "pipeline": "verbatim|plain|normalized|positional",
  "markdownDialect": "gfm|commonmark|markdown|markdown_strict",
  "positionalLayout": "bbox|bbox-layout"
}
```

`pdfMode` присутствует всегда: отсутствие входного значения нормализуется в
`verbatim`. Для каждого non-verbatim режима требуется `markdownDialect`, а для
`positional` требуется ровно одно из `bbox` и `bbox-layout` в
`positionalLayout`; вне `positional` layout не используется. `pipeline` —
сохранённое effective значение, а не поле, которое replay/audit вычисляет из
`pdfMode` или старого job payload. Replay требует сохранённый `pipeline` для
каждого persisted job; `positionalLayout` требуется только при
`pdfMode=positional` и не требуется для `verbatim`, `plain` или `normalized`.
Replay останавливается при отсутствии или несогласованности требуемых полей;
неизвестные значения, режимы не для `pdf→md`, `markdownDialect` с `verbatim`/
отсутствующим `pdfMode`, `positionalLayout` вне `positional`, неизвестный layout
и отсутствие dialect в non-verbatim режиме отклоняются fail-closed до постановки
queue. Способ сериализации неприменимых полей этим контрактом не
предписывается.

Требования к реализации должны быть отдельными для каждого pipeline:

- **A / plain:** bounded CPU, память, временное пространство и wall time для
  `pdftotext` и Pandoc; sandbox subprocess, безопасные имена временных файлов,
  отсутствие shell-инъекций и fixture для обычного текста, колонок и таблиц.
- **B / normalized:** те же resource/security gates плюс bounded проектная
  нормализация, детерминированный нормализатор и fixture, отделяющие ложные
  отступы колонок от настоящей структуры и проверяющие таблицы.
- **C / positional:** the form must pass the explicit `positionalLayout` through
  unchanged. `bbox` invokes Poppler `pdftotext -bbox` and the parser accepts its
  bbox XML; `bbox-layout` invokes `pdftotext -bbox-layout` and the parser accepts
  its layout XML. The parser must reject a mismatched/ambiguous input rather
  than detect or switch formats. Apply the same resource/security gates plus
  bounded bbox-data, parser-memory/time and pathological-PDF protections.
  Separate fixtures are required for both Poppler XML variants, each covering
  coordinates, columns, tables, headings and empty text; audit fixtures must
  prove that both `positionalLayout` and the selected pipeline survive replay.
  Parser and writer must be deterministic.

For every A/B/C fixture, audit assertions must compare the persisted tuple
`(pdfMode, pipeline, markdownDialect, positionalLayout)` with the executed
inputs; a replay must not infer a pipeline from a missing or legacy field.
For all pipelines, use redaction-safe audit logs, prohibit network access,
isolate temporary artifacts and clean up after errors. Fidelity-пороги,
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
  `positional` — Pipeline C: `positionalLayout=bbox` вызывает `pdftotext -bbox`
  и bbox parser, `positionalLayout=bbox-layout` вызывает `pdftotext -bbox-layout`
  и layout parser; выбор обязателен, явен и не переключается неявно. Все три
  требуют явный `markdownDialect`.
- `/formats` и форма описывают `positionalLayout` только для `positional` и
  предлагают ровно `bbox`/`bbox-layout`; неизвестный или отсутствующий layout
  в Pipeline C отклоняется.
- Канонический audit/provenance для каждого job всегда содержит effective
  `pdfMode` (missing → `verbatim`) и сохранённый `pipeline`. Для non-verbatim
  требуется `markdownDialect`; для `positional` требуется `positionalLayout` со
  значением `bbox` или `bbox-layout`. Replay/audit не выводит pipeline из mode
  или legacy payload и fail-closed при отсутствии/несогласованности требуемых
  полей. Сериализация неприменимых полей не нормируется.
- Неизвестный `pdfMode`, `pdfMode` вне `pdf→md`, dialect с verbatim или
  отсутствующим mode, `positionalLayout` с любым mode кроме `positional`,
  неизвестный layout, отсутствие layout в `positional`, а также non-verbatim
  без `markdownDialect` отклоняются fail-closed до постановки задачи.
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
  Pandoc; C = `positional` с явным отображением `bbox` → `pdftotext -bbox` →
  bbox parser и `bbox-layout` → `pdftotext -bbox-layout` → layout parser →
  dialect writer. Их production fidelity и ресурсные пороги не выбраны.
- 2026-09-02: контракт действует только для `pdf→md`; `txt→md` сохраняет
  существующий Pandoc-путь `markdownDialect`. `/formats`, API и UI показывают
  четыре варианта и передают `pdfMode` плюс существующий
  `markdownDialect`; новые имена полей не вводятся.
- 2026-09-02: normalized options queue и audit/provenance обязаны сохранять
  effective `pdfMode`, pipeline и dialect для воспроизводимости. Неизвестные
  режимы и несовместимые/неполные комбинации отклоняются fail-closed.
- 2026-09-02: для Pipeline C утверждён только явный
  `options[positionalLayout]=bbox|bbox-layout`: `bbox` означает Poppler
  `pdftotext -bbox` + bbox parser, `bbox-layout` — `pdftotext -bbox-layout` +
  layout parser. Option обязательна при `pdfMode=positional`, запрещена во всех
  остальных режимах; implicit detection/fallback запрещены.
- 2026-09-02: canonical audit schema обязана явно хранить `pdfMode` для каждого
  job (missing → `verbatim`) и сохранённый `pipeline`; non-verbatim jobs
  сохраняют dialect, а positional jobs — выбранный `positionalLayout`.
  Replay/audit не выводит pipeline из `pdfMode`; сериализация неприменимых полей
  не нормируется.
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
- 2026-09-02 repair review: recorded dual Poppler input contract via
  `options[positionalLayout]`, per-input parser/fixture/audit requirements,
  canonical audit normalization, and fail-closed invalid combinations. No
  `docs/roadmap-current-priorities` file exists in this checkout; no roadmap
  file was created or changed.
