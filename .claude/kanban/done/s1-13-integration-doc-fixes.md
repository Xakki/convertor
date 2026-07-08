### S1-13 — Сквозная интеграция всех воркеров + фиксы доков

**Критичность:** High

**TAGS:**
- transport
- integration
- docs

**Описание:**
Финальная сборка S1: end-to-end smoke по всем воркерам через WS + приведение доков в соответствие. Сворачивает `[[queue-channel-names-mismatch]]`.

**Интеграция (§9):**
- Прогнать по одному фейковому WS-клиенту на каждый тип (`ai/document/image/audio/video/data`): каждый читается gateway'ем из своего `conv.<type>`, ни один не открывает соединение к KeyDB/S3, мульти-тип воркер (audio+video) держит два отдельных `workerId`-соединения с непересекающимися PEL.
- По-настоящему мёртвый воркер посреди задачи → per-type idle-timeout → reclaim → переотправка второму воркеру (backstop, §6.6 путь «b»).
- Идемпотентность: форсировать дублирующую доставку (тот же `jobId`/`conversionId`) → ровно один сохранённый результат по детерминированному ключу, без двойного списания/возврата квоты.
- Маршрутизация Stream: `workerType:"image"` всегда получает только `conv.image`.

**Фиксы доков:**
- `docs/queue-contract.md` §2 — описать чистый single-JSON контракт (Option D), убрать упоминания двойного конверта `{body,headers}`.
- `CLAUDE.md` (секция `## Queue Architecture`) — имена каналов `conversion.documents`/`images`/… → фактические `conv.<type>` (единственное число, префикс `conv.`), включая недостающий `conv.data`. Сверено с `app-symfony/config/packages/messenger.yaml` (2026-07-05): фактический список — `conv.document/image/audio/video/data/ai`; единственный устаревший ref во всём репо — `CLAUDE.md:41` (др. конфигов/README/docs с `conversion.*` нет).

**Файлы:**
- Создать/изменить: `workers/tests/` — интеграционный сквозной прогон (все 6 типов, backstop-reclaim, идемпотентность, маршрутизация).
- Изменить: `docs/queue-contract.md` (§2 single-JSON).
- Изменить: `CLAUDE.md` (имена каналов `conv.<type>` вкл. `data`).

**Критерии приёмки:**
- Сквозной прогон: по одному WS-клиенту на каждый из 6 типов читается из своего `conv.<type>`; ни один не коннектится к KeyDB/S3; ffmpeg держит 2 `workerId`-соединения с непересекающимися PEL.
- Backstop: мёртвый воркер → idle-reclaim → переотправка второму воркеру; PEL в итоге пуст.
- Идемпотентность: дублирующая доставка → один результат, без двойного списания квоты.
- `docs/queue-contract.md` §2 описывает чистый single-JSON; нет упоминаний `{body,headers}`-обёртки.
- `CLAUDE.md` перечисляет `conv.document/image/audio/video/data/ai`; grep не находит `conversion.*` в доках/конфигах.
- Весь набор зелёный: `make phpstan`, `make cs`, `pytest workers/tests`, `make test-gateway`, `make docker-check`.

**Зависит от:** `[[s1-01-clean-wire-contract]]`, `[[s1-02-gateway-keydb-reader]]`, `[[s1-03-ws-server-dispatch]]`, `[[s1-04-result-relay-ack]]`, `[[s1-05-ping-liveness]]`, `[[s1-06-reclaim-poison-dlq]]`, `[[s1-07-progress-conv-status]]`, `[[s1-08-shared-ws-client]]`, `[[s1-09-ai-worker-migrate]]`, `[[s1-10-streamconsumer-refactor-unify]]`, `[[s1-11-onserver-workers-migrate]]`, `[[s1-12-compose-nginx-env-version]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Execution Log:**
- Part A — добавлен `workers/tests/test_ws_transport_integration.py` (4 теста, real-KeyDB harness как в `test_gateway_reclaim_dlq.py`: реальный `WsGateway`+`KeyDbGateway`, mock-relay `RelayRecorder`, фейковые воркеры = «сырые» websockets-клиенты, НЕ трогают KeyDB/S3):
  - `[1]` маршрутизация всех 6 типов: по одному воркеру на `conv.<type>`; каждый получает РОВНО свою задачу (отдельные conversionId → image видит только conv.image).
  - `[2]` ffmpeg dual-connection: два `workerId` (`ffmpeg-audio`/`ffmpeg-video`), PEL по непересекающимся consumer'ам (disjoint меряется по consumer, т.к. stream-id per-stream, не глобальны).
  - `[3]` backstop-reclaim (§6.6 «b»): воркер `die_after_job` → `reclaim._sweep_all_types` (idle-ms=1, детерминированно, без гонки таймаутов) → handoff → второй воркер получает ту же запись → XACK → PEL пуст.
  - `[4]` дублирующая доставка: A удерживает → reclaim отдаёт B → оба persist'а несут ТОТ ЖЕ jobId (детерминированный якорь). Транспорт НЕ дедуплицирует межсоединенческую доставку — «ровно один результат + без двойной квоты» это PHP `ConversionResultPersister` (покрыт `ConversionResultPersisterTest.php::testIdempotencySkipsTerminalConversion`/`testFailedStateRefundsQuotaAndFlushesOnce`).
  - Изоляция: файл сеет в РЕАЛЬНЫЕ `conv.<type>` → фиксированный db-индекс **4** (keydb.conf `databases 5`: 0=cache,1=sessions,2=queues,3=e2e,4=free), иначе рядом поднятый dev/e2e-стек «ворует» conv.audio/video/data. Аналог e2e-изоляции на db 3.
  - Прогон: добавлен в таргет `make test-gateway` (не в `test-python`, т.к. нужен реальный keydb).
- Part B:
  - `CLAUDE.md:41` — имена каналов `conversion.*` → `conv.document/image/audio/video/data/ai` (вкл. недостающий `conv.data`). `grep -rn "conversion\." CLAUDE.md docs/ app-symfony/config/` — чисто.
  - `docs/queue-contract.md §2` — уже описывал чистый single-JSON (Option D) начиная с s1-01. Единственные упоминания `{body,headers}` — КОНТРАСТНОЕ обоснование («сток оборачивает в {body,headers}; мы — нет») + прямое отрицание «нет обёртки». РЕШЕНИЕ: оставить как есть (не выпиливать rationale — он ценен), критерий «§2 описывает single-JSON» удовлетворён. Флаг тимлиду: буквальное «убрать любое упоминание» противоречит сохранению обоснования.
- Гейты (все зелёные): `make phpstan` (OK, 40 files), `make cs-check` (0/56), `make test-gateway` (100 passed, вкл. 4 новых), `make test-python` (все suite'ы passed, exit 0), `make test-drift` (2 passed), `make docker-check` (exit 0).
- Review-fix (MEDIUM, A4 money-path): добавлен PHPUnit-регресс `ConversionResultPersisterTest::testIdempotencySkipsFailedConversionNoDoubleRefund` — дубль-доставка задачи уже в терминальном Failed НЕ вызывает повторный refund/flush (guard покрывает оба терминала). Только тест, прод-код не тронут. `make test-php` — 101 passed / 389 assertions, exit 0 (persister: 6 tests OK).

**Status:** progress
