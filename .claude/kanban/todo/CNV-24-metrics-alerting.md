### Метрики и алертинг (worker health, error alerting)

**Criticality:** Medium

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
- Метрики worker health и ошибок экспортируются и доступны для алертинга.
- Алерты на ошибки/деградацию заведены в общем Grafana/Prometheus.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- Metrics/alerting = **reuse кросс-проектного Grafana/Prometheus**, не отдельный стек.

**Status:** todo (Stage 6, не срочно).
