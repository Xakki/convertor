### Data-worker: применение CSV/JSON settings

**Criticality:** High

**TAGS:**
- feature
- data
- data-worker

**Description:**
Применить normalized CSV/JSON settings из CNV-103 в data-worker.

**Problem:**
Backend validation не сохраняет совместимость export, если serializer worker-а игнорирует delimiter, quote, UTF-8, pretty-print или indent.

**Impact:**
Пользователь получит файл, не соответствующий подтверждённой настройке, либо данные будут молча изменены.

**Recommendation:**
Для CSV применять разрешённые delimiter и quote из CNV-103, строго отклонять невалидный UTF-8 без replacement; для JSON применять pretty-print и ограниченный indent. Не добавлять options для YAML/TOML/XML и не принимать произвольные serializer options.

**Acceptance Criteria:**
- CSV output использует normalized delimiter/quote и UTF-8; невалидный UTF-8 завершается предсказуемой worker error без замены символов (без replacement-фолбэка).
- JSON output применяет pretty-print и normalized indent.
- Fixture и round-trip worker tests фиксируют сохранение структуры и типов для CSV/JSON.
- YAML/TOML/XML не читают и не получают settings.
- `pytest`, `make test` и `make build` зелёные.

**Decisions:**
- Зависит от CNV-85 и CNV-103.
- Frontend controls принадлежат CNV-105 после profile; общая frontend grammar принадлежит CNV-92.
- Data MVP ограничен CSV delimiter/quote/UTF-8 и JSON pretty-print/indent.
- YAML/TOML/XML вне scope.

---

## Execution Log (worker, 2026-08-24)

### Что изменено

`workers/data/worker.py`:
- `_write_data()` (было L157, теперь L157-206) получила параметр
  `options: dict[str, Any] | None = None`. JSON-ветка (L167-181): `pretty`
  (bool, default None→True) переключает `json.dumps(..., indent=N)` (pretty)
  vs `json.dumps(..., separators=(",", ":"))` (compact); `indent` (1-8)
  используется только когда `pretty` эффективно `True`, дефолт `2` (совпадает
  с прежним хардкодом → «нет options» даёт байт-в-байт прежний вывод).
  CSV-ветка (L193-208): `delimiter`→`sep`, `quote`→`quotechar` в
  `df.to_csv(...)`, дефолты `","`/`'"'` (прежнее поведение pandas). YAML/TOML/
  XML-ветки не читают `options` вообще — параметр физически недостижим для них.
- `DataWorker.convert()` (L267-273): читает `options = job.get("options") or
  {}` (тот же идиом, что `workers/image/worker.py`), `isinstance` guard →
  `ValueError("invalid data options")`; передаёт `options` в `_write_data()`.
- `workers/data/worker.py:100` (`pd.read_csv(src)` без `sep`) — НЕ ТРОГАЛ,
  вне scope (отдельная grooming-карточка на source-side CSV parsing).

`workers/tests/test_data_worker.py`: `_make_job()` получил опциональный
параметр `options` (default `[]`, как в реальных job); добавлены классы
`TestCsvSettings` (6 тестов), `TestJsonSettings` (5),
`TestNonProfiledFormatsIgnoreOptions` (1 параметризованный ×4 target), `Test
InvalidUtf8NoReplacement` (3) — 18 новых тестов, 98→116.

### Literal TAB/pipe → байты вывода

`delimiter`/`quote` приходят как литеральные однобайтовые Python-строки
(`"\t"`, `"|"`, `";"`, `"'"` — уже РЕАЛЬНЫЕ символы, не символьные токены типа
`"tab"`, контракт зафиксирован CNV-103) и передаются напрямую в
`pandas.DataFrame.to_csv(sep=..., quotechar=...)`, которые требуют
однобайтовую строку и используют её как есть — никакой трансляции/маппинга не
требуется. Тест `test_delimiter_literal_tab_survives_into_output` читает
файл обратно и сравнивает `header == "name\tage\tcity"` (реальный tab-байт в
Python-строке сравнения) — не имя токена.

### Invalid UTF-8 → предсказуемая PERMANENT ошибка, без replacement

Ничего специально не писал: и `pandas.read_csv(src)` (C-парсер), и
`Path.read_text(encoding="utf-8")` (JSON/YAML/TOML/XML-ветки `_read_data`) уже
по умолчанию строгие (`errors="strict"`) — на невалидном UTF-8-байте кидают
`UnicodeDecodeError`, а НЕ подставляют `U+FFFD`. Подтверждено эмпирически
(пробный тест на pandas C-parser: `UnicodeDecodeError: 'utf-8' codec can't
decode byte 0xff in position 16: invalid start byte`). Так как
`UnicodeDecodeError` — подкласс `ValueError`, `StreamConsumerBase.process_job()`
(`workers/common/stream_consumer.py:91`, `except ValueError` раньше
`except Exception`) автоматически классифицирует её как **permanent=True**
(не бесконечный retry) — без единой строчки специального кода. Пользователь
увидит ошибку вида `UnicodeDecodeError: 'utf-8' codec can't decode byte 0xff
in position N: invalid start byte`, помеченную permanent.

Побочная находка (НЕ в scope этой карточки, не чинил): XML-ветка `_read_data`
читает через `ET.parse(src)` (сырые байты, не `read_text`) — на невалидном
UTF-8 expat кидает `xml.etree.ElementTree.ParseError: not well-formed
(invalid token)`, НЕ `UnicodeDecodeError`. Ошибка тоже без replacement и тоже
детерминированная, но `ParseError` НЕ подкласс `ValueError` → classified как
transient (retry), не permanent. XML вне scope CNV-104 (профиля не получает),
трогать не стал — если нужна permanent-классификация и для XML, это отдельная
grooming-карточка.

