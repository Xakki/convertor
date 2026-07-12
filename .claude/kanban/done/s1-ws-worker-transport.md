### Эпик: S1 — WebSocket-транспорт воркеров (umbrella)

**Критичность:** High

**TAGS:**
- feature
- epic
- transport
- worker

**Описание:**
Зонтичная карточка подсистемы **S1** трёхчастного редизайна очереди (S1 транспорт → S2 потоковый приём аудио + VAD → S3 приоритеты/маршрутизация/учёт скорости). S1 сливает два старых транспорта воркеров в один постоянный WebSocket:
- off-server воркеры ходили через HTTP pull-API (`/api/v1/worker`, short-poll ~10 s);
- on-server воркеры (document/libreoffice, audio+video/ffmpeg, image, data) читали свой `conv.<type>` Stream и ходили в S3 **напрямую**, каждый сам делая `XACK`.

Вместо этого — новый асинхронный Python-сервис **WS-Gateway**, который становится **единственным** читателем KeyDB Streams для ВСЕХ `conv.<type>` (владеет `XREADGROUP`/`XACK`/`XAUTOCLAIM`) и держит WS-соединения воркеров. Symfony остаётся синхронным единственным источником истины (БД + S3 + терминальный статус) и **перестаёт делать XACK**. KeyDB наружу не публикуется — воркеры больше не трогают ни KeyDB, ни S3 напрямую.

Spec (источник истины по всем решениям): `docs/superpowers/specs/2026-07-02-ws-worker-transport-design.md`.

**Влияние / Решение:**
Поллинг добавлял до `poll_interval` задержки на каждую диспетчеризацию, а прямой доступ on-server-воркеров к KeyDB/S3 размазывал владение источником истины и раздавал S3-креды по всем контейнерам. S1 проталкивает задачу готовому воркеру в момент попадания в Stream (нет poll-задержки) и централизует чтение Stream + ack + живой статус в одном gateway. Жёсткий инвариант: надёжность не меняется — KeyDB Streams остаются источником истины, WebSocket — только транспорт доставки, at-least-once сохраняется.

**Критерии приёмки (эпик):**
- Все 6 воркеров (по типам `ai/document/image/audio/video/data`) — WS-клиенты gateway; ни один не читает `conv.<type>` и не делает self-`XACK`.
- WS-Gateway — единственный читатель каждого job-стрима; KeyDB **никогда** не покидает внутреннюю сеть `backend`.
- Живой статус (`conv:status`) сохранён — теперь его пишет gateway; `ConversionStatusReader`/`getStatus()` без изменений.
- Golden-контракт-тест чистого single-JSON зелёный с обеих сторон (PHP + Python).
- `make phpstan` / `make cs` зелёные; все таргеты тестов воркеров + gateway (`pytest workers/tests`, `make test-gateway`) зелёные; `make docker-check` проходит с подключённым сервисом gateway.

