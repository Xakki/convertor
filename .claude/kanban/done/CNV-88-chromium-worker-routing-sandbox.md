### Маршрутизация browser-задач и входной контракт backend

**Criticality:** High

**TAGS:**
- feature
- backend
- browser
- queue

**Description:**
Backend-специалист вводит отдельный вид исполнения `browser` и маршрутизацию
browser-задач в stream `conv.browser`. Карта возможностей, gateway и метрики
должны создавать и принимать browser-задачу независимо от выходной категории файла.

**Problem:**
Текущая маршрутизация выбирает worker по категориям `image` и `video`. Скриншот и
запись сохраняют эти категории для квот и хранения, поэтому без отдельного признака
исполнения browser-задача недостижима либо ошибочно попадает в существующий worker.

**Impact:**
Смешение browser-задач с image/video worker-ами делает очередь непредсказуемой,
нарушает изоляцию исполнения и исключает наблюдаемую маршрутизацию browser-нагрузки.

**Recommendation:**
Добавить `WorkerType::Browser`, `executionKind=browser`, stream `conv.browser` и
согласованные записи в каталоге возможностей, gateway, метриках и проверках дрейфа.
Сохранить `image` для screenshot и `video` для recording только как категории
результата. Не изменять контейнер, сетевую политику, Chromium runtime и frontend.

**Acceptance Criteria:**
- Backend создаёт browser-задачи с `executionKind=browser` и направляет их только в
  `conv.browser`; обычные image/video-задачи сохраняют существующие streams.
- Catalog, enum, gateway и метрики содержат одинаковый browser contract; тесты дрейфа
  выявляют расхождение между ними.
- Backend-тесты покрывают допустимую browser-маршрутизацию и отказ при неизвестном
  execution kind; целевые проверки backend проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: backend-специалист; граница работы — только routing/catalog/input contract.
- `executionKind=browser`, а не `FileCategory`, является единственным признаком
  маршрутизации; category остаётся источником quota/retention.
- CNV-113 зависит от завершения CNV-88 и использует созданный browser route; CNV-90 и
  CNV-91 зависят от CNV-88 как от общего backend prerequisite.

**Execution Log:**
- Ветка `epic/EPIC-004`. Зона — только `app-symfony/`; `workers/` не трогали.
- `App\Enum\WorkerType::Browser = 'browser'` (новый case, конец списка) +
  transport `conv_browser` (stream `conv.browser`, group `convertor`) в
  `config/packages/messenger.yaml` — зеркалит существующие 6 transports 1:1.
- `ConversionRegistry`: каждый ряд каталога (`conversion_pairs.json`) МОЖЕТ
  нести необязательное `executionKind` (валидное значение `WorkerType`) —
  ЕДИНСТВЕННЫЙ override маршрутизации в `streamFor()` (после OCR/AI-веток, до
  category-фолбэка); `category` не трогается (остаётся источником
  quota/retention). Отсутствующий/`null` — no-op (100% сегодняшних 402 рядов).
  Невалидное/пустое значение → громкая `\RuntimeException` при загрузке
  каталога, той же политикой, что и неизвестная `category`.
  `reduceCapabilities()`/`getSupportedFormatsFromBlobs()` (генератор
  `conversion_pairs.json` из `worker_capabilities.json`) НЕ трогал — ни один
  worker-blob сегодня не объявляет browser, поэтому механизм существует
  только на уровне схемы, не данных; регенерация каталога не нужна
  (см. gate ниже — байт-в-байт).
- **Before/after routing (proof, не сборка руками):** `bin/dump-matrix.php
  --json` до/после изменения — `diff` ПУСТОЙ (402 пары, все 6 categories:
  document/image/audio/video/data/ai — маршрут каждой пары не сдвинулся ни на
  один стрим). `routingKeys` до и после: `[video, audio, image, document,
  data, ai]` — `browser` в этом списке НЕТ (ни одна реальная пара его не
  объявляет).
