### Механика KeyDB Streams: документация + лаг-метрики + drift-тест

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
Подписка и распределение задач на KeyDB Streams **уже спроектированы и реализованы** (`workers/common/stream_consumer.py`, `docs/queue-contract.md`, `docs/queue-redesign-design.md`). При груминге 2026-06-20 разобрали механику; карточка из «понять» сведена к трём конкретным доделкам: целостная документация + метрики лага + drift-тест.

**Текущая механика (зафиксировано как есть, изменению не подлежит — только документируем):**
- Топология: один стрим на routing-key — `conv.<category>` (`document/image/audio/video/data`) + `conv.ai` (routing-key = `isAi ? 'ai' : category`; `markup` свёрнут в `document`). Результаты → `conv.result`, статус → `conv:status:{id}` (TTL 24ч), DLQ → `conv.dead`.
- Подписка/распределение: одна consumer-group `convertor` на стрим, `XREADGROUP ... >`, имя консьюмера `{hostname}-{pid}`. N инстансов категории делят нагрузку самой группой (без дублей).
- Отказоустойчивость: `XAUTOCLAIM` (idle ≥ `CONSUMER_IDLE_MS`=300с) перехватывает зависшее; delivery-count из `XPENDING`; `max_retries=3` → `conv.dead`; идемпотентность по статусу `completed`.
- Порядок/батч: `READ_COUNT=1` (по одной записи на стрим).

**Проблема:**
- Механика нигде не описана целостно (разбросана по двум докам + коду).
- Нет метрик лага очереди — `XPENDING` используется только для ретраев; завалы/зависания не видны (хотя инфра Prometheus/Grafana есть).
- Нет drift-теста «каждый routing-key имеет ≥1 воркера; worker matrix ⊆ registry» — именно его отсутствие пропустило баг «`conv.document` без consumer» (libreoffice).

**Влияние:**
Без метрик завалы и зависшие задачи незаметны; без drift-теста рассинхрон PHP↔воркеры (стрим без потребителя) уходит в прод молча.

**Решение:**
1. **Документация:** свести механику подписки/распределения/реклейма/ретраев/DLQ в один раздел `docs/` (обновить/связать `queue-contract.md`).
2. **Лаг-метрики:** экспорт consumer-lag (`XPENDING`/длина PEL), размера стримов, размера `conv.dead` в Prometheus → дашборд/алерт в Grafana (mon.xakki.ru).
3. **Drift-тест:** автотест «каждый routing-key из реестра имеет ≥1 воркера-потребителя; объявленная воркерами matrix ⊆ registry».

**Критерии приёмки:**
- В `docs/` есть целостное описание Stream-механики (топология, группы, консьюмеры, реклейм, ретраи, DLQ, идемпотентность).
- Метрики лага/PEL/DLQ отдаются в Prometheus; есть дашборд (или панель) в Grafana и базовый алерт на рост лага/DLQ.
- Drift-тест в CI/`make`: падает, если у routing-key нет consumer'а или matrix ⊄ registry.

**Decisions (груминг 2026-06-20):**
- **Масштабирование — горизонтальное, инстансами.** N одинаковых инстансов категории в одной consumer-group делят нагрузку. Уже работает; механику не меняем.
- **Backpressure: `READ_COUNT=1` оставляем** (строгий порядок, простота). Пропускную поднимаем числом инстансов воркера, не prefetch'ем.
- **Лаг-метрики — добавляем сейчас** (Prometheus/Grafana уже есть).
- **Drift-тест — добавляем сейчас** (ловит «стрим без consumer»).
- ack/claim при падении, ретраи, DLQ, идемпотентность — уже решены в коде, фиксируем как есть.

Siblings: [[distributed-workers]] · [[validate-libreoffice-worker]] · [[backend-hardening-bugs]]

---

## Execution Log (2026-06-22)

**1. Docs** — новый `docs/queue-streams.md` (топология, группы/консьюмеры, XAUTOCLAIM-reclaim, ретраи/DLQ, идемпотентность+ordered-commit, backpressure, метрики, drift). Перелинкован с `queue-contract.md` ↔ `queue-redesign-design.md`.

**2. Лаг-метрики** — отдельный экспортер-сайдкар `workers/metrics_exporter/exporter.py` (контейнер `convertor-metrics-exporter:9472`, сети `backend`+`common`, без публикации хост-порта). Метрики: `convertor_stream_length`, `_stream_group_pending`, `_stream_group_lag`, `_stream_group_consumers`, `_stream_pending_max_idle_ms`, `_dead_letter_messages`, `_exporter_scrape_errors_total`, `_exporter_up`. Lag: XINFO GROUPS `lag`/`entries-read` → XRANGE-fallback для KeyDB 6.x. Скрейп защищён (socket_timeout, per-stream try/except).
  - Мониторинг (dockprom, `/home` commit `2540745`): scrape-job `convertor-exporter`; alert-правила `convertor.rules` (`ConvertorExporterDown`/`ConvertorDeadLetterGrowing`/`ConvertorQueueLagHigh`, label `project=convertor`); AlertManager receiver `telegram-convertor` + route `project=convertor` → канал `-1001115524886` топик `16859` (бот @StudentAssistentMonitorBot); Grafana дашборд «Convertor — KeyDB Streams» (9 панелей).

**3. Drift-тест** — `workers/tests/test_routing_drift.py` (assert A: каждый routing-key реестра имеет ≥1 воркера; assert B: worker matrix ⊆ registry). Живой реестр через `dump-matrix.php --json`. `make test-drift` + в дефолтном `make test`; skip только при отсутствии PHP/docker, fail на реальном дрифте.
  - **Сведён реальный дрифт:** archive убран из реестра (прод-баг «стрим без consumer»; API archive 422→400); добавлены `*→toml`, `3gp`-вход, `webm/flv/wmv→audio extract`, `md→{odt,rtf,txt,epub}`. golden master 306→326.

**QA:** `make test` зелёный (PHP 59/59, Python 133 passed/8 skipped), PHPStan 0, CS 0. **Ревью:** APPROVE-WITH-NITS, блокеров нет; 3 нита закрыты.

**Осталось (операционное, перед `ready`):**
- Деплой контейнера экспортера: `make build-metrics-exporter && make up` (стек живой) → target `convertor-exporter` UP, метрики потекут.
- Бот @StudentAssistentMonitorBot в канал `t.me/c/1115524886/16859` (на момент записи `getChat`=chat not found) → живой тест доставки алерта.
