### markdownDialect real effect for pdf→md

**Criticality:** Minor

**TAGS:**
- tech-debt
- documents
- document-worker

**Description:**
CNV-97 назначает профиль `document.markdown` (поле `markdownDialect`) обеим
парам markdown-триангля — `pdf→md` и `txt→md` — одним и тем же правилом
`assignments`. Реализация CNV-98 показала, что у воркера (`workers/libreoffice/
worker.py`) это ДВА разных исполнения: `txt→md` реально прогоняет диалект
через pandoc writer, а `pdf→md` — нет. В рамках CNV-98 (repair-раунд,
исправление CHANGES-REQUIRED) каталог разделён на два профиля:
`document.markdown` (с `markdownDialect`, только `txt→md`) и
`document.markdown.verbatim` (без `markdownDialect`, только `pdf→md`) —
то есть опция для `pdf→md` больше НЕ рекламируется клиенту вместо того,
чтобы чинить путь конвертации. Эта карточка — про саму доработку: сделать
`markdownDialect` реально работающим и для `pdf→md`.

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
Реализовать два явно различимых режима `pdf→md`, не меняя молча текущий
результат:
- **verbatim** — режим по умолчанию, сохраняющий `pdftotext -layout` и его
  текущую семантику;
- **dialect-enabled** — только при явном выборе пользователем/API/profile
  дискриминатора, с реальным применением выбранного Markdown-диалекта.

Дискриминатор должен быть явным на границе пользовательского запроса, API или
профиля, но его точное имя и wire/schema-форма этой карточкой не выбираются.
Нельзя просто прогонять текущий `pdftotext -layout` через pandoc: пробелы
колонок могут стать ложными code block. Для dialect-enabled режима нужно
эмпирически выбрать pipeline (например, другой режим `pdftotext` с эвристикой
таблиц или PDF→Markdown-инструмент) по многоколоночным и табличным fixture.

В обоих режимах добавить real-fixture тесты и bounded benchmark; benchmark
должен измерить fidelity отдельно для verbatim и dialect-enabled и установить
приемлемые критерии до реализации. Каталог/профили обновлять только после
решения точной схемы дискриминатора; отсутствие явного выбора всегда означает
verbatim. Семантика `txt→md` (включая существующее применение
`markdownDialect` через pandoc) сохраняется без изменений.

**Acceptance Criteria:**
- Без явного user/API/profile выбора `pdf→md` остаётся verbatim и использует
  `pdftotext -layout`; существующий default не меняется silently.
- Явный dialect-enabled выбор проходит через согласованный дискриминатор и
  реально меняет результат хотя бы для одного нетривиального диалекта; его
  точные имя и wire/schema-контракт должны быть отдельно утверждены.
- `txt→md` сохраняет текущую семантику `markdownDialect` и не получает
  побочных изменений от разделения PDF-режимов.
- Real fixtures покрывают многоколоночный и табличный PDF в обоих режимах;
  bounded benchmark измеряет fidelity для каждого режима и фиксирует
  согласованные пороги/метрики до acceptance реализации.
- Выбранный extraction pipeline и поведение при неоднозначной/неподдержанной
  структуре подтверждены fixture/benchmark, а не предположены по unit-тесту.
- Catalog/profile changes отражают утверждённый discriminator и режимы; до
  этого catalog не рекламирует неподтверждённый wire/schema.
- После реализации профильные tests/QA green: `make TEST=1
  test-python-libreoffice`, `make TEST=1 test-php`, `make phpstan`.

**Open questions:**
- Какой extraction pipeline использовать для dialect-enabled режима: другой
  режим `pdftotext`, отдельные table heuristics или сторонний PDF→Markdown
  инструмент? Ответить по real fixtures и bounded benchmark.
- Как называется и где живёт явный user/API/profile discriminator (точное
  поле, wire/schema и обратная совместимость)? Выбран только принцип явного
  выбора; exact field/schema не утверждены.
- Как именно представить два режима в catalog/profiles: слить профили или
  оставить `document.markdown` / `document.markdown.verbatim` либо иную
  структуру? Решение зависит от discriminator/schema.
- Каковы fidelity-метрики и пороги отдельно для verbatim и dialect-enabled,
  включая допустимое расхождение таблиц и колонок? Порог не выбран до
  benchmark.

**Decisions:**
- 2026-09-02: утверждён продуктовый контракт двух явных `pdf→md` режимов:
  verbatim с `pdftotext -layout` по умолчанию и opt-in dialect-enabled режим.
  Отсутствие явного user/API/profile выбора означает verbatim; silent default
  behavior change запрещён.
- 2026-09-02: выбран принцип явного discriminator на границе user/API/profile,
  но exact field name, wire/schema и profile/catalog representation не выбраны.
  Эта запись не изобретает имя и оставляет соответствующие вопросы открытыми.
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
