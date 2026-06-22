### Тесты конвертации для воркеров (+ фикстуры в tests/example_files)

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
Логика конвертации в воркерах слабо покрыта тестами. Не покрыты **Ffmpeg** и **Ai** воркеры. Нет `conftest.py` и конфига pytest (маркеры, asyncio-режим). LibreOffice покрыт отдельным `unittest`-интеграционником (требует живой сервис). Фикстуры в `workers/tests/example_files/` неполные и частично «осиротевшие».

**Текущее покрытие (актуализировано при груминге):**
- `test_base_worker.py` — только инфраструктура (pop/ack очереди, retry callback'а), без реальных конвертаций.
- `test_data_worker.py` — csv/json/yaml/xml чтение/запись + round-trip + ошибки на неподдерживаемый формат; матрица неполная, мало edge-кейсов.
- `test_image_worker_stream.py` — **уже есть**: Pillow png↔jpg↔webp↔bmp↔pdf, формат имени выходного файла, ошибки (неподдерживаемый формат, нет входного файла).
- `test_stream_consumer.py` — **уже есть**: декод двойного JSON-конверта Symfony Messenger, типы ключей/значений (bytes/str).
- `workers/libreoffice/tests/test_main.py` — `unittest` HTTP-интеграция, нужен живой сервис / `HOST_SHARE`.
- **Ffmpeg, Ai** — тестов нет вообще.

**Проблема:**
Регрессии конвертации уезжают в прод молча; нельзя доверять ядру продукта (конвертация файлов). Каждый тест-файл сам мокает redis/requests/urllib3 — дублирование, которое просится в общий `conftest.py`.

**Пробелы в фикстурах (`tests/example_files/`):**
- Есть: `image.jpg`, несколько doc/docx/pdf, плюс **сирота** `29216306410573.dwg` (нет воркера). Фикстура `video.3gp` укорочена до 41KB и больше не сирота: поддержка 3gp + конвертация 3gp→mp4 вынесены в [[docs-workers-conversion-validation]] (integration).
- Пользователь добавил `story.mp3` (**5.9 MB — нужно укоротить до ≤50KB**).
- Не хватает: data (csv/json/xml/yaml), malformed/пустые файлы. Видео-фикстуры для unit-тестов не нужны (subprocess мокается).

**Влияние:**
Без покрытия Ffmpeg/Ai любая поломка кодеков/провайдеров/выбора формата проходит незамеченной.

**Решение (scope — только unit, реальные движки мокаем):**
Добавить конфиг pytest и слой unit-тестов без внешних бинарей.
- Завести `conftest.py` (общие фикстуры: моки redis/requests, временный share-dir) + `pytest.ini` (`asyncio_mode=auto`, маркеры `integration`/`slow` — на будущее, кейсы под ними сейчас не пишем).
- **Валидация/моки (все воркеры Image/Ffmpeg/Ai/Data):** неподдерживаемый вход/выход, нет входного файла, path-traversal (`safe_share_path`), пустой вход, «выход не создан». Для Ffmpeg/Ai — мокать `subprocess`/движки (whisper/TTS/SDK).
- **Лёгкие реальные конвертации (без бинарей):** Data — полная матрица csv/json/xml/yaml + round-trip + malformed JSON/XML/CSV. Image — уже покрыт, при необходимости дополнить.
- **Сирота `.dwg`:** оставить файл, покрыть `xfail`/`skip`-тестом как намеренно неподдерживаемый формат. (`.3gp` — больше не сирота, см. [[docs-workers-conversion-validation]].)
- Укоротить `story.mp3` до ≤50KB и закоммитить (для Ai-воркера достаточно факта существования файла — STT/движок мокается).

**Критерии приёмки:**
- `pytest workers/tests` зелёный и запускается **без внешних бинарей** (ffmpeg/soffice/whisper не требуются).
- Ffmpeg и Ai воркеры получают unit-покрытие (моки subprocess/SDK): happy-path + error-case.
- У каждого воркера есть happy-path + error-case; протестирован path-traversal через `safe_share_path`.
- Добавлены `conftest.py` + `pytest.ini`; настроены asyncio-режим и маркеры.
- `story.mp3` укорочен до ≤50KB и закоммичен; сирота `.dwg` покрыта `xfail`/`skip`.
- QA зелёный по project CLAUDE.md (`pytest` для воркеров).

**Decisions (зафиксировано при груминге 2026-06-20):**
- **Фикстуры audio:** использовать добавленный пользователем `story.mp3`, укоротить до ≤50KB, закоммитить. Video-фикстуры для unit не нужны (subprocess мокается). Data/malformed — мелкие, коммитим.
- **Граница unit/integration:** сейчас **только unit**. Тяжёлые движки (ffmpeg/soffice/whisper/TTS) мокаем. Реальные integration-тесты (tier 3) — отложены в отдельную будущую карточку; маркер `integration` заводим заранее, но кейсы под ним сейчас не пишем.
- **Сирота `.dwg`:** оставить, покрыть `xfail`/`skip` («неподдерживаемый формат» — намеренно). **`.3gp`:** не сирота — 3gp поддерживается ffmpeg; поддержка во воркере + integration-тест 3gp→mp4 вынесены в [[docs-workers-conversion-validation]] (2026-06-20).
- **Coverage gate:** не вводим. Опционально можно подключить `pytest-cov` для отчёта, но **без** `--fail-under`.
- **LibreOffice unittest-интеграционник:** в scope этой карточки не трогаем (он требует живой сервис → не unit). Рефактор/перенос на pytest — при необходимости отдельной задачей.

**Execution Log (2026-06-23):**
- Карточный раздел «текущее покрытие» был устаревшим: `test_ai_worker.py`/`test_ffmpeg_worker.py` уже существовали → реализация свелась к закрытию пробелов.
- Добавлен `workers/tests/conftest.py` (общие фикстуры `build_worker`/`example_files`/`mock_redis`) + `pytest.ini` (`asyncio_mode=auto`, маркеры `integration`/`slow`/`e2e`/`drift`).
- Ai-воркер: снят module-level `pytest.skip` (heavy-deps lazy-import внутри функций → импорт безопасен); добавлены empty-TTS error-case и STT через реальную фикстуру `story.mp3` (движок мокается).
- Data: `TestMalformedInputs` (битые/пустые json/xml/csv/yaml) + `TestFullMatrix` — round-trip по всей `SUPPORTED` с нормализацией типов (ловит потерю полей/строк/значений).
- Ffmpeg: error-case (ненулевой выход движка) + content-agnostic тест (0-байтовый вход проходит валидацию формата и доходит до `run_ffmpeg`).
- Path-traversal: `test_safe_path.py` (dotdot, абсолютный вне share, sibling-prefix, symlink-escape).
- Сирота `.dwg` — `xfail(strict, raises=ValueError)`; `story.mp3` укорочен до 12451 B (валидный MPEG layer III).
- Ревью (2 finder-агента, high): 3 замечания по качеству тестов исправлены (round-trip-фиделити, тавтологичный ffmpeg-тест, использование фикстуры).
- QA: `PYTHONPATH=. pytest workers/tests -m "not e2e and not integration"` → **191 passed, 10 deselected, 1 xfailed**, без внешних бинарей.
