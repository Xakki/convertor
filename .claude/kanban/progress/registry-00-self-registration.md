### Dynamic worker self-registration → DB-driven conversion matrix (EPIC)

**Criticality:** Medium

**TAGS:**
- feature
- tech-debt

**Description:**
EPIC (Stage 2+). Workers register their conversion capabilities at startup; PHP builds the
conversion matrix dynamically from a DB source, replacing the hardcoded
`ConversionRegistry::workerCapabilities()`. Capabilities are today declared in **3 hardcoded
places** — Python per-worker `CAPABILITIES` (canonical), PHP `workerCapabilities()`, and
`WorkerController::ALLOWED_TYPES`. No worker/capability DB table exists. On-server workers attach
to KeyDB Streams directly with no registration; remote workers use only the pull-API.

**Impact:**
While the matrix is hardcoded, PHP and workers drift; AI routing and multi-worker pairs need a
generalized router the static matrix can't express.

**Decisions:**
- **Registration transport = `POST /api/v1/worker/register` on the pull-API for ALL workers**
  (only option honoring "remote workers get no direct DB/KeyDB" rule; on-server can also reach PHP).
  **Registration MUST be best-effort / non-fatal** — a worker that can't reach PHP still starts and
  consumes from KeyDB (else a PHP blip halts on-server processing = regression).
- **Storage = new Doctrine entity `WorkerCapability`**, one row per worker-type, JSON `capabilities`
  blob mirroring the Python dict 1:1 (stream, isAi, routing_keys, matrix, image/version, last_seen),
  upsert keyed on worker-type. Registry reads a `WorkerCapabilityRepository` instead of the hardcode;
  `getSupportedFormats()`/`isSupported()`/`streamFor()` signatures unchanged; cache the built matrix.
- **Startup / no-worker-up = keep hardcoded `workerCapabilities()` as fallback when DB empty/unreachable
  (Phase 1); seed DB via migration so it's never empty (Phase 2).** Empty-matrix-reject was rejected
  (service-down window, blank `/formats`).
- **Eviction = long-TTL GC, NOT short liveness gating.** KeyDB streams buffer durably → a down worker
  doesn't need its pairs removed; jobs wait. Separate **capability** (durable, feeds `/formats`+routing)
  from **liveness** (last_seen, for monitoring + future candidate selection). Prune capability only
  after a generous configurable TTL (hours–days) or explicit deregister.
