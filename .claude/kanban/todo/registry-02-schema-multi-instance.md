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

**Status:** todo
