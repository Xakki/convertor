### Worker observability: реальные метрики, host-поле, gateway-снапшот как источник правды

**Criticality:** Medium

**TAGS:**
- feature
- infra
- admin

**Description:**
Follow-up к эпику `[[registry-00-self-registration]]` (registry-00…07, все в `done/`). Три
независимых, но связанных доработки наблюдаемости воркеров, реализованные одним проходом:
(1) параметры воркеров в админке, (2) реальные CPU/MEM метрики + явный host, (3) gateway как
источник правды о живых соединениях вместо кэша регистрации в БД.

**Problem:**
- Админка `/admin#workers` (`[[registry-07-admin-workers-page]]`) отдавала `isAi`, `streams`,
  `routingKeys`, `matrix_categories` из провайдера, но `WorkerStatsProvider::toRow()` их
  игнорировал; PHP-эндпоинт liveness (`[[registry-06-liveness-push]]`) выбрасывал присланные
  gateway `{cpu, mem, load}` — колонки для них не было.
- `workers/common/ws_client.py::_load_snapshot()` хардкодил `cpu=0/mem=0` — метрики были
  фиктивными с самого начала эпика.
- Не было явного поля host — только `instanceId`, из которого хост не всегда очевиден.
- `worker_capabilities` — кэш регистрации, а не правда о соединениях: 2026-07-23 локальные
  воркеры реально обработали 27 задач, при этом в таблице числились отсутствующими (registry-06
  оставил задокументированный KNOWN GAP: `unknown` не самовосстанавливается).

**Impact:**
Оператор не видел реальную нагрузку/хост воркера в админке; таблица `worker_capabilities` могла
расходиться с реальными подключениями gateway без механизма самовосстановления, что маскирует
живые воркеры как отсутствующие в диагностике.

**Реализовано:**

*Часть 1 — параметры воркеров в админке:*
- Новая nullable JSON-колонка `metrics` на `worker_capabilities` (миграция
  `Version20260723090000`); `WorkerCapabilityRepository::updateLiveness()` теперь сохраняет
  `{cpu, mem, load}`, которые gateway и раньше слал, но PHP выбрасывал.
- `WorkerStatsProvider::toRow()` отдаёт `isAi`, `streams`, `routingKeys`, `matrix_categories`
  (раньше игнорировались) + `metrics` + `host`.
- `templates/admin/workers.html.twig` — колонки Host, AI-бейдж, streams/routingKeys/
  matrix_categories, CPU/MEM/Load (прочерк при null).

*Часть 2 — реальные метрики и явный хост:*
- `workers/common/ws_client.py::_load_snapshot()` — реальный замер без внешних зависимостей:
  cgroup v2 (`cpu.stat` usage_usec delta, `memory.current`/`memory.max`) с фолбэком на
  `/proc/stat` и `/proc/meminfo`. cpu — delta-based, поэтому легитимно `None` на первом ping
  соединения.
- Явное поле `host`: env `WORKER_HOST` (алиас `NODE_NAME`, фолбэк — hostname контейнера), тот
  же источник питает host-часть `instanceId`. Колонка `worker_capabilities.host` (миграция
  `Version20260723110000`), проброшено через register-эндпоинт, провайдер и админку. Прописано
  в `docker-compose.yml` (все 6 воркеров, включая `worker-ai` — с 2026-07-23 обычный сервис
  там же, не отдельный overlay-файл; переиспользован существующий `HOST_NAME`) и в
  `docs/workers-remote-deploy.md`.

*Часть 3 — gateway как источник правды о подключениях:*
- Liveness-пуш gateway теперь несёт ПОЛНЫЙ снапшот живых инстансов (`snapshot:true` +
  `authoritative:true`), новый `App\Service\Worker\WorkerLivenessReconciler` сверяет с ним
  таблицу.
- Инвариант: строка → `disconnected` только если снапшот авторитетный И пары
  `(workerType,instanceId)` в нём нет И `lastSeen` старше `WORKER_LIVENESS_SILENCE_SECONDS`
  (120с) И это не seed И статус сейчас `alive`. Условие по `lastSeen` защищает от массового
  offline при перезапуске gateway, пустом снапшоте на прогреве и при втором gateway.
- Саморемонт регистрации: PHP возвращает неизвестные инстансы в `unknown` → gateway шлёт
  воркеру кадр `{"type":"re-register"}` (кулдаун 300с, `LIVENESS_REREGISTER_COOLDOWN_S`) →
  воркер переотправляет register. Совместимо со старыми сборками в обе стороны.
- Закрыт задокументированный в `[[registry-06-liveness-push]]` KNOWN GAP («unknown не
  самовосстанавливается»).

**Acceptance Criteria:**
- `make phpstan` — чисто.
- `make cs` / `make cs-check` — чисто.
- `make test-php-live` — 492 passed.
- `make test-gateway` — 182 passed.
- `make test-python` — зелёный.
- `make docker-check` — чисто.

**Статус тестов на момент реализации:** все команды выше выполнены и зелёные (см. Acceptance
Criteria).

**ОСТАЁТСЯ:**
- Не задеплоено: нужна пересборка образов воркеров и gateway + пересоздание контейнеров.
- Не закоммичено — изменения в рабочем дереве (репозиторий параллельно правит другой человек).
- Известное ограничение: если gateway полностью недоступен, пушей нет — строки остаются
  `alive` до GC; намеренно не лечится Symfony-кроном (это отвергнутая модель «БД — источник
  правды»), для этого случая работает бейдж `stale`.
- Связанные карточки в `grooming/`: `worker-register-no-retry` (частично закрыта саморемонтом
  из Части 3, но fire-once register в воркере остался нерешённым), `conversion-matrix-no-stale-filter`.

**Зависит от:** `[[registry-06-liveness-push]]`, `[[registry-07-admin-workers-page]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** ready — реализовано и протестировано (QA зелёный), НЕ задеплоено, НЕ закоммичено;
требует пересборки образов + пересоздания контейнеров и коммита перед приёмкой.
