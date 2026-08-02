### Метрики и алертинг (worker health, error alerting)

**Criticality:** Medium
**Epic:** [[CNV-54]]

**TAGS:**
- feature
- infra

**Описание:**
Выделено из docs-prod-polish (Stage 6 split, 2026-07-11). Стадия 6 (production polish),
не срочно.

Наблюдаемость: экспорт метрик, worker health checks, error alerting. Переиспользовать
кросс-проектный стек мониторинга (Grafana/Prometheus), а не поднимать свой.

**Проблема:**
Нет observability: не видно здоровья воркеров, нет алертов на ошибки/зависания задач.

**Влияние:**
Не production-safe: сбои воркеров и рост ошибок незаметны до жалоб пользователей.

**Recommendation:**
- Экспорт метрик (воркеры + API), health checks воркеров, алерты на ошибки.
- Переиспользовать общий Grafana/Prometheus (кросс-проектный стек), дашборды/алерты в нём.

**Acceptance Criteria:**
- [x] Метрики worker health (прокси: consumers/lag/pending) и ошибок экспортируются и доступны для алертинга.
- [x] Алерты на ошибки/деградацию заведены in-repo (`deploy/monitoring/convertor.rules`); apply в dockprom — см. Execution Log / docs.
- [x] Tests: `make test-python-metrics` green. PHPStan/cs не трогали (PHP не меняли).

**Decisions:**
- Metrics/alerting = **reuse кросс-проектного Grafana/Prometheus**, не отдельный стек.
- (2026-08-02) In-repo SoT: `deploy/monitoring/` + `docs/monitoring.md`. Per-worker CPU Prometheus — out of scope (CNV-35 = DB/admin).
- (2026-08-02) `metrics-exporter` REDIS_HOST → `${COMPOSE_PROJECT_NAME}-keydb`: на сети `common` имя `keydb` резолвилось в чужой KeyDB → lag/consumer gauges отсутствовали (причина отключения QueueLagHigh 2026-07-08).

**Status:** progress (implementation done; dockprom sync needs root).

## Execution Log

**2026-08-02 (implementer):**
- Added `deploy/monitoring/`: `convertor.rules` (5 alerts), `prometheus-scrape-snippet.yml`, `grafana-convertor-streams.json` (synced from dockprom).
- Added `docs/monitoring.md` (RU); pointer from `docs/queue-streams.md`.
- Alerts: keep ExporterDown + DeadLetterGrowing; re-enable QueueLagHigh (>100/10m); add NoConsumers (==0/10m); add PendingStall (>600s idle/5m).
- Exporter: always set `pending_max_idle_ms` (0 when PEL empty). Compose: fix REDIS_HOST for multi-network DNS collision.
- Verified live: after recreate, stream lag/consumers series present; `REDIS_HOST=xakki-convertor-keydb`.
- Tests: `make test-python-metrics` — 16 passed.
- **dockprom NOT synced** — `/home/soft/dockprom/prometheus/convertor.rules` root-owned, no write. Apply steps in `docs/monitoring.md` (sudo cp + Prometheus reload).
