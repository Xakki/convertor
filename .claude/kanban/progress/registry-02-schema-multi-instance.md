### Схема capability: ключ (workerType, instanceId) + нативный upsert

**Criticality:** Medium

**TAGS:**
- tech-debt
- feature

**Description:**
Первый шаг Phase 2 эпика `[[registry-00-self-registration]]`. Сейчас `worker_capabilities`
ключуется UNIQUE только по `worker_type` (`app-symfony/src/Entity/WorkerCapability.php:31`,
миграция `Version20260708071901`) — два воркера одного типа (напр. on-server CPU AI +
удалённый GPU AI, оба `workerType='ai'`) делят одну строку и перетирают регистрацию друг
друга (`worker-registry-fragility`, D4: втянута сюда). `WorkerCapabilityRepository::upsert()`
(`app-symfony/src/Repository/WorkerCapabilityRepository.php:27-41`) — find-then-update на
уровне PHP, TOCTOU при гонке двух одновременных register одного ключа (carry-over Phase 1).
Заодно закрываются два других carry-over Phase 1: неверный источник `isAi` и неопределённая
семантика `streams` vs `routingKeys`.

**Problem:**
- Нет способа различить несколько живых инстансов одного `workerType` — регистрация
  инстанса B стирает капабилити инстанса A.
- `upsert()` не атомарен: конкурентный register того же ключа может упасть в UNIQUE-конфликт
  на `flush()` → 500.
- `isAi` в Python выводится как `self._cfg.worker_type == "ai"` (`workers/common/ws_client.py:551`),
  а не из `CAPABILITIES["isAi"]` — хрупко при появлении второго AI-типа.
- Python шлёт одно и то же значение в оба поля `streams`/`routingKeys`
  (`_build_register_body()`, `workers/common/ws_client.py:537-558`) — семантика не зафиксирована.

**Impact:**
Без ключа per-инстанс любое горизонтальное масштабирование воркера (несколько GPU-хостов,
failover-реплики) молча теряет капабилити всех, кроме последнего зарегистрировавшегося —
блокер для реального Phase 2/3 (multi-candidate router).

**Recommendation:**
- Миграция: добавить поле `instance_id` (string, required) в `worker_capabilities`; сменить
  UNIQUE-индекс с `worker_type` на составной `(worker_type, instance_id)`.
- `WorkerCapability` entity — добавить `instanceId`, обновить геттеры/конструктор.
- `WorkerCapabilityRepository::upsert()` — переписать на нативный
  `INSERT ... ON DUPLICATE KEY UPDATE` (Doctrine `Connection::executeStatement()` с raw SQL или
  `EntityManager`-native query) — снимает TOCTOU из Phase 1 review.
- `WorkerController::validateRegisterPayload()` (`app-symfony/src/Controller/Api/WorkerController.php:77-96`) —
  принять и провалидировать `instanceId` (required string).
- `workers/common/ws_client.py::_build_register_body()` — генерировать стабильный `instanceId`,
  переживающий реконнект того же процесса (варианты: hostname+worker_type, env-переменная,
  PID+hostname — выбор и обоснование зафиксировать в карточке при реализации).
- `_build_register_body()` — `isAi` брать из `CAPABILITIES.get("isAi", False)` вместо вывода
  из `worker_type == "ai"`.
- Зафиксировать семантику `streams` vs `routingKeys` — либо развести (имена stream-каналов
  `conv.<type>` vs routing-суффиксы), либо осознанно схлопнуть в одно поле; решение и
  обоснование записать в карточку по факту реализации.
- Явно учесть: ffmpeg регистрируется ДВАЖДЫ за процесс (`workers/ffmpeg/__main__.py::run_dual()`,
  L37-61, два `WsClient` — audio/video) — `instanceId` должен различать эти две регистрации
  (иначе они схлопнутся в один ключ несмотря на разные `worker_type`).

**Acceptance Criteria:**
- Миграция меняет UNIQUE на `(worker_type, instance_id)`, накатывается на непустую таблицу
  без потери существующих строк (Phase 1 данные получают детерминированный `instance_id`).
