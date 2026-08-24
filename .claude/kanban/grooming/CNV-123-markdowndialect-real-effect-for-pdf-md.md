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
Реализовать реальный эффект `markdownDialect` для `pdf→md`, сохранив
целостность извлечения текста из многоколоночных/табличных PDF:
- Не просто прогнать текущий `pdftotext -layout` вывод через pandoc
  markdown-reader "как есть" — `-layout` сохраняет визуальные отступы
  колонок пробелами, а markdown-reader интерпретирует ≥4 пробела как code
  block, то есть многоколоночные/табличные PDF превратятся в мусорный
  вывод. Нужна другая стратегия экстракции: например, `pdftotext` БЕЗ
  `-layout` (реже подходит для многоколоночных PDF, зато не создаёт ложных
  code-block отступов) + отдельная эвристика для таблиц, либо сторонний
  PDF→Markdown-инструмент/библиотека, либо два режима извлечения
  (verbatim-режим по умолчанию + markdown-режим только когда диалект
  реально запрошен).
- Завести новые real-fixture тесты на многоколоночные и табличные PDF (в
  дополнение к существующим `test_libreoffice_integration.py` фикстурам),
  чтобы регрессия по fidelity была видна сразу, а не как молчаливая
  деградация вывода.
- Обновить каталог (`app-symfony/config/catalog/conversion_settings.json`):
  либо снова слить `document.markdown` и `document.markdown.verbatim` в
  один профиль с `markdownDialect`, либо (если verbatim-режим остаётся
  дефолтным поведением без диалекта) сознательно оставить раздельные
  профили — решить по итогам реализации.

**Acceptance Criteria:**
- `markdownDialect` реально меняет вывод `pdf→md` (не no-op) хотя бы для
  одного нетривиального значения (напр. `markdown_strict` экранирует
  спецсимволы иначе, чем `gfm` — тот же наблюдаемый эффект, который CNV-98
  подтвердил живым пробегом для `txt→md`).
- Многоколоночные/табличные PDF не деградируют по сравнению с текущим
  `-layout` verbatim-выводом — подтверждено новыми real-fixture тестами.
- Unit + real-fixture тесты покрывают разные диалекты на `pdf→md`.
- Catalog (`conversion_settings.json`) актуализирован под выбранную
  стратегию (см. Recommendation), `version` поднята.
- Tests/QA green: `make TEST=1 test-python-libreoffice`, `make TEST=1 test-php`, `make phpstan`.

**Open questions:**
- Какая стратегия извлечения даёт приемлемый баланс fidelity/диалект:
  `pdftotext` без `-layout`, другой инструмент, или два параллельных режима
  (verbatim по умолчанию + markdown только по явному запросу диалекта)?
  Нужны фикстуры многоколоночных/табличных PDF, чтобы сравнить эмпирически.
- Слить профили обратно в один `document.markdown` или оставить раздельные
  `document.markdown` / `document.markdown.verbatim` — зависит от того,
  остаётся ли verbatim-режим отдельным поведением или полностью заменяется.

**Decisions:**
- CNV-98 (repair-раунд, 2026-08-24) выбрал НЕ чинить это в своём скоупе —
  вместо этого каталог перестал рекламировать `markdownDialect` для
  `pdf→md` (см. `.claude/kanban/progress/CNV-98-document-worker-settings-application.md`,
  раздел Execution Log "Нужен ack team-lead" — там же зафиксирован сам
  gap и решение завести под него отдельную карточку).

**Execution Log:** *(add concise, secret-free evidence after work starts)*
- Authorization: explicit user approval at hand-off or recorded EPIC-scoped upfront autonomous authorization
- Agent/zone: <owner and zone>; Gate: `<command>` → <result>
- Reviewer: <verdict>; Commit: <SHA>
- Prompt evidence (optional): <sanitized artifact ID / session ID / digest / checksum>
- Never record full prompts, credentials, tokens, or other secrets.
