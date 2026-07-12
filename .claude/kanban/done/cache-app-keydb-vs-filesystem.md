### cache.app = FilesystemAdapter (не KeyDB) — ломается на scale-out

**Критичность:** Minor

**TAGS:**
- tech-debt
- infra

**Описание:**
Найдено при реализации `admin-panel-conv-toggle` (2026-07-11). CLAUDE.md
подразумевает «KeyDB DB0: cache», но фактически `cache.app` = `FilesystemAdapter`
(Redis-пул закомментирован в `config/packages/cache.yaml`; sessions=DB1, messenger=DB2).
Кэш — per-container. Пострадавшие места, где инвалидация кэша НЕ долетает до других
инстансов:
- `ConversionToggleService` — флип тумблера на инстансе A инвалидирует только A; B
  отдаёт устаревшее до истечения TTL (1ч). Т.е. отключённая конвертация может ещё
  до часа проходить на другом PHP-инстансе.
- `ConversionRegistry::invalidateMatrix()` — та же проблема с worker-matrix.

Сейчас приложение single-instance (как и quota-consumer), поэтому не воспроизводится.
Всплывёт при горизонтальном масштабировании PHP.

**Решение:**
- `cache.app` целиком переключается на KeyDB DB0 (новый `REDIS_CACHE_DSN=redis://keydb:6379?dbindex=0`).
  Все потребители `cache.app` (в т.ч. `ConversionToggleService`, `ConversionRegistry`) становятся shared →
  инвалидация видна всем инстансам.
- Приводим конфиг в соответствие CLAUDE.md («KeyDB DB0: cache»).

**Decisions (2026-07-12):**
- Выбран вариант «весь cache.app → Redis DB0» (а не выделенный пул для toggle/registry) —
  проще и ровно по CLAUDE.md-конвенции DB-индексов.

**Реализация (2026-07-12):**
- `cache.yaml`: `app: cache.adapter.redis` + `default_redis_provider: %env(REDIS_CACHE_DSN)%`;
  `when@test` → `cache.adapter.array` (тесты не требуют живого KeyDB).
- `.env`: `REDIS_CACHE_DSN=redis://keydb:6379?dbindex=0`; `services.yaml`: env()-дефолт того же DSN.
- Побочный эффект (желаемый): `doctrine.result_cache_pool` = `cache.app` → тоже уходит на KeyDB DB0.
- QA: `make phpstan` OK, `make cs-check` OK. Ревью: APPROVE, блокеров нет.

**Status:** done.
