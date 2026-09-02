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
исполнения: `txt→md` реально прогоняет диалект через pandoc writer, а
`pdf→md` — нет. Эта карточка фиксирует одобренный контракт для дальнейшей
доработки `pdf→md`: явный `options[pdfMode]=verbatim|dialect`, два отдельных
плоских профиля и сохранение выбора в уже существующих normalized options.

**Problem:**
`pdf→md` в воркере оборачивает СЫРОЙ вывод `pdftotext -layout` как `.md`
без прогона через pandoc — `markdownDialect` там физически не читается.

Код (`workers/libreoffice/worker.py`, функция `_convert()`, PDF-source
ветка, ~строки 391-402):
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
Для сравнения — блок `target == "md"` (~строки 417-431), который РЕАЛЬНО
читает `options.get("markdownDialect")` и прогоняет через
`run_pandoc(..., dialect)`, отрабатывает только для источников из
`_PANDOC_READER` (md/html/docx/odt/epub/rst/latex/wiki) либо для `txt`
через промежуточный `soffice(txt→docx)→pandoc`; `pdf` в эту ветку не
попадает вовсе — return происходит раньше, в PDF-source-ветке выше.

**Impact:**
Пользователь, ожидающий выбрать Markdown-диалект (GFM/CommonMark/Pandoc
Markdown/strict) для конвертации PDF→MD, либо не увидит поле в UI (после
CNV-98 catalog-фикса — `document.markdown.verbatim` не декларирует
`markdownDialect`), либо (если кто-то восстановит поле не разобравшись)
получит опцию, не имеющую эффекта на результат — недостоверный UX.

**Recommendation:**
Реализовать два явно различимых режима `pdf→md` через согласованный
`options[pdfMode]=verbatim|dialect`, не меняя молча текущий результат:
- **verbatim** — если `pdfMode` отсутствует, нормализовать его как `verbatim`;
  сохранять `pdftotext -layout` и текущую семантику;
- **dialect** — выбирать отдельный плоский профиль только при явном
  `pdfMode=dialect` и применять `markdownDialect` в выбранном pipeline.

`markdownDialect` разрешён только вместе с `pdfMode=dialect`; в verbatim,
при отсутствии `pdfMode` и для любой пары, кроме `pdf→md`, он должен быть
отклонён. Queue должна сохранить выбранный `pdfMode` в существующих
normalized options. Каталог `/formats` обязан рекламировать вариантность
режима, а связанные API и UI должны передавать и отображать тот же выбор;
новые имена полей этой карточкой не вводятся.

Нельзя просто прогонять текущий `pdftotext -layout` через pandoc: пробелы
колонок могут стать ложными code block. Extraction pipeline для dialect нужно
выбрать эмпирически по многоколоночным и табличным fixture. Fidelity-пороги
и метрики должны быть измерены отдельно для обоих режимов до acceptance
реализации. Семантика `txt→md`, включая существующее применение
`markdownDialect` через pandoc, сохраняется без изменений.

**Acceptance Criteria:**
- Для `pdf→md` отсутствие `pdfMode` нормализуется как `pdfMode=verbatim`,
  сохраняется `pdftotext -layout`, и прежний результат не меняется silently.
- `options[pdfMode]` принимает только `verbatim` и `dialect`; `dialect` выбирает
  отдельный плоский профиль, а queue сохраняет выбор в существующих normalized
  options.
- `markdownDialect` принимается только для `pdf→md` с `pdfMode=dialect`;
  в verbatim, при отсутствии `pdfMode` и для иных пар запрос отклоняется.
- `pdf→md` с `pdfMode=dialect` реально меняет результат хотя бы для одного
  нетривиального диалекта после подтверждения extraction pipeline.
- `/formats` рекламирует оба варианта `pdf→md`, а API и UI поддерживают выбор
  через согласованный `pdfMode`; дополнительных имён полей не появляется.
- `txt→md` сохраняет текущую семантику `markdownDialect` и не получает
  побочных изменений от разделения PDF-режимов.
- Real fixtures покрывают многоколоночный и табличный PDF в обоих режимах;
  bounded benchmark отдельно измеряет fidelity и фиксирует согласованные
  метрики/пороги до acceptance реализации.
- После реализации профильные tests/QA green: `make TEST=1
  test-python-libreoffice`, `make TEST=1 test-php`, `make phpstan`.

**Open questions:**
- Какой extraction pipeline использовать для dialect-enabled режима: другой
  режим `pdftotext`, отдельные table heuristics или сторонний PDF→Markdown
  инструмент? Ответить по real fixtures и bounded benchmark.
- Каковы fidelity-метрики и пороги отдельно для verbatim и dialect-enabled,
  включая допустимое расхождение таблиц и колонок? Порог не выбран до
  benchmark.

**Decisions:**
- 2026-09-02: утверждён продуктовый контракт двух явных `pdf→md` режимов:
  verbatim с `pdftotext -layout` по умолчанию и opt-in dialect-enabled режим.
  Отсутствие явного user/API/profile выбора означает verbatim; silent default
  behavior change запрещён.
- 2026-09-02: выбран явный selector `options[pdfMode]=verbatim|dialect`.
  Отсутствующий `pdfMode` нормализуется как `verbatim`; `dialect` выбирает
  отдельный плоский профиль. `markdownDialect` разрешён только с `dialect`,
  selector валиден только для `pdf→md`, а queue сохраняет выбор в существующих
  normalized options.
- 2026-09-02: `/formats` должен рекламировать variant selection для `pdf→md`,
  а API и UI должны поддержать его через `pdfMode`; новые имена полей не
  добавляются. Профили остаются отдельными и плоскими.
- 2026-09-02: `txt→md` сохраняет текущую семантику — выбранный
  `markdownDialect` применяется существующим pandoc-путём; PDF-режимы не
  меняют этот контракт.
- 2026-09-02: extraction pipeline, fidelity thresholds и окончательное
  представление профилей не считаются решёнными; их нужно выбрать после
  multi-column/table real fixtures и bounded benchmark отдельно для обоих
  режимов. Карточка остаётся в `grooming/`, не перемещается в `todo/`.
- CNV-98 (repair-раунд, 2026-08-24) выбрал НЕ чинить это в своём скоупе —
  вместо этого каталог перестал рекламировать `markdownDialect` для
  `pdf→md` (см. `.claude/kanban/done/CNV-98-document-worker-settings-application.md`,
  раздел Execution Log "Нужен ack team-lead" — там же зафиксирован сам
  gap и решение завести под него отдельную карточку).

**Execution Log:** *(add concise, secret-free evidence after work starts)*
- Authorization: explicit user approval at hand-off or recorded EPIC-scoped upfront autonomous authorization
- Agent/zone: <owner and zone>; Gate: `<command>` → <result>
- Reviewer: <verdict>; Commit: <SHA>
- Prompt evidence (optional): <sanitized artifact ID / session ID / digest / checksum>
- Never record full prompts, credentials, tokens, or other secrets.
