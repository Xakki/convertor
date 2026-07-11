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

**Execution Log:**

- **Источник данных — РЕШЕНО: Option 1 (scrape `/metrics` exporter'а).**
  Reachability: сервис `php` (app-symfony) в docker-сетях `default`+`backend`;
  `metrics-exporter` — в `backend`+`common`. Общий сегмент — `backend` (даже с
  `internal: true` DNS между контейнерами работает), поэтому
  `http://metrics-exporter:9472/metrics` достижим напрямую. Prometheus/dockprom
  и basic-auth не нужны — self-contained. URL вынесен в env
  `METRICS_EXPORTER_URL` (Symfony-only, читается Dotenv из `app-symfony/.env`,
  НЕ инжектится через docker-compose environment во избежание process-env
  override; дефолт = compose-адрес; продублирован `env()`-дефолтом в
  `services.yaml`).
- **Парсер изолирован** (`PrometheusMetricsParser`, чистый, без I/O) от
  скрейпа/агрегации (`QueueStatsProvider`) — юнит-тест на текстовой фикстуре без
  живого exporter'а. Учтены: пропуск `# HELP`/`# TYPE` и default-коллекторов
  (`process_*`/`python_*`), порядок-независимые метки, безлейбловые метрики,
  отсев `NaN`/`Inf`.
- **conv.dead исключён** из таблицы типов (это DLQ, не тип конвертации) —
  считается отдельно через `convertor_dead_letter_messages`.
- **Все 6 канонических `conv.<type>`** (document/image/audio/video/data/ai;
  источник — `messenger.yaml` + `keydb.py::WORKER_TYPES`) показываются всегда,
  даже если exporter по стриму ещё не эмитил gauge (overlay поверх seed-списка).
- **Stuck — мульти-сигнал, каждый отдельно:** per-stream `idle` (maxIdleMs > 5
  мин) и `stalled` (lag>0 при 0 consumers); глобально — dead-letter counter,
  `keydbUp` (`convertor_exporter_up`), и DB-stuck (Pending/Processing с
  `updatedAt` старше 15 мин). Новые репо-методы `findStuck`/`countStuck`
  (параметризованы, не переиспользуют `findPending`).
- **Graceful-недоступность:** HttpClient timeout 2 c; ловится
  `HttpClientExceptionInterface` (transport + 4xx/5xx из `getContent()`) →
  `exporterAvailable=false`, HTTP 200 (не 500). DB-сигнал остаётся. Доказательство
  «нет 500 при недоступном exporter'е» — юнит-тест
  `QueueStatsProviderTest::testUnreachableExporterDegradesGracefully` (MockHttpClient
  с transport-ошибкой). Функциональный endpoint-тест в docker-среде почти всегда
  бьёт по ЖИВОМУ exporter'у (php и metrics-exporter в общей сети `backend`), т.е.
  проверяет happy-path; он устойчив к обоим исходам (ассертит только `assertIsBool`).
- **API:** `GET /api/v1/admin/queues` (`#[IsGranted('ROLE_ADMIN')]`); UI —
  `templates/admin/queues.html.twig` (Alpine + `window.admin.fetch`, poll 10 c,
  подсветка stuck-строк, сигнальные бейджи, DB-таблица зависших).
- **Quality-gate:** `make phpstan` — 0 ошибок; `make cs-check` — чисто;
  `make test-php-live` — 177/177 зелёных (+9 новых: 3 parser + 2 provider + 3
  endpoint 401/403/200 + 1 repo DB-stuck). AccessDenied-строки в выводе — это
  403-ассерты, не падения.

**Изменённые файлы:**
- `src/Service/Admin/PrometheusMetricsParser.php` (new)
- `src/Service/Admin/QueueStatsProvider.php` (new)
- `src/Controller/Admin/Api/QueueController.php` (new)
- `src/Repository/ConversionRepository.php` (+findStuck/countStuck)
- `templates/admin/queues.html.twig` (new)
- `config/services.yaml`, `app-symfony/.env` (METRICS_EXPORTER_URL)
- tests: `tests/Unit/Service/Admin/{PrometheusMetricsParser,QueueStatsProvider}Test.php`,
  `tests/Functional/Controller/Admin/QueueControllerTest.php`,
  `tests/Functional/Repository/StuckConversionRepositoryTest.php`

**Status:** ready (ревью APPROVE WITH NITS; в done — с эпиком)