- Тесты: `ConversionRegistryCatalogLoadingTest` (+5: executionKind
  override/absent/explicit-null/unknown-throws/empty-throws, синтетический
  tiny-каталог), `ConversionRegistryRoutingTest` (+1: `jpg→png` на РЕАЛЬНОМ
  коммиченном каталоге всё ещё `image`, не `browser` — регрессионный guard),
  новый `MessengerWorkerTypeTransportDriftTest` (+2, in-zone PHP-зеркало
  Python drift-guard: `WorkerType::cases()` ↔ `conv_<type>` transports 1:1) +
  обновлён 1 hardcoded error-string в `WorkerRegisterControllerTest`
  (`allowed: ..., ai` → `..., ai, browser`).
- **Python-контракт (вне зоны, handoff тимлиду):** `make TEST=1 test-drift`
  переехал 28→26 passed / 2 failed — ОБА failure это ровно ожидаемый мираж-
  дрейф, не routing-регрессия: `test_ws_client_allowed_worker_types_match_canon`
  и `test_keydb_worker_types_match_canon` (в `workers/tests/test_worker_type_drift.py`)
  сравнивают PHP-канон `WorkerType` с двумя Python-whitelist'ами и требуют
  добавить `"browser"` в `ALLOWED_WORKER_TYPES` (`workers/common/ws_client.py`)
  и в `WORKER_TYPES` (`workers/gateway/keydb.py`) — больше НИЧЕГО (ни логики,
  ни сборки, ни Chromium). `test_messenger_transports_match_canon` (тот же
  файл), оба assertion `test_routing_drift.py` и `test_catalog_drift.py`
  остались green — проверено (см. gate ниже), т.к. `routingKeys` в
  `dump-matrix.php` строится из фактических пар каталога, а не из
  `WorkerType::cases()`.
- **Consequence-проверка (карточка):** ни один user-facing путь сегодня не
  может породить `conv.browser` job. Несущий слой защиты: генератор каталога
  (`getSupportedFormatsFromBlobs()`/`reduceCapabilities()`, НЕ трогал) никогда
  не эмитит `executionKind` — ни один worker-blob его не объявляет, поэтому НИ
  ОДНА реальная пара `conversion_pairs.json` не резолвится в `browser`; это
  единственный структурный барьер. Второй слой — `WorkerUnavailableException`
  (503) на отсутствующей строке `worker_capabilities` для `workerType` —
  СЕГОДНЯ тоже верен (ни один воркер `browser` не зарегистрирован), но он
  контингентный, не структурный: `WorkerController::register()` валидирует
  `workerType` через `WorkerType::tryFrom()`, так что после этого изменения
  держатель статического worker-токена МОЖЕТ зарегистрироваться как `browser`
  и снять этот гейт (это ожидаемо и понадобится CNV-82/90/91/113) — так что
  реальная защита от `conv.browser` job сегодня держится ИСКЛЮЧИТЕЛЬНО на
  первом слое (каталог не эмитит executionKind).
- Gate: `make phpstan` 0/0 · `make cs` 0 fixes · `make cs-check` 0 · `make
  TEST=1 test-php` 983/5796 (было 975/5755, +8 тестов) · `make TEST=1
  test-drift` 26 passed / 2 failed (см. выше, оба — ожидаемый Python-handoff)
  · `make TEST=1 test-python` 431 passed/1 xfailed/2 skipped (не изменилось)
  · `make TEST=1 test-gateway` 223 passed/1 skipped (не изменилось).
- Can-fail drills (a/b/c из задания) — каждый сломан точечно, увидел RED по
  верной причине, восстановлен, зелёный подтверждён:
  (a) отключил override (`if (false && $executionKind !== null)`) →
  `testExecutionKindOverridesCategoryBasedRouting` упал: `Expected 'browser'
  +++ Actual 'image'`.
  (b) отключил валидацию (`if (false && WorkerType::tryFrom(...) === null)`)
  → `testUnknownExecutionKindValueThrows` упал: `Failed asserting that
  exception of type "RuntimeException" is thrown`.
  (c) подменил дефолт на `?? 'browser'` (симуляция регрессии) →
  `testExistingImagePairStillRoutesToImageNotBrowser` (реальный каталог,
  `jpg→png`) упал: `Expected 'image' +++ Actual 'browser'`.
  Доп. drill на новый `MessengerWorkerTypeTransportDriftTest` (не входил в
  a/b/c задания, но это тоже свежий тест без red-проверки): (d) `stream:
  conv.ai`→`conv.aiX` в messenger.yaml → `testEveryConvTransportStreamMatchesItsWorkerType`
  упал `Expected 'conv.ai' +++ Actual 'conv.aiX'`; (e) `conv_browser:`→
  `conv_browserX:` → `testEveryWorkerTypeHasExactlyOneConvTransport` упал —
  array diff показал `conv_browser`→`conv_browserX`. Оба восстановлены,
  зелёные подтверждены, `messenger.yaml` сверен байт-в-байт с закоммиченной
  версией после восстановления.