- Повторный register с тем же `(workerType, instanceId)` обновляет строку, не дублирует;
  два разных `instanceId` одного `workerType` сосуществуют как отдельные строки.
- `upsert()` — один SQL-запрос (`ON DUPLICATE KEY UPDATE`), без find-then-update; юнит-тест на
  конкурентный register (или явная проверка отсутствия TOCTOU-окна в коде).
- ffmpeg audio- и video-регистрация видны как две разные строки.
- `isAi` в теле register читается из `CAPABILITIES["isAi"]`.
- Решение по `streams`/`routingKeys` задокументировано в карточке.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest (`workers/tests`).

**Decisions:**
- Груминг 2026-07-22: карточка объединяет схемную часть `worker-registry-fragility` с тремя
  carry-over пунктами Phase 1-ревью (TOCTOU, `isAi`, `streams`/`routingKeys`) — схема меняется
  один раз, а не дважды (D4).

**Эпик:** `[[registry-00-self-registration]]`

**Status:** in progress

**Execution Log (2026-07-22):**

#### Python-зона

Файлы: `workers/common/ws_client.py`, `workers/ai/worker.py`, `workers/ffmpeg/worker.py`,
`workers/libreoffice/worker.py`, `workers/data/worker.py`, `workers/image/worker.py`,
`workers/tests/test_ws_client.py`.

- **`instanceId`-схема** (`WsClient._instance_id()`): приоритет
  1) `WORKER_INSTANCE_ID` env (`WsClientConfig.instance_id_override`) — явный пин оператором;
  2) иначе `<hostname>:<worker_id>`. Оба пути прогоняются через `_sanitize_instance_id()` —
  недопустимые символы → `-`, обрезка до 128, пустая строка → `"unknown"` (контракт
  `^[A-Za-z0-9._:-]+$`, ≤128, непустая — сервер 400-ит при нарушении, санитизация делается
  клиентом ДО отправки, не полагаемся на серверную валидацию).
  Обоснование по 4 требованиям:
  - **Стабильность между реконнектами** — `worker_id` и hostname не меняются в течение жизни
    процесса/контейнера, значит `_instance_id()` детерминирована на каждый вызов
    (`_register()` дёргается на каждый connect, L469) → один и тот же instanceId, не новая
    строка на флапе.
  - **Разные WsClient в одном процессе** — ffmpeg `run_dual()` создаёт audio/video клиентов с
    РАЗНЫМИ `worker_id` (`build_dual_configs`: `<base>-audio`/`<base>-video`,
    `workers/ffmpeg/__main__.py:24-34`) — раз `instanceId` выводится из `worker_id`, различие
    приходит автоматически, без спецкейса под ffmpeg. Проверено в коде: `worker_type` у них
    и так разный (`audio`/`video`), так что differentiation даже не строго обязателен для
    ключа `(workerType, instanceId)` — но instanceId различается тоже, что упрощает будущий
    multi-candidate router (Phase 3).
  - **Разные хосты** — добавлен явный `hostname` в схему (не полагаемся только на `worker_id`),
    потому что оператор может ЯВНО запиннить одинаковый `WORKER_ID` на нескольких хостах
    (напр. один и тот же docker-compose service name) — без hostname такие два хоста
    схлопнулись бы в один instanceId. (Без явного `WORKER_ID` фолбэк `_default_worker_id()`
    и так = hostname — но это не гарантия для явно заданного `WORKER_ID`.)
  - **Overridable через env** — `WORKER_INSTANCE_ID` (по аналогии с `WORKER_ID`/`WORKER_TYPE`),
    прокидывается в `WsClientConfig.from_env()`, санитизируется так же, как авто-значение.
  - Компромисс: если оператор явно пиннит ОДИН `WORKER_INSTANCE_ID` на audio- и video-клиента
    ffmpeg — оба получат одинаковый instanceId, но это НЕ коллизия по контрактному ключу
    `(workerType, instanceId)`, т.к. `workerType` у них разный; ответственность за это на
    операторе, который явно переопределил авто-генерацию.