**Decisions (зафиксировано с пользователем 2026-07-02):**
- **Все воркеры в S1.** AI + 4 on-server (document/libreoffice, audio+video/ffmpeg, image, data) становятся WS-клиентами; перестают читать `conv.<type>` и делать self-`XACK`. Никакого частичного/поэтапного покрытия «сначала AI, потом остальные» — вся шестёрка мигрирует в S1.
- **Единая I/O-модель.** Воркеры общаются ТОЛЬКО с gateway (WS) + Symfony API (объёмные payload'ы); НИ прямого S3, НИ прямого KeyDB (on-server тоже). Вход — `GET /jobs/{id}/input`. Малый результат (≤256 KB) — inline по WS → gateway relay в Symfony. Большой — воркер сам делает `POST /jobs/{id}/result` (multipart) + шлёт `{type:"result", resultKey}` по WS. **Переопределяет** прежнее правило CLAUDE.md «on-server = S3 in/out».
- **Полная унификация результата (Option A).** Убираем worker-writable `conv.result` и worker-writable `conv:status`; `QueueResultConsumerCommand` уходит на пенсию; единый терминатор persist для обоих путей — `App\Service\Queue\ConversionResultPersister`.
- **Progress-протокол.** Новый WS-фрейм `progress{jobId,percent,stage?}`, тик ~1/сек для объёмных задач, эмитится ТОЛЬКО пока задача в работе. Отдельный фрейм от `ping` (`ping` = liveness+телеметрия, `progress` = прогресс задачи).
- **Живой статус у gateway.** Gateway пишет `conv:status:{conversionId}`: dispatch → `state=processing`, на каждый `progress` → обновляет `percent`/`stage`, на терминале/ack → `DEL`. `ConversionStatusReader`/`getStatus()` без изменений (читают тот же хеш, при очищенном/протухшем падают на строку БД).
- **Сеть/nginx = факт репо.** Gateway на сетях `backend` (достучаться до keydb) + `default` (публичный фронт + egress к Symfony). Публичный `wss://` — через **локальный** сервис `nginx` репо, `location /ws/worker/`. НЕ `common`, НЕ shared-nginx.
- **Internal relay result+fail.** Новые внутренние relay-эндпоинты `POST /api/v1/internal/worker/{result,fail}` на собственном файрволе с `GATEWAY_INTERNAL_TOKEN`. Публичный `POST /jobs/{id}/fail` **удалён**; публичный `POST /jobs/{id}/result` остаётся только для large-multipart-пути (и теряет свой `XACK`).
- **Wire-контракт = Option D.** Кастомный Messenger-транспорт пишет чистый single-JSON в `conv.*` (без внешней обёртки `{body,headers}` стокового redis-messenger). `dispatch()` / `ConversionMessage` / call-site — **БАЙТ-В-БАЙТ те же** (§3 диспетча цел); и PHP, и Python декодируют **одинарно**. Golden-фикстура `app-symfony/tests/Fixtures/messenger_envelope.golden.json` замораживает контракт с обеих сторон. Caveat: phpredis-writer кастомного транспорта ОБЯЗАН выставлять `SERIALIZER_NONE`, иначе phpredis PHP-сериализует поле `message`.
- **slots:1 в S1.** Кредитный цикл спроектирован обобщённо, но тестируется на 1 слоте. Версия воркера = `APP_VER` (`.env`) + build-счётчик `.i` → полная `version`, запекается в образ при build; репортится в `ready`, транспортируется по проводу, но НЕ потребляется (потребление = S3). Reclaim — **только** per-type idle `XAUTOCLAIM`; reclaim по WS-дисконнекту **запрещён**. ffmpeg = ДВА WS-соединения (`audio` + `video`), свой `workerId` на каждый тип.

**Phasing:**
- **Фаза 1 — транспортный фундамент.** `[[s1-01-clean-wire-contract]]` → `[[s1-02-gateway-keydb-reader]]` → `[[s1-03-ws-server-dispatch]]`. Чистый single-JSON контракт, gateway-скелет + чтение KeyDB, WS-сервер + кредитный dispatch. Строго последовательно.
- **Фаза 2 — протокол gateway.** `[[s1-04-result-relay-ack]]`, `[[s1-05-ping-liveness]]`, `[[s1-06-reclaim-poison-dlq]]`, `[[s1-07-progress-conv-status]]`. Все зависят от Фазы 1; `s1-06`/`s1-07` дополнительно зависят от `s1-04`.
- **Фаза 3 — общий WS-клиент.** `[[s1-08-shared-ws-client]]` — переиспользуемый `workers/common/ws_client.py`; зависит от готового протокола (Фаза 2). Точка-разветвление: гейтит всю миграцию воркеров.
- **Фаза 4 — миграция воркеров.** `[[s1-09-ai-worker-migrate]]`, `[[s1-10-streamconsumer-refactor-unify]]`, `[[s1-11-onserver-workers-migrate]]`. AI-воркер на общий клиент; рефактор `StreamConsumerBase`; on-server-воркеры на общий клиент. `s1-11` зависит от `s1-10`.
- **Фаза 5 — деплой + интеграция.** `[[s1-12-compose-nginx-env-version]]`, `[[s1-13-integration-doc-fixes]]`. Compose/nginx/env/версия; сквозной smoke + фиксы доков. `s1-13` зависит от всех.

**Subcards:**
- `[[s1-01-clean-wire-contract]]` — Option D кастомный Messenger-транспорт → чистый single-JSON в `conv.*`; одинарная декодировка PHP+Python; golden-фикстура.
- `[[s1-02-gateway-keydb-reader]]` — скелет gateway + `config.py` + `keydb.py` (XREADGROUP/XACK/XAUTOCLAIM, db2, `worker:job:{id}` мета) + Dockerfile + `make test-gateway`.
- `[[s1-03-ws-server-dispatch]]` — WS-сервер + auth-граница + фреймы `ready`/`job` + кредитный dispatch (slots:1) + стабильный consumer=workerId + resume_pending.
- `[[s1-04-result-relay-ack]]` — маршрутизация result (inline≤256KB→relay→ack; large→resultKey trust-ack; fail→relay); internal-эндпоинты Symfony; вынос XACK из PHP; удаление публичного `/fail`.
- `[[s1-05-ping-liveness]]` — `ping`/`pong`, N пропущенных → reconnect, возобновление своего PEL (без reclaim).
- `[[s1-06-reclaim-poison-dlq]]` — per-type idle `XAUTOCLAIM` → redispatch; poison-job delivery-count + DLQ (`conv.dead`) через `fail{permanent:true}`.
- `[[s1-07-progress-conv-status]]` — `progress{jobId,percent,stage?}` ~1/сек; gateway пишет `conv:status`; `ConversionStatusReader` без изменений.
- `[[s1-08-shared-ws-client]]` — `workers/common/ws_client.py`: connect/auth/ready/job/completion/ping/progress/reconnect+backoff; seam `ResultSignal`/`handle_job`.
- `[[s1-09-ai-worker-migrate]]` — AI-воркер на общий клиент (унифицированный result, progress, inline/large); удаление `pull_api.py` + poll-цикла + `PULL_ENABLED`; dev-server pull-stats → минимальный WS-stats.
- `[[s1-10-streamconsumer-refactor-unify]]` — разбить `StreamConsumerBase` на переиспользуемый `process_job`; persist через унифицированный relay/API; выпилить `conv.result` + worker `conv:status`; ретайр `QueueResultConsumerCommand`.
- `[[s1-11-onserver-workers-migrate]]` — data/image/ffmpeg/libreoffice на общий клиент; вход + large через API, без прямого S3/KeyDB; progress; ffmpeg = 2 соединения (audio/video).
- `[[s1-12-compose-nginx-env-version]]` — compose-сервис `ws-gateway`, nginx `location /ws/worker/`, Makefile-таргеты, env, запекание `WORKER_VERSION`, ретайр pull-env.
- `[[s1-13-integration-doc-fixes]]` — сквозной smoke по всем воркерам; фиксы доков (`docs/queue-contract.md` §2 single-JSON, имена каналов в CLAUDE.md → `conv.<type>` вкл. `data`).

**Зависимости/взаимодействия:**
- `[[registry-00-self-registration]]` — его Phase 1 сейчас регистрирует воркеров из `StreamConsumerBase.__init__` (AI — через `PullApiClient`). После S1 self-регистрация должна вызываться из базы WS-клиента (`workers/common/ws_client.py`), а не из stream-consumer'а. Скоординировать при пересечении.
- `[[extract-worker-common-helpers]]` — перекрывается: S1 наполняет `workers/common` (`ws_client.py`, `envelope.py`, обобщённый `process_job`). После S1 пересмотреть, что из его scope ещё актуально.
- `[[queue-channel-names-mismatch]]` — **сворачивается** в S1: фикс имён каналов в CLAUDE.md (`conv.<type>` вкл. `data`) выполняется в `[[s1-13-integration-doc-fixes]]`.
- `[[worker-pull-api-live-status-hash]]` — **superseded** S1 → `[[s1-07-progress-conv-status]]` (conv:status пишет gateway). Отдельно не брать.
- `[[worker-pull-api-poison-job-dlq]]` — **superseded** S1 → `[[s1-06-reclaim-poison-dlq]]`. Отдельно не брать.
- `[[align-document-stream-matrix-dlq]]` — **re-scoped** в S1 → `[[s1-06-reclaim-poison-dlq]]`: решение `PermanentError` fast-DLQ переносится, но цель смещается с `StreamConsumerBase` на WS-протокол (`fail{permanent:true}` → gateway DLQ).

**Status:** ready (epic). Все 13 сабкарт (s1-01…s1-13) в `ready/` — ревью пройдено, гейты зелёные (phpstan OK, 101 phpunit/389 assert, test-python 110, test-gateway 100 вкл. 4 интеграционных, test-drift 2, docker-check 0). Эпик функционально закрыт; ждёт финального ready→done + squash-merge ветки `task/s1-ws-transport` (только пользователь). Все сабкарты сделаны в этой ветке без per-task веток.
