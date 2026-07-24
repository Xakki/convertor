### Хрупкость реестра воркеров: ключ по worker_type + нет админ-вью воркеров

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
При разборе бага с AI-форматами обнаружены две системные проблемы в архитектуре реестра воркеров:

1. **Коллизии при множественных воркерах одного типа**: таблица `worker_capabilities` ключуется UNIQUE по `worker_type` (без `worker_id`), что приводит к last-writer-wins поведению — два воркера одного типа (напр. on-server CPU + удалённый GPU оба `worker_type='ai'`) делят одну строку и могут перетирать регистрацию друг друга в `app-symfony/src/Repository/WorkerCapabilityRepository.php::upsert` и `src/Entity/WorkerCapability.php`.

2. **Слепая зона наблюдаемости**: в админ-UI нет отдельной страницы со списком зарегистрированных воркеров с их capabilities, статус воркера видна лишь косвенно через consumers очереди KeyDB. Невозможно быстро увидеть, какие воркеры подключены, когда они последний раз были видны, и какие они заявляют capabilities.

3. **Тихий отсев форматов**: при неполных capabilities (напр. отсутствие `matrix_categories`) реестр молча отсеивает форматы без предупреждения или health-сигнала (частично адресуется в карточке `fix-worker-matrix-categories`, но полноценного вью остаётся лазейка).

**Problem:**
- Текущее проектирование неустойчиво к масштабированию: появление нескольких воркеров одного типа на разных хостах приведёт к конфликтам регистрации.
- Оператору некому доверять при диагностике: "Почему этот формат недоступен?" — ответ скрыт в логах и require'ует cross-filtering по контролёрам.
- Risk: скрытые отказы, трудная диагностика инцидентов.

**Impact:**
- Латентный баг при масштабировании воркеров (мультирегион, backup-воркеры, failover).
- Плохая наблюдаемость → сложная отладка production-инцидентов.
- Возможность потери capabilities при регистрации нескольких инстансов.

**Open questions:**
1. Нужно ли ключевать `worker_capabilities` по кортежу `(worker_id, worker_type)` вместо только `worker_type`, чтобы разные инстансы одного типа не конфликтовали?
2. Должен ли `worker_id` быть сгенерирован на стороне воркера (UUID или другой уникальный идентификатор per-instance) или на стороне gateway при регистрации?
3. Нужна ли отдельная админ-страница "Воркеры" (список зарегистрированных воркеров: worker_id, worker_type, last_seen, capabilities JSON, health status)?
4. Подходит ли Symfony Dashboard / стандартный EasyAdmin для этого, или нужна кастомная страница в `templates/admin/`?

**Recommendation (при готовности к реализации):**
- Провести refactoring `worker_capabilities` таблицы и Entity.
- Добавить migration для исторических данных.
- Реализовать админ-страницу с realtime-вью воркеров (pull от gateway через `/api/v1/admin/workers` эндпоинт).
- Перевести регистрацию воркеров на `worker_id`-based lookup с graceful handling старых записей.

**Reference:** `backend-architecture`, skill `worker-ai-image` (деплой воркеров)

---

**Decisions (grooming 2026-07-24): закрыта как устаревшая — перекрыта эпиком `registry`.**

Сверка с кодом показала, что все три пункта уже решены и карточка описывает состояние, которого больше нет:

1. **Коллизии по `worker_type`** — НЕ актуально. Таблица `worker_capabilities` ключуется UNIQUE по `(worker_type, instance_id)` (`app-symfony/src/Entity/WorkerCapability.php:29`, констрейнт `UNIQ_WORKER_CAPABILITIES_TYPE_INSTANCE`), `upsert()` делает `INSERT … ON DUPLICATE KEY UPDATE` по этому кортежу (`WorkerCapabilityRepository.php:56-83`). `instance_id` генерится воркером и стабилен между реконнектами — два инстанса одного типа НЕ конфликтуют. (Реализовано в эпике `registry`, `worker_id`-based lookup не понадобился — вместо него `instance_id`.)
2. **Нет админ-вью воркеров** — НЕ актуально. Есть `GET /api/v1/admin/workers` (`src/Controller/Admin/Api/WorkerController.php`), провайдер `src/Service/Admin/WorkerStatsProvider.php`, шаблон `templates/admin/workers.html.twig` (доставлено `[[registry-07-admin-workers-page]]`, done).
3. **Тихий отсев форматов** — адресовано `[[fix-worker-matrix-categories]]` (done): `_build_register_body()` форвардит `matrix_categories` (`workers/common/ws_client.py:747`).

Остаточный defense-in-depth (воркерский проактивный retry на initial register) вынесен в отдельную карточку `[[worker-register-no-retry]]`. Отдельного health-сигнала на тихий отсев форматов решено не заводить — админ-вью + observability из `[[registry-08-worker-observability]]` покрывают наблюдаемость.

**Status:** closed (obsolete / superseded).