- **`isAi`**: `_build_register_body()` теперь читает `caps.get("isAi", False)` вместо
  `self._cfg.worker_type == "ai"`. Все CAPABILITIES-словари получили ЯВНЫЙ ключ `isAi`
  (раньше отсутствовал ВЕЗДЕ, включая сам AI-воркер — не только в non-AI):
  `workers/ai/worker.py::CAPABILITIES` → `isAi: True`;
  `workers/ffmpeg/worker.py::AUDIO_CAPABILITIES`, `VIDEO_CAPABILITIES`, `FfmpegWorker.CAPABILITIES`;
  `workers/libreoffice/worker.py::LibreOfficeWorker.CAPABILITIES`;
  `workers/data/worker.py::DataWorker.CAPABILITIES`;
  `workers/image/worker.py::ImageWorker.CAPABILITIES` → все `isAi: False`.
- **Стале-комментарий** (`workers/common/ws_client.py:116-118`, старый номер строк) исправлен:
  раньше утверждал, что `ALLOWED_WORKER_TYPES` зеркалит `WorkerController::ALLOWED_TYPES` —
  эта PHP-константа удалена 2026-07-03 (`17b1ac8`). Список сейчас — самостоятельный whitelist
  без PHP-аналога; вывод его из реестра — отдельная grooming-карточка `worker-type-lists-hardcode`,
  здесь не трогали.
- **Тесты** (`workers/tests/test_ws_client.py`): `test_instance_id_present_and_well_formed`,
  `test_instance_id_stable_across_calls`, `test_instance_id_differs_between_ffmpeg_dual_clients`,
  `test_instance_id_env_override_honored`, `test_instance_id_env_override_sanitized`,
  `test_is_ai_true_only_for_ai_worker_capabilities`,
  `test_is_ai_false_for_non_ai_worker_capabilities` (параметризован по всем 6 non-AI
  CAPABILITIES-источникам — оба ffmpeg-словаря + класс, + libreoffice/data/image). Прогон —
  `make test-gateway` (гоняет `test_ws_client.py` на реальном KeyDB) + `make test-python`
  (юнит-сьюты по воркерам, включая ffmpeg/ai/libreoffice/data/image) + `make test-drift`
  (routing-контракт — 2 skipped, известный пробел, не regressed этой карточкой).
- **Рекомендация по `streams` vs `routingKeys`** (не реализовано здесь, только предложение
  для отдельной подкарточки): сегодня `_build_register_body()` шлёт ОДНО и то же значение
  (`routing_keys` из CAPABILITIES, напр. `["image"]`) в оба поля. Разница по факту не нужна:
  единственный потребитель этих полей на PHP-стороне сегодня — `buildMatrixFromCapabilities`,
  и оба поля концептуально отвечают на один вопрос «какой worker/stream обслуживает эту
  capability-строку» (soft-разделение "имя stream-канала" vs "routing-суффикс" не отражает
  РЕАЛЬНОЙ архитектуры — stream-имя строится PHP-стороной как `conv.<routing_key>`
  детерминированно из ОДНОГО и того же значения, второе поле не несёт независимой
  информации). Рекомендую СХЛОПНУТЬ в одно поле `routingKeys` (или `streams` — имя не
  принципиально) и убрать дублирующее поле контракта целиком, а не разводить его на два
  разных смысла ради разделения, которого сейчас нет ни в одном потребителе. Если позже
  появится воркер с несколькими routing-key НЕ равными его stream-именам 1:1 — тогда и
  разводить, с конкретным кейсом перед глазами, а не заранее.
- **Judgment calls**: (1) `instance_id_override` — новое поле `WsClientConfig`, НЕ трогает
  существующие поля/сигнатуры; `replace()` в `build_dual_configs()` не переопределяет его
  явно, значит если оператор задаст `WORKER_INSTANCE_ID`, оно унаследуется ОБОИМИ
  audio/video клиентами (см. компромисс выше) — осознанно, не баг. (2) Санитизация
  оператора: даже явный override прогоняется через `_sanitize_instance_id()` — оператор не
  обязан помнить charset контракта, а сервер получает всегда валидную форму.