- Без can-fail evidence: нет для кода. Для документации — да, но это
  ожидаемо (доки не тестируются): drift-фикс `docs/queue-contract.md`,
  `docs/queue-streams.md`, `.claude/skills/backend-architecture/SKILL.md`
  (были "6 транспортов"/список без browser — обнаружено при ревью, поправлено
  в этом же изменении).
- **CNV-106 handoff — ДВА prerequisite'а, не один** (проверено эмпирически,
  не просто прочитано): временно добавил `executionKind: 'browser'` в реальный
  закоммиченный ряд `conversion_pairs.json` и прогнал
  `ConversionPairsCatalogDriftTest` → упал (`assertSame` увидел лишний ключ
  `executionKind` — реструктурировал/восстановил файл, зелёный подтверждён).
  Значит: (1) Python-слайс (2 строки, см. выше); (2) CNV-106/82 НЕ может
  просто дописать `executionKind` в закоммиченный `conversion_pairs.json` —
  это НЕ generated-in-place поле, файл ЦЕЛИКОМ выводится из
  `getSupportedFormatsFromBlobs()`/`reduceCapabilities()` (которые я
  сознательно не трогал), и та функция это поле не эмитит и не примет
  `workerType='browser'` без падения (`categoryForStream('browser')` →
  `FileCategory::from('browser')` → `ValueError` → пара молча дропается с
  warning). Значит владелец browser capability-блоба (CNV-82/90/91/113)
  должен САМ расширить `reduceCapabilities()`, чтобы (a) эмитить
  `executionKind` per-pair и (b) резолвить category для `workerType='browser'`
  НЕ через `categoryForStream()` (веб-паттерн AI: переиспользовать
  `matrix_categories` per-from, а не полагаться на 1:1 workerType↔category).
  Я сознательно НЕ трогал `reduceCapabilities()` — ни одного реального
  caller'а сегодня, риск нетестируемой генерализации перевешивает пользу.
- Требует подтверждения тимлида: (1) диспетчеризация Python-слайса (2 строки,
  см. выше) агенту worker-python — вне моей зоны, руками не трогал; (2) факт,
  что `reduceCapabilities()` расширение — отдельный prerequisite для
  CNV-82/90/91/113, не покрытый этой карточкой.

**Execution Log (worker-python, Python-зеркало canon):**
- Ветка `epic/EPIC-004`. Зона — только `workers/common/ws_client.py` и
  `workers/gateway/keydb.py`; больше ничего не трогал.
- Правка (1:1, по стилю окружения — trailing tuple без сортировки):
  `ALLOWED_WORKER_TYPES` в `workers/common/ws_client.py:279` и `WORKER_TYPES`
  в `workers/gateway/keydb.py:43` — оба `(..., "data")` → `(..., "data",
  "browser")`.
- До правки `make TEST=1 test-drift` = 26 passed / 2 failed, ровно:
  `test_ws_client_allowed_worker_types_match_canon` — `AssertionError:
  ...missing here (in canon, absent from ws_client.py): ['browser']`;
  `test_keydb_worker_types_match_canon` — тот же diff для `keydb.py`. После
  правки — **28 passed** (ровно +2, оба таргета теперь green, остальные 26 не
  изменили результат).
- `make TEST=1 test-python`: 431 passed, 1 xfailed, 2 skipped — без
  изменений (баланс сошёлся по всем 6 суб-таргетам: 116+77+51+60+16+111=431).
- `make TEST=1 test-php`: 983 tests / 5796 assertions — без изменений,
  подтверждает, что PHP-канон и Python-зеркала теперь согласованы.