- **`/formats`** — unchanged externally; internal source becomes the repository (active capabilities).
- **Drift-test rework** — once PHP no longer *declares* pairs, drift dissolves; replace with a
  **register-round-trip** test (registered DB == union of workers' declared CAPABILITIES). Note:
  `bin/dump-matrix.php` does `new ConversionRegistry()` with no container → breaks when the registry
  needs a repository; needs a seeded DB/fixture; retire/regenerate `ConversionRegistryGoldenTest`.
- **⚠ Stage-7 "coming-soon" pairs = LET THEM DISAPPEAR (honest 400).** [USER DECISION 2026-07-01]
  A registration-driven matrix contains only what a live worker declares, so the deliberately-advertised
  unhandled pairs (xlsx→pdf, ppt→pdf, dwg→*, pdf→jpg) vanish and the API flips from accept-then-DLQ to
  **400-reject-at-submit**. This **overrides** `align-document-stream-matrix-dlq`'s "do NOT remove the
  Stage-7 pairs" decision. Interim (before Phase 2 lands): those pairs still exist → the align-document
  `PermanentError` fast-DLQ covers them; after Phase 2 they're gone → 400 up-front, no churn.
- **Collision policy (interim, Phase 1-2):** two workers registering the same pair → mirror current
  `buildMatrix()`: non-AI precedence, last-registered-wins. Real multi-candidate handling = Phase 3.
  **Уточнено registry-03 ревью (2026-07-22):** появился второй уровень — кросс-типовые коллизии
  между двумя non-AI воркерами (напр. `pdf→txt`: `document` vs `image`-OCR) резолвятся
  детерминированным precedence-рангом `ConversionRegistry::NON_AI_PRECEDENCE`, НЕ порядком строк
  из БД; last-registered-wins остаётся только для коллизий ВНУТРИ одного ранга (несколько
  инстансов одного workerType). Тоже interim, снимается Phase 3 multi-candidate router'ом.

**Груминг Phase 2 (2026-07-22):**
- D1. Хардкод `workerCapabilities()` УДАЛЯЕТСЯ; вместо fallback — seed-миграция, заливающая
  текущий снапшот матрицы, чтобы БД никогда не была пустой.
- D2. Heartbeat: gateway агрегирует ping'и и ПУШИТ liveness в PHP на новый internal-эндпоинт
  (вариант «периодический re-register» отклонён — gateway видит реальный обрыв WS и уже
  получает cpu/mem/load).
- D3. Admin-видимость: ОТДЕЛЬНАЯ страница Workers в админке (не расширение `/admin/queues`).
- D4. `worker-registry-fragility` втягивается в Phase 2: нативный `INSERT ... ON DUPLICATE KEY
  UPDATE` + переход на ключ `(workerType, instanceId)` — схема меняется один раз, а не дважды.
- D5. Пункт Phase 2 «вывести `WorkerController::ALLOWED_TYPES` из реестра» ИСКЛЮЧЁН как мёртвый
  (константы нет с 03.07); вместо него заведена отдельная grooming-карточка
  `[[worker-type-lists-hardcode]]` на реальные оставшиеся хардкод-списки типов.

**Phasing:**
- **Phase 1 — infra, no behavior change.** `POST /worker/register` (both classes, best-effort);
  `WorkerCapability` entity + migration + repository; registry builds matrix from DB **with hardcoded
  fallback**; workers call register at startup (`StreamConsumerBase.__init__`; AI via `PullApiClient`);
  add caching. Drift/golden tests unchanged (fallback covers). Independent of the AI-pair shape.
- **Phase 2 — remove hardcode + eviction.** Отгрумлена 2026-07-22, разбита на 6 подкарточек
  в порядке исполнения:
  1. `[[registry-02-schema-multi-instance]]` — ключ `(workerType, instanceId)` + нативный upsert
     (снимает TOCTOU carry-over), `isAi` из CAPABILITIES, семантика `streams`/`routingKeys`.
  2. `[[registry-03-seed-migration]]` — seed-миграция снапшота матрицы в БД, без Stage-7 пар.
  3. `[[registry-04-matrix-tooling-tests]]` — восстановить `bin/dump-matrix.php`, переписать
     drift-тест на register-round-trip, перевести golden-тест на seeded DB (ДО удаления хардкода).
  4. `[[registry-05-drop-hardcode]]` — удалить `workerCapabilities()`/`buildMatrixFromHardcode()`;
     Stage-7 pairs исчезают → честный 400 на submit.
  5. `[[registry-06-liveness-push]]` — gateway пушит агрегированный ping в PHP + long-TTL GC.
  6. `[[registry-07-admin-workers-page]]` — отдельная страница Workers в админке.

  (Пункт «вывести `WorkerController::ALLOWED_TYPES` из реестра» из первоначального дизайна
  исключён как мёртвый — константы нет с 03.07, см. «Дрейф» ниже; реальные оставшиеся
  хардкод-списки типов воркеров вынесены в отдельную grooming-карточку
  `[[worker-type-lists-hardcode]]`.)
- **Phase 3 — generalized candidate+intent router** (absorbs php-ai-virtual-key Phase 2). Matrix stores
  multiple candidate workers per pair (pdf→txt: document-extract vs image-OCR); `streamFor()` becomes a
  resolver taking intent/flag + picks among live candidates (liveness now matters). Generalizes the
  `ocr` special case. Foundation for `conversion-chaining` graph path-finding.

**Dependencies:**
- `php-ai-virtual-key-submit-resolution` — **блокер СНЯТ** (карточка в `done/`: AI-матрица плоская
  и в PHP, и в Python, golden-фикстура уже без `_stt`/`_tts`). Phase 2 больше им не блокирована.
- `align-document-stream-matrix-dlq` (done) — interacts via the Stage-7-pairs decision above;
  перекрывается в `[[registry-05-drop-hardcode]]` (Stage-7 pairs исчезают → честный 400).
- `worker-registry-fragility` (grooming) — **втянут в Phase 2** через `[[registry-02-schema-multi-instance]]`
  (схемная часть: ключ `(workerType, instanceId)`) и `[[registry-07-admin-workers-page]]`
  (admin-видимость).
- `conversion-chaining` (grooming, Stage 7) — needs Phase 3's live capability graph for path-finding.
- **S1 WS-transport (in-flight) — Phase 1 register-hook seam MOVED.** The design note
  "workers call register at startup (`StreamConsumerBase.__init__`)" predates S1.
  `[[s1-10-streamconsumer-refactor-unify]]` splits `StreamConsumerBase` into transport-agnostic
  `process_job` + a transport layer, and `[[s1-08-shared-ws-client]]` introduces the shared WS client.
  → Phase 1 must hook `register()` into the **shared WS-client startup (s1-08 seam)**, not the old
  `StreamConsumerBase.__init__`, and start only AFTER `[[s1-11-onserver-workers-migrate]]` (else it
  targets a seam S1 is deleting). Best-effort/non-fatal rule unchanged.

**Status:** Phase 2 отгрумена 2026-07-22, разбита на `registry-02`…`registry-07`, эпик в работе
(карточка перемещена в `progress/`); Phase 3 остаётся здесь до своей очереди (нужен дизайн
multi-candidate router).
- **Phase 1 выделен в todo → done** → `[[registry-01-worker-register]]` (2026-07-07, после
  приземления s1-11). Seam register() уточнён: хук в `WsClient` на `ready` (единообразно для всех
  воркеров), а не старый `StreamConsumerBase.__init__`. Register-контракт закреплён в карточке Phase 1.

**Carry-over из ревью Phase 1 (в Phase 2) — назначены:**
- **Семантика `streams` vs `routingKeys`.** Сейчас Python шлёт в оба поля одно и то же (`routing_keys` из
  CAPABILITIES, напр. `["image"]`). → назначено `[[registry-02-schema-multi-instance]]`: решить —
  разные сущности (имена stream-каналов `conv.<type>` vs routing-суффиксы) или схлопнуть.
- **`isAi` источник.** Python выводит `isAi` из `worker_type == "ai"`, а не из `CAPABILITIES["isAi"]`.
  → назначено `[[registry-02-schema-multi-instance]]`: брать из `caps.get("isAi", False)`.
- **Upsert TOCTOU.** `WorkerCapabilityRepository::upsert()` — find-then-update на уровне PHP; при гонке
  двух одновременных register одного `workerType` второй `flush()` упрётся в UNIQUE и даст 500.
  → назначено `[[registry-02-schema-multi-instance]]`: нативный `INSERT ... ON DUPLICATE KEY UPDATE`.

**Дрейф, выявленный при груминге (2026-07-22):**
1. `WorkerController::ALLOWED_TYPES` удалена 03.07 в `17b1ac8` (вместе с claim-by-type action) —
   пункт исходного Phase 2-дизайна «вывести ALLOWED_TYPES из реестра» мёртв, исключён (D5);
   реальные оставшиеся хардкод-списки типов вынесены в `[[worker-type-lists-hardcode]]`.
2. `app-symfony/bin/dump-matrix.php` удалён 10.07 несвязанным коммитом `2105d70` (auth-фича) →
   `test_routing_drift.py` молча скипается ~12 дней (`pytest.skip` на отсутствие инструмента,
   не падение) → чинится в `[[registry-04-matrix-tooling-tests]]`.
3. Stage-7 «coming-soon» пары уже отдают честный 400 на DB-пути (`workers/libreoffice/worker.py:86-98`
   их не декларирует) — они остаются только в хардкод-fallback; окончательно исчезают в
   `[[registry-05-drop-hardcode]]`.
4. Блокер `php-ai-virtual-key-submit-resolution` СНЯТ (карточка в `done/`) — AI-матрица плоская
   и в PHP, и в Python, golden-фикстура `conversion_matrix.golden.txt` уже без `_stt`/`_tts`.
