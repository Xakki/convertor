### S1-11 — Миграция on-server-воркеров на общий WS-клиент

**Критичность:** High

**TAGS:**
- worker
- transport

**Описание:**
Перевести четыре on-server-воркера — **data**, **image**, **ffmpeg** (audio+video), **libreoffice** (document) — на общий WS-клиент `[[s1-08-shared-ws-client]]` поверх transport-agnostic `process_job` из `[[s1-10-streamconsumer-refactor-unify]]`. Воркеры перестают читать `conv.<type>` напрямую, лишаются S3-кред и подключения к KeyDB; вход — через `GET /jobs/{id}/input`, большой результат — через `POST /jobs/{id}/result`; эмиссия `progress` по ходу. Воркеры остаются flag-agnostic (используют только `sourceFormat`/`targetFormat`).

**ffmpeg = ДВА WS-соединения (§4/§6.1):** обслуживает `audio` + `video` → открывает **отдельное соединение на каждый тип**, каждое со **своим `workerId`** (напр. `ffmpeg-audio`, `ffmpeg-video`) → непересекающиеся PEL. Одно соединение = один `workerType` = один stream.

**Файлы:**
- Изменить: `workers/data/worker.py`, `workers/image/worker.py`, `workers/ffmpeg/worker.py`, `workers/libreoffice/worker.py` (на `ws_client` + seam `handle_job`; конвертация через `process_job`).
- Изменить: соответствующие `__main__.py` / entrypoint'ы (ffmpeg — 2 соединения audio/video, каждое со своим `WORKER_ID`).
- Изменить: `workers/*/config.py` при наличии (WORKER_ID/GATEWAY_WS_URL/WS-tunables; убрать прямые S3/KeyDB-креды).
- Изменить: `workers/tests/` — по одному фейковому WS-клиенту на тип + двойное соединение ffmpeg.

**Критерии приёмки:**
- Каждый из data/image/ffmpeg/libreoffice подключается по WS, `ready` со своим `workerType`, обрабатывает засеянную задачу своего `conv.<type>`, возвращает результат (inline/large), эмитит `progress`.
- Grep: НИ один воркер не импортирует/не использует S3 или KeyDB напрямую; вход только через `GET /jobs/{id}/input`; нет прямого чтения `conv.<type>` и self-XACK.
- ffmpeg держит ДВА соединения (`audio` + `video`) с разными `workerId` и непересекающимися PEL (ассерт).
- Маршрутизация: `workerType:"image"` получает только `conv.image` и т.д.
- `pytest workers/tests` зелёный.

**Зависит от:** `[[s1-10-streamconsumer-refactor-unify]]`, `[[s1-08-shared-ws-client]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready

**Реализация:**
- data/image/libreoffice уже на WS-транспорте через `StreamConsumerBase.run()` (строит `WsClient(cfg, self.process_job)`, s1-10) — миграция на уровне базы, дублей не плодили.
- Создан `workers/ffmpeg/__main__.py` — dual-connection entry point: `build_dual_configs()` (worker_id/type = `ffmpeg-audio`/`ffmpeg-video`, непересекающиеся PEL) + `run_dual()` (два `WsClient` поверх одного `FfmpegWorker.process_job`, каждый со своим `httpx.AsyncClient`). `workers/ffmpeg/worker.py` `__main__` делегирован в `__main__.main()`.
- WS-тесты разбиты по воркерам: `test_{data,image,ffmpeg,libreoffice}_ws_transport.py` + общий `ws_helpers.py` (FakeGateway); ffmpeg — dual-connection disjoint-ids assert; у каждого — ready-frame + inline-result тест.
- Grep-clean: ни один on-server воркер не импортирует/не использует boto3/minio/redis/keydb; вход только `GET /jobs/{id}/input`; нет прямого чтения `conv.<type>` и self-XACK.

**Ревью (reviewer-s11):** APPROVE-WITH-NITS → все 3 нита закрыты (коммит `f581da8`): N1 — добавлены job-dispatch+inline-result тесты для image/ffmpeg-audio/libreoffice; N2 — устаревший docstring «downloaded from S3» в ImageWorker; N3 — удалена осиротевшая фикстура `mock_redis`.

**Гейт:** `make test-python` (из корня) — зелёный, exit 0. Per-worker: data 97, ffmpeg 18 (вкл. dual-connection), image 33 (+1 xfailed), libreoffice 31, ai 110, metrics — все passed.