- **Заявление backend-имплементера «это ВЕСЬ Python-scope» — ОПРОВЕРГНУТО.**
  `make TEST=1 test-gateway` до правки = 223 passed/1 skipped (баланс), после
  = **222 passed + 1 FAILED + 1 skipped** (тот же total=224 — это флип
  PASS→FAIL одного теста, не новый/удалённый тест).
  Упавший: `workers/tests/test_ws_transport_integration.py
  ::test_all_six_types_route_to_own_stream` — `for t in WORKER_TYPES:
  ...CONV_ID[t]` → `KeyError: 'browser'`, т.к. фикстура `CONV_ID` (строки
  67-70 того же файла) — хардкод-словарь на 6 старых типов, не выведен из
  `WORKER_TYPES`. Причинность механическая (сам traceback), доп. mutation-
  тест не потребовался. Файл лежит в `workers/tests/` — формально в моей
  зоне, но НЕ трогал: чинить фикстуру — значит решать, нужно ли
  `test_all_six_types_route_to_own_stream` сеять реальный `conv.browser` при
  отсутствии browser-воркера — это дизайн-вопрос CNV-82/CNV-113, не
  двухстрочная правка из этой карточки. Останавливаюсь и репортю, как
  требовало задание при опровержении scope-заявления.
- **Без can-fail evidence (задание требует явно назвать):** рантайм-
  последствие правки `keydb.py` — `WORKER_TYPES` фидит НЕ только тесты, но и
  живой gateway: `ws_server.py` (per-type `asyncio.Queue`, whitelist
  входящего `worker_type`), `reclaim.py`/`expiry.py` (XAUTOCLAIM/expiry-sweep
  по каждому типу), `__main__.py:94` (`streams=[f"conv.{t}" for t in
  WORKER_TYPES]` — список для `XREADGROUP`/consumer-group на старте). После
  этой правки живой gateway при следующем деплое начнёт создавать consumer-
  group и поллить `conv.browser` — пустой стрим, в который сегодня никто не
  пишет и никто не читает. Ожидаемо безвредно (idle-poll), но это поведение
  без can-fail проверки — тестов на этот путь нет.
- Дрейф skill `backend-architecture` — не найден: секция «Карта классов»
  уже упоминает `conv_browser` (CNV-88, пока без консьюмера) и
  `executionKind` как schema-only override, согласовано с `messenger.yaml` и
  `WorkerType.php` на момент проверки.
- Follow-up на grooming (не заводил карточку сам — решение тимлида):
  `CONV_ID` в `test_ws_transport_integration.py` не выведен из
  `WORKER_TYPES`, разошёлся при добавлении `browser`; нужно решить в рамках
  CNV-82/CNV-113 (когда появится реальный browser-воркер) — тест
  `test_all_six_types_route_to_own_stream` либо получит `CONV_ID['browser']`
  и per-worker seed, либо явно исключит `browser` из перебора с комментарием
  почему.

**Execution Log (worker-python, закрытие red gate + Task B — consequence-проверка):**
- Ветка `epic/EPIC-004`. Зона — только `workers/tests/`; сам runtime-код
  (`keydb.py`/`reclaim.py`/`expiry.py`/`ws_server.py`/`__main__.py`) не
  трогал (кроме can-fail drill ниже, откачен обратно, `git diff` пуст).

**Task A — red gate.** `test_all_six_types_route_to_own_stream`
  (`test_ws_transport_integration.py`) переименован в
  `test_all_consumed_types_route_to_own_stream`; введена
  `CONSUMED_WORKER_TYPES = tuple(t for t in WORKER_TYPES if t != "browser")`
  с явным комментарием-условием возврата (CNV-82/CNV-113 — когда появится
  подключаемый Chromium-воркер) + module-level
  `assert set(CONSUMED_WORKER_TYPES) == set(CONV_ID)` (ловит будущий разъезд
  громко на import, а не `KeyError` в середине теста). Тело теста и topline
  docstring `[1]` обновлены под "подключаемые типы", не хардкод "6". Другие 5
  критериев (`[2]`-`[4]`, ffmpeg dual-conn/backstop-reclaim/duplicate-delivery)
  НЕ трогал — они уже маршрутизацию остальных 6 типов не ослабляют.

