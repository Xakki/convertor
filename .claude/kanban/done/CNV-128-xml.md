### XML с битой кодировкой ретраится вечно вместо постоянного отказа

**Criticality:** High

**TAGS:**
- data
- bug-fix
- reliability

**Description:**
В data-воркере чтение XML (`workers/data/worker.py:135`, `_read_data` через
`ET.parse(src)`) при невалидном UTF-8 бросает
`xml.etree.ElementTree.ParseError`. Этот класс НЕ наследует `ValueError`,
поэтому `StreamConsumerBase.process_job()` относит его к временным ошибкам и
задача уходит в бесконечный ретрай.

**Problem:**
Файл испорчен навсегда — повтор не поможет никогда. Но воркер будет повторять
попытку снова и снова, занимая слот и сжигая ресурсы.

**Impact:**
Один битый XML способен занять воркер бесконечно. На малых хостах (saVpn — 1
ядро) это означает вытеснение полезной работы. Пользователь при этом не видит
внятного отказа — задача просто никогда не завершается.

**Evidence:**
Найдено при реализации CNV-104 (2026-08-24). Соседние форматы ведут себя
ПРАВИЛЬНО и служат образцом: `pandas.read_csv` и `Path.read_text(encoding="utf-8")`
декодируют строго и бросают `UnicodeDecodeError`, который является подклассом
`ValueError`, поэтому классифицируется как постоянная ошибка. Разница именно в
иерархии исключений, а не в логике классификации.
Тот же класс дефекта уже чинился в CNV-98: `ImportError` при отсутствии
`python-docx` перехватывается и перевыбрасывается как `ValueError`, чтобы старый
образ падал явно, а не ретраился вечно.

**Recommendation:**
Перехватить `ParseError` в месте чтения XML и перевыбросить как `ValueError` с
внятным сообщением, по образцу CNV-98. Правка тривиальная (cplx ~2), но требует
теста, доказанного на падение: убрать перехват — тест обязан покраснеть.
Не делалось внутри EPIC-004 намеренно: эпик схлопывается в один коммит про
настройки конвертации, и правка обработки ошибок XML исказила бы и историю, и
финальное ревью.

**Decisions:** *(разрешено team-lead 2026-08-25 при взятии в работу)*
- Открытый вопрос «есть ли ещё исключения вне иерархии `ValueError`» НЕ
  расширяет эту карточку. Чиним XML сейчас; исполнитель дополнительно делает
  ОГРАНИЧЕННЫЙ обзор воркеров на тот же класс дефекта и возвращает список
  кандидатов team-lead'у. Если кандидаты найдутся — на них заводится отдельная
  карточка, а не дописывается эта. Причина: сплошной аудит обработки ошибок —
  отдельная работа со своим объёмом, и смешивать её с точечным багфиксом
  значит потерять и то, и другое.

**Execution Log (2026-08-25):**
- Фикс `workers/data/worker.py:135` (`_read_data`, XML-ветка): `ET.parse(src)`
  обёрнут в `try/except (ET.ParseError, LookupError)`, перевыбрасывает
  `ValueError(f"malformed XML: {exc}")` — сохраняет исходную деталь (line/column
  у `ParseError`, имя кодировки у `LookupError`). `LookupError` найден
  эмпирически (`<?xml encoding="bogus-enc"?>` → `unknown encoding: bogus-enc`)
  тем же путём, что и `ParseError`, но другой класс исключения — обе ветки
  того же дефекта (постоянно битый вход, не наследует `ValueError`).
- Второй перехват на том же пути (по ревью advisor'а, до отчёта team-lead'у):
  `_elem_to_dict(root)` рекурсивен (1 фрейм/уровень вложенности) — well-formed,
  но аномально глубоко вложенный XML (2000 уровней) переполняет рекурсию:
  `RecursionError` — подкласс `RuntimeError`, тоже не `ValueError`. Подтверждено
  эмпирически (прямой вызов `_read_data()` на `"<a>"*2000 + "x" + "</a>"*2000`
  → `RecursionError`), а не оставлено как утверждение "не бросает" по чтению
  кода. Обёрнуто отдельным `try/except RecursionError` вокруг
  `_elem_to_dict(root)` → `ValueError("malformed XML: nesting too deep (...)")`.
- Тесты (`workers/tests/test_data_worker.py`, класс `TestMalformedInputs`):
  переименован/переписан `test_malformed_xml_raises` →
  `test_malformed_xml_raises_value_error` (ранее закреплял БАГОВОЕ поведение —
  `pytest.raises(ET.ParseError)`); добавлены
  `test_malformed_xml_encoding_raises_value_error` (LookupError-ветка),
  `test_deeply_nested_xml_raises_value_error` (RecursionError-ветка) и
  `test_malformed_xml_via_convert_propagates_value_error` (сквозь
  `DataWorker.convert()`). Can-fail proof (все 4 теста, по отдельности): с
  закрытым соответствующим перехватом падали на сыром исключении —
  `ET.ParseError: mismatched tag: line 1, column 22`,
  `LookupError: unknown encoding: bogus-enc`,
  `RecursionError: maximum recursion depth exceeded` — не на
  assertion-мисматче, т.е. красные по правильной причине; после возврата
  перехвата — зелёные.
- TASK B (обзор, без правок): найдено 2 кандидата того же класса дефекта —
  1) `workers/data/worker.py:108` — `yaml.safe_load()` в YAML-ветке
  `_read_data()`: `yaml.YAMLError` НЕ наследует `ValueError` (проверено
  `yaml.YAMLError.__mro__` → `Exception`), а существующий
  `test_malformed_yaml_raises` уже закрепляет это как ожидаемое (баговое)
  поведение — `pytest.raises(yaml.YAMLError)`;
  2) `workers/image/worker.py:135,263` — `Image.open(src)` (в `_do_convert()` и
  в OCR-пути) не обёрнут: `PIL.UnidentifiedImageError` наследует `OSError`, не
  `ValueError` (проверено `__mro__`), для битого/неопознаваемого файла
  изображения — постоянная ошибка. Метод поиска: grep по `workers/*/worker.py`
  и `workers/common/*.py` на `ET.parse|etree.parse|fromstring|yaml.safe_load|
  yaml.load|UnidentifiedImageError|BadZipFile|struct.error|tomllib.load|
  json.load(`, плюс точечная проверка MRO кандидатных типов исключений
  (`python3 -c`). `tomllib.TOMLDecodeError` — уже подкласс `ValueError`, не
  кандидат. ffmpeg/libreoffice воркеры не входили в обзор — там путь другой
  (subprocess-обёртки над `ffmpeg`/`soffice`/`pandoc`), тот же grep по
  parse-функциям ничего не нашёл, но это НЕ равно полному аудиту тех путей.
  Список передан team-lead'у, карточка не расширялась и не правилась.
- Гейты (финальный прогон после обоих перехватов): `make TEST=1 test-python`
  — 434 passed, 1 xfailed, 2 skipped (база 431/1/2 + 3 новых теста, регрессий
  нет). `make TEST=1 test-drift` — 28 passed (тест-стенд поднимался только на
  время прогона: `make TEST=1 test-up` → `test-drift` → `make TEST=1
  test-down`; таргет требует поднятый `xakki-convertor-test-php` — предпосылка
  для team-lead при повторной проверке). PHP не затронут. Дрифт
  `backend-architecture` (контракт `StreamConsumerBase.process_job()`)
  сверен с `workers/common/stream_consumer.py:91-109` — расхождений нет,
  ValueError → permanent=True, FileNotFoundError/прочее → permanent=False.