### Новая runtime-зависимость

Нет. Использован только stdlib (`json`) и уже запиненный в
`docker/workers/requirements-data.txt` `pandas==3.0.3`.

### Гейт

- `make TEST=1 test-python`: **423 passed, 1 xfailed, 2 skipped, 0 failed**
  (data 98→116 [+18], ffmpeg 77, image 43+1xfailed, libreoffice 60, metrics 16,
  ai 111+2skipped — все 5 остальных таргетов не тронуты, baseline 405+18=423
  подтверждено; `grep -c "^FAILED"` = 0 по полному логу).
- `make TEST=1 test-php`: **949 passing, 5405 assertions, 12 deprecations** —
  идентично baseline (PHP не трогался).
- `make TEST=1 test-drift`: **28 passed** (AST-парсинг `CAPABILITIES`
  worker'а не задет правками).

### Can-fail proof (мутация реального `workers/data/worker.py` → красный по
верной причине → откат → снова зелёный; каждый прогон — `make TEST=1
test-python-data`, полный `git diff` после отката содержал 0 маркеров
`MUTATED`)

**(a) delimiter меняет байты вывода.** Мутация: `delimiter = ","` (хардкод).
Красные (3, верная причина — сепаратор не изменился):
`test_delimiter_semicolon_changes_output_bytes` → `AssertionError: assert
'name,age,city' == 'name;age;city'`; аналогично `..._tab_...` (`'name,age,city'
== 'name\tage\tcity'`) и `..._pipe_...`. Остальные 113 — зелёные. Откат → 116/116.

**(b) quote применяется.** Мутация: `quotechar = '"'` (хардкод). Красный (1,
верная причина): `test_quote_single_applied_to_field_needing_quoting` →
`assert "'Hello, World'" in 'name,note\nAlice,"Hello, World"\n'` (значение
квотировано двойной кавычкой вместо запрошенной одинарной). Откат → 116/116.

**(c) pretty/indent применяются.** Две отдельные мутации:
c1) `pretty_effective = True` (хардкод, игнорирует флаг) → красные (2, верная
причина): `test_pretty_false_is_single_line_compact` и
`test_indent_ignored_when_pretty_false` (оба ожидали компактный вывод, получили
многострочный pretty). c2) `indent = 2` (хардкод, игнорирует `indent_val`) →
красные (2, верная причина): `test_pretty_true_indent_4_reindents_output` и
`test_indent_alone_without_pretty_key_still_applies` (оба ожидали `"    {"`
4/6-пробельный отступ, получили `"  {"` 2-пробельный). Откат каждой → 116/116.

**(d) YAML/TOML/XML не затронуты.** Мутация: в начале `_write_data()` добавлен
guard `if opts and ext in ("yaml","yml","toml","xml"): write(json.dumps(opts));
return` (симулирует утечку options). Красные (ровно 4, верная причина — по
одной на каждый target из параметризации):
`test_target_output_ignores_options[yaml/yml/toml/xml]` — вывод с
`options` перестал совпадать байт-в-байт с выводом без `options`. Остальные
112 — зелёные. Откат → 116/116.

**(e) invalid UTF-8 — без replacement.** Мутация: `pd.read_csv(src,
encoding_errors="replace")` + `read_text(..., errors="replace")`. Красные (3,
верная причина — исключение перестало бросаться, чтение молча заменило байт):
`test_invalid_utf8_csv_source_raises_unicode_decode_error` →
`Failed: DID NOT RAISE UnicodeDecodeError`; аналогично `..._json_source_...`
и `test_invalid_utf8_via_convert_is_a_permanent_worker_error` →
`Failed: DID NOT RAISE ValueError`. Откат → 116/116.

### Явно БЕЗ can-fail evidence

- **`encoding: "utf-8"` (CSV-профиль, единственное легальное значение).**
  Воркер не читает это поле вообще (уже всегда пишет `encoding="utf-8"`,
  прежнее поведение) — как и `document.txt`/`markdown` в CNV-98/103, поле
  зафиксировано на единственном значении, совпадающем с текущим хардкодом:
  мутировать нечего в смысле «убрать эффект», можно только материально
  ИЗМЕНИТЬ хардкод на другой encoding, что не относится к применению *опции*.
- **Дефолты `delimiter=","`/`quote='"'` при отсутствии options** — доказаны
  ПОЗИТИВНЫМ тестом `test_no_options_csv_output_byte_identical_to_pre_cnv104`
  (байт-в-байт сравнение с прежним хардкод-вызовом `to_csv(...)` без
  `sep`/`quotechar`), не мутационно — ломать нечего, это уже покрыто can-fail
  (a)/(b) для случая КОГДА options заданы.
- **`indent` по умолчанию `2` при `pretty` отсутствующем/`True`** — аналогично
  доказано позитивным `test_no_options_json_output_byte_identical_to_pre_cnv104`
  (сравнение с `json.dumps(..., indent=2, default=str)`), can-fail покрыт
  мутацией (c2) для случая когда `indent` задан явно.

### Side findings

- **`workers/data/worker.py:135` (XML `_read_data` через `ET.parse()`)** —
  невалидный UTF-8 классифицируется как **transient**, не **permanent**
  (`ParseError` не подкласс `ValueError`), в отличие от CSV/JSON/YAML. XML вне
  scope этой карточки (профиля не получает) — не чинил, только зафиксировал
  находку здесь. Grooming-карточку НЕ заводил (не в моей зоне; `.claude/
  kanban/grooming/TODO.md` git-ignored и невидим команде) — нужно решение
  team-lead, заводить ли отдельную карточку.

### Нужно team-lead

- Ничего блокирующего. XML `ParseError`-vs-`ValueError` находка выше — на
  подтверждение, заводить ли отдельную карточку.