**Task B — consequence-проверка (не предположение, проверено).**
  Опровергнута часть тезиса предыдущего агента: `__main__.py:94` — это
  ТОЛЬКО строка лога (`extra={"streams": [...]}), не вызов `ensure_group`;
  никакого eager consumer-group-creation при старте gateway НЕТ. Диспетч —
  кредитный, per-connection (`ws_server.py` docstring: «одно соединение =
  один workerType = один stream»): `read_new`/`reclaim_stale` идут ТОЛЬКО по
  стриму РЕАЛЬНО подключённого воркера — пока browser-воркер не подключится,
  никто не читает `conv.browser` вне sweep-циклов. Третье следствие правки
  (не harmless per se, а расширение поверхности): `ws_server.py:351`
  `if worker_type not in WORKER_TYPES` — держатель статического
  `WORKER_API_TOKEN` теперь МОЖЕТ зарегистрироваться как `workerType=browser`
  по WS и получит handoff-очередь (`ws_server.py:186`, уже создана для всех
  `WORKER_TYPES`) — это ожидаемо, тот же контингентный гейт, что PHP-сторона
  уже описала (WorkerController::register()), не новый риск.
  Реально ТРОГАЮТ `conv.browser` безусловно только `reclaim._sweep_all_types`
  и `expiry.sweep_all_types` — оба итерируют ПО ВСЕМ `WORKER_TYPES` каждый
  тик. Добавлен новый real-KeyDB тест
  `test_reclaim_and_expiry_scan_harmless_on_never_created_stream`
  (`test_gateway_keydb.py`) на стриме, который НИ РАЗУ не существовал (не
  просто пуст): `ensure_group`(MKSTREAM)+`XAUTOCLAIM` (reclaim-путь) и
  `ensure_group`+`XINFO GROUPS`+`XRANGE` (expiry-путь) — оба не падают, не
  логируют WARNING/ERROR (`caplog`, logger `workers.gateway.keydb`),
  `conv.browser`-аналог остаётся `[]`/`"0-0"`. **Can-fail drill**: временно
  `mkstream=True`→`False` в `ensure_group` (`keydb.py`) → RED по верной
  причине (`ResponseError: ... requires the key to exist... MKSTREAM`) — упал
  не только новый тест, но и ДВА уже существующих real-KeyDB теста
  (`test_backstop_reclaim_redispatches_to_second_worker`,
  `test_duplicate_delivery_same_job_deterministic_anchor`), которые уже
  сегодня безусловно проходят через `conv.browser` внутри `_sweep_all_types`
  (после `_wipe()` этот стрим реально не существует) — т.е. существующая
  зелёная сюита уже была живым доказательством, drill это подтвердил.
  Откат `mkstream=True`, `git diff workers/gateway/keydb.py` — пусто,
  подтверждено.
  **Вердикт: harmless.** Единственный реальный побочный эффект — MKSTREAM
  реально СОЗДАЁТ пустой `conv.browser` + группу `convertor` в KeyDB на
  первом sweep-тике (это НЕ no-op: новый persistent-ключ), но без ошибок и
  без per-cycle логов (обе sweep-функции делают `if not entries: return/
  continue` до какого-либо WARNING-пути).
- Побочный drift-фикс (нашёл при Task B, тот же класс, что и Task A —
  «WORKER_TYPES has 6 entries» стал 7 после 69bbc7b): комментарий +
  assert-порог в `test_run_expiry_loop_ticks_and_stops`
  (`test_gateway_expiry.py`) 6→7 (comment-only/count-only, логика теста не
  менялась — `>=`/`<` ждущий цикл, раньше просто ждал МЕНЬШЕ, чем реально
  происходит за один sweep).
- Gate: `make TEST=1 test-gateway` 224 passed/1 skipped (было 222+1
  FAILED+1 skipped → починили 1 + добавили 1 новый тест: 223+1=224, ровно
  предсказано до прогона); `make TEST=1 test-drift` 28 passed (не
  изменилось); `make TEST=1 test-python` 431 passed/1 xfailed/2 skipped
  (116+77+51+60+16+111 — не изменилось, `test-gateway` НЕ входит в
  `test-python` per Makefile, проверено чтением, не предположением);
  `make TEST=1 test-php` 983/5796 (не изменилось).
- Без can-fail evidence: нет для кода — единственный поведенческий пункт без
  теста ДО этой карточки (появление ключа `conv.browser` в KeyDB на первом
  sweep) теперь покрыт тестом выше.
- Требует подтверждения тимлида: ничего нового сверх уже открытых пунктов
  выше (Python-слайс/`reduceCapabilities()` — не в моей зоне этой карточки).
