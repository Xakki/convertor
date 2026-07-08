### WS-wiring on-server воркеров в docker-compose + резолв ffmpeg two-routing-key

**Критичность:** Medium

**TAGS:**
- worker
- transport
- ws-client
- docker

**Описание:**
После s1-10 on-server воркеры (data/ffmpeg/image/libreoffice) стали WS-клиентами (`WsClient` + `process_job`), но их сервис-блоки в `docker-compose.yml` всё ещё сконфигурированы под старый redis/S3-транспорт. `WsClientConfig.from_env()` читает `WORKER_TYPE`/`GATEWAY_WS_URL`/`WORKER_API_TOKEN`/`WORKER_ID`; при их отсутствии `WsClient.run()` вызывает `cfg.validate()`, логирует critical и сразу возвращается — контейнер выходит 0 и рестартует в тайт-луп, задачи не берутся. Т.е. до этой карточки on-server воркеры в live-WS режиме не запускаются. Правку compose держим отдельным когерентным изменением (в scope s1-10 её не было — file-list карточки не включал docker-compose).

**Что сделать:**
- Для каждого из 4 сервисов (`worker-libreoffice`, `worker-ffmpeg`, `worker-image`, `worker-data`) в `docker-compose.yml`:
  - Добавить WS-env: `WORKER_TYPE`, `WORKER_ID`, `GATEWAY_WS_URL`, `WORKER_API_TOKEN`, `API_BASE_URL`, `WORK_DIR` (по образцу `worker-ai` / `workers/ai/worker.py`).
  - Удалить мёртвые env: `REDIS_HOST`/`REDIS_PORT`/`REDIS_DB`/`REDIS_QUEUE_DB`/`REDIS_PASSWORD`, `S3_ENDPOINT`/`S3_KEY`/`S3_SECRET`/`S3_REGION`/`S3_BUCKET_PREFIX`/`S3_USE_PATH_STYLE`.
  - Убрать `depends_on: keydb` у воркеров (KeyDB им больше не нужен).

**Дизайн-вопрос (блокирует часть правки — резолвить до compose):**
- **ffmpeg обслуживает 2 routing-key** (`CAPABILITIES["routing_keys"] = ["audio", "video"]`), а `WORKER_TYPE` в `ws_client.py` — один слот из `{ai, document, image, audio, video, data}`. Один контейнер не может зарегистрироваться под два типа при текущем контракте. Варианты: (a) поднять два ffmpeg-контейнера (`WORKER_TYPE=audio` и `=video`); (b) расширить gateway/`WsClient`-регистрацию до multi-type (worker объявляет список routing_keys при `ready`); (c) слить audio+video в один stream/type. Выбрать до написания compose — свериться с контрактом gateway (регистрация воркера, `[[s1-06-reclaim-poison-dlq]]` / `[[s1-07-progress-conv-status]]`).

**Связано:** `[[s1-10-streamconsumer-refactor-unify]]`, `[[s1-08-shared-ws-client]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Open questions:**
- Как gateway ожидает регистрацию воркера с несколькими routing-key (ffmpeg audio+video)? — определяет выбор варианта (a/b/c).

**Status:** ready

**Итог (2026-07-07):** реализовано в `e493ba1` + `a0aa8e2` на `task/s1-ws-transport`. Решение по ffmpeg — **вариант A** (контракт gateway: 1 коннект = 1 `workerType` = 1 stream; multi-type/merge требуют правок gateway+Symfony, вне scope). `worker-ffmpeg` разбит на `worker-ffmpeg-audio` (`WORKER_TYPE=audio`) + `worker-ffmpeg-video` (`WORKER_TYPE=video`) — один образ/код, разный env. Все 5 on-server воркеров (libreoffice=document, image, data, ffmpeg-audio, ffmpeg-video) переведены на WS-env (`WORKER_ID`/`WORKER_TYPE`/`GATEWAY_WS_URL`/`WORKER_API_TOKEN`/`API_BASE_URL`/`WORK_DIR`), сняты dead `REDIS_*`/`S3_*`, сеть `backend` и `depends_on: keydb` (KeyDB им больше не нужен; gateway — по публичному `wss://`, Symfony — `http://nginx` на `default`). `S3_PREFIX` вычищен из e2e-overlay. `WORKER_API_TOKEN=CHANGE_ME` в `.env.local_example`. Гейт: `make docker-check` exit 0. Ревью — APPROVE (limits.yml воркеров не содержал; достижимость подтверждена; секретов нет).
