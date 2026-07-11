### Admin queue monitoring (эпик admin-panel #4)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 4. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Live-размеры очередей по типам + детект зависших задач. **Не переписывать XINFO на
PHP** — данные уже есть в `metrics_exporter`/Prometheus.

**Scope:**
- **Источник данных:** существующий `workers/metrics_exporter/exporter.py`
  (`docker-compose.yml:408`, Prometheus). Метрики: `convertor_stream_length`
  (XLEN по `conv.<type>`), `_group_pending` (XPENDING), `_group_lag`,
  `_group_consumers`, `_pending_max_idle_ms`, dead-letter (`conv.dead`).
  Symfony читает Prometheus (dockprom) — НЕ трогает KeyDB Streams напрямую
  (`WorkerStreamGateway` намеренно не имеет XLEN/XPENDING).
- **Проверить доступность Prometheus из app-symfony** (сеть/URL). Если недоступен —
  fallback: тонкий read-only эндпоинт в самом exporter'е или scrape `/metrics`.
  Решение реализатору; задокументировать выбор.
- **Stuck-job (мульти-сигнал):** высокий `_pending_max_idle_ms` + `_group_lag` при
  0 consumers + рост `conv.dead` + DB `Conversion.status` завис в Pending/Processing
  дольше порога (`ConversionRepository::findPending` + порог по `updatedAt`).
  Показывать все сигналы, не один флаг.
- **API:** `GET /api/v1/admin/queues` (размеры по типам + список stuck).
- **UI:** `templates/admin/queues.html.twig` — таблица по `conv.<type>`,
  HTMX-poll для live, подсветка stuck.

**Acceptance criteria:**
- [ ] Панель показывает размер каждой `conv.<type>` очереди (live через HTMX-poll).
- [ ] Зависшие задачи видны с указанием сигнала (idle/lag/DLQ/DB-stuck).
- [ ] Источник данных — Prometheus/exporter, не прямой XINFO из PHP (выбор
      задокументирован).
- [ ] Эндпоинт под ROLE_ADMIN; 403 иначе. `make phpstan` 0, `make cs-check` чисто.

**Files:** `src/Controller/Admin/QueueController.php`, новый сервис-клиент к
Prometheus/exporter, `templates/admin/queues.html.twig`. (Возможна правка
`workers/metrics_exporter/` при выборе fallback-эндпоинта.)

**Status:** todo.
