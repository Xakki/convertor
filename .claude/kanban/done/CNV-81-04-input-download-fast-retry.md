### Быстрый retry скачивания входного файла

**Criticality:** Medium

**TAGS:**
- bug-fix
- workers
- reliability
- queue

**Description:**
Автономная реализация надёжного скачивания входа. Происхождение: CNV-65
«Воркер не ретраит скачивание входного файла — fail сразу, повтор только через
idle-reclaim (~2 мин)». Исходная карточка остаётся журналом инцидента; эта
подзадача фиксирует границы и порядок безопасной реализации.

**Problem:**
`_download_input` в `workers/common/ws_client.py` при транзиентной сетевой
ошибке сразу завершает job retryable-fail. Следующая попытка появляется только
после gateway idle-reclaim, поэтому краткий сбой растягивает обработку на
минуты. Нынешний неявный timeout httpx (5 с) не выражает ожидания для
скачивания. Повторное использование залипшего соединения не решает сценарий
с новым TLS-хендшейком, наблюдавшийся на uBook.

**Impact:**
Транзиентные сбои сети дают многоминутную задержку при минимальной полезной
работе и выглядят как затык очереди.

**Recommendation:**
Локально в `_download_input` добавить ограниченный retry транзиентных ошибок:
сетевых ошибок httpx, timeout и HTTP 5xx. Использовать короткий экспоненциальный
backoff с jitter по образцу `_register()`, но не выносить общий helper.
Установить явный `httpx.Timeout` с ориентиром connect около 10 с и read около
60 с. Каждая попытка создаёт новый `httpx.AsyncClient`/TLS-хендшейк. Ошибки
4xx, включая 401, не ретраить; после исчерпания попыток сохранить текущий
путь fail, чтобы idle-reclaim оставался страховкой. Пересмотреть каждое
`RECLAIM_IDLE_MS_*` и либо оставить с кратким обоснованием, либо изменить с
указанием причины.

**Acceptance Criteria:**
- `_download_input` выполняет ограниченное число попыток (ориентир: 3) с
  коротким экспоненциальным backoff и jitter для сетевых ошибок, timeout и
  HTTP 5xx; бесконечного retry нет.
- Каждая retry-попытка использует новый `httpx.AsyncClient`, а не повторно
  использует зависшее соединение.
- Для скачивания задан явный `httpx.Timeout`: connect около 10 с, read около
  60 с; прочие фазы timeout определены явно либо обоснованно оставлены.
- HTTP 4xx, в том числе 401, не ретраятся; после исчерпания retry исключение
  проходит тем же fail-путём, что и до изменения.
- Тесты покрывают успех со второй попытки после транзиентного сбоя, исчерпание
  попыток и отсутствие retry для 4xx; проверка доказывает создание нового
  клиента на повторной попытке.
- Для всех `RECLAIM_IDLE_MS_*` в `workers/gateway/config.py` зафиксировано
  решение: значение сохранено с краткой причиной или изменено с новой
  величиной и причиной.
- В `workers/common/` не появляется общий retry-helper: логика остаётся
  локальной в `_download_input`.
- Выполнены релевантные pytest, `make test` и `make build`; команды и
  результаты записаны в execution log.

**Decisions:**
- 2026-08-14: решение и обязательность нового соединения на retry наследованы
  из CNV-65 и диагностического факта CNV-66; простого увеличения timeout
  недостаточно.
- 2026-08-14: retry локален в `_download_input`; общий helper не создаётся.
  401 и прочие 4xx — нетранзиентны и остаются без retry.

**Execution order:**
- Выполнять четвёртой, после CNV-81-03, для единого линейного исполнения
  эпика; функционально не зависит от реализации диагностики очередей.
- Перед началом перечитать CNV-65; исходную карточку не изменять.

**Execution log (2026-08-14):**
- Перечитаны `AGENTS.md`, эта карточка, исходная CNV-65, `_register()` и
  `_download_input`, тесты WS-клиента и gateway config. Реализация локальна в
  `_download_input`: 3 попытки, exponential backoff + jitter, новые
  `AsyncClient`/соединение на каждую попытку, explicit timeout
  `connect=10`, `read=60`, `write=None`, `pool=None`; retry только
  `httpx.RequestError` и 5xx. 4xx (включая 401) поднимаются без retry, а
  исчерпание поднимает исходное исключение в прежний job fail-path.
- Добавлены tests `test_download_input_retries_5xx_with_new_client_then_succeeds`,
  `test_download_input_exhausts_network_retries_and_reraises` и
  `test_download_input_does_not_retry_4xx`: подтверждают успех со второй
  попытки, три попытки при exhaustion, отсутствие retry для 401 и разные
  экземпляры клиента. RED: до реализации 3 теста упали из-за отсутствующих
  retry-констант/явного клиента; GREEN: `PYTHONPATH=. pytest
  workers/tests/test_ws_client.py -v` → 77 passed, 1 skipped.
- Все `RECLAIM_IDLE_MS_*` сохранены: document/audio/AI 5 min, image 2 min,
  video 10 min, data 3 min. Fast retry работает до начала обработки, а эти
  пороги защищают от дублирования живых длительных работ; данных для снижения
  нет. Обоснование добавлено в `workers/gateway/config.py`.
- `make TEST=1 test-gateway` → 220 passed, 1 skipped. Локальный запуск
  `PYTHONPATH=. pytest workers/tests/test_gateway_reclaim_dlq.py -v` ожидаемо
  не смог разрешить compose-host `keydb`; тот же gateway target поднял
  изолированный KeyDB и прошёл.
- `make test` → success: PHPUnit 725 tests (12 baseline deprecations), worker
  suites and gateway 220 passed/1 skipped, drift 22 passed; `make build` →
  success. Известные skips: optional `pdf2image`; AI e2e без `espeak-ng` и
  `llama_cpp`/`LLM_MODEL_PATH`.

**Execution log (review-fix, 2026-08-14):**
- Blocking review commit `c6744e9` выявил две ошибки: retry ловил слишком широкий
  `httpx.RequestError` (включая protocol/decode/redirect ошибки), а download
  брал private `http_client._transport` и закрывал transport инжектированного
  клиента. Проверены все вызовы `WsClient(..., http_client=...)`: production
  путь не инжектирует клиента, а существующие тестовые вызовы остаются
  совместимыми без private API.
- Исправлено локально: retry теперь только `httpx.NetworkError` или
  `httpx.TimeoutException`, плюс прежний отдельный HTTP 5xx; 4xx, decoding,
  redirect и protocol ошибки не повторяются. Добавлена явная
  `download_client_factory`: production создаёт новый независимый AsyncClient
  на каждую попытку, а injected `http_client` не закрывается и остаётся usable.
- Добавлены regression tests для timeout retry, единственной попытки на
  `DecodingError` и сохранения работоспособности injected regular client после
  successful download; сохранены проверки 5xx/new-client/4xx.
- Проверки: `PYTHONPATH=. pytest workers/tests/test_ws_client.py -v` →
  80 passed, 1 skipped; `make TEST=1 test-gateway` → 223 passed, 1 skipped;
  `make test` → success (PHPUnit 725 tests, 12 baseline deprecations; worker
  suites including 223/1 gateway; drift 22 passed); `make build` → success.
  Дополнительный host-only cross-worker запуск остановился на baseline missing
  optional dependency `cairosvg`; dockerized official suites прошли.
- Независимое ревью объединённых коммитов `c6744e9` и `0b5b782` пройдено.
