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

**Решение (черновик):**
- Развести общий KeyDB cache-pool (DB0) и переключить `cache.app` (или отдельный
  пул для toggle/registry) на него → инвалидация видна всем инстансам.
- Согласовать с CLAUDE.md («KeyDB DB0: cache») — привести конфиг в соответствие.

**Status:** grooming.
