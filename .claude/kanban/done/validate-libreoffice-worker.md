### LibreOffice воркер — перевод на Streams + S3 (документы без consumer'а — MVP-критично)

**Критичность:** Critical — топ-приоритет Стадии 1 (MVP)

**TAGS:**
- feature
- tech-debt

**Описание:**
LibreOffice-воркер (`workers/libreoffice/main.py`) сейчас — HTTP-прокси-сервис, который PHP дёргает напрямую. Это **не** consumer очереди: в отличие от остальных воркеров (все уже Streams-consumer'ы) он не читает ни Redis-LISTS, ни KeyDB Streams. Содержит реальную конвертацию документов (LibreOffice + pandoc). Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (storage-input-to-s3, 2026-06-20). Документы — core product, поэтому это топ-приоритет MVP.

**Проблема:**
- **Документы/разметка диспатчатся в стрим без consumer'а → задачи копятся непрочитанными.** PHP роутит категории `document` И `markup` в стрим `conv.document`, но **ни один воркер не потребляет `conv.document`**: libreoffice — HTTP-only, ни один Python-воркер не объявляет routing_key `document`. Конвертация документов фактически не выполняется вообще.
- LibreOffice — единственный воркер вне общей очередной модели (HTTP-прокси). Решено: привести к **KeyDB Streams consumer**, как все остальные воркеры (глобальное правило проекта — воркеры только Streams + S3).
- I/O всё ещё рассчитан на удалённый `/shared-files` → перевести на S3.
- Матрица документов/разметки (doc/pdf/md) не провалидирована end-to-end.

**Влияние:**
Документы — core product, а сейчас их конвертация не работает: задачи `conv.document` уходят в стрим без consumer'а и копятся. Перевод LibreOffice на Streams + S3 закрывает дыру и приводит его к общей очередной архитектуре.

**Решение:**
- Переписать LibreOffice-воркер как **KeyDB Streams consumer** для routing_key `document` (категория `markup` сворачивается в `document` — оба роутятся в `conv.document`); убрать HTTP-прокси-модель.
- Перевести I/O на S3: вход из `-inputs`, результат в `-results` (сейчас воркер всё ещё ждёт удалённый том `/share`, которого больше нет).
- Провалидировать конвертации doc/pdf/markup(md) против матрицы форматов `ROADMAP.md` (справочные данные).
- pandoc сохраняется; MarkItDown отложен (зафиксировано в [[add-open-ai]]).

**Зависимости:**
- S3 runtime блокируется [[finish-worker-compose-wiring]]: после переписи на consumer воркеру нужны S3_*-env и egress-сеть (как у эталонного worker-image), иначе до S3 в рантайме не дотянется.

**Отложено в Стадию 7 (вне MVP, см. [[ROADMAP]] / [[post-mvp-conversion-formats]]):**
- **Таблицы / LibreOffice Calc:** `xls, xlsx, ods, csv` → `xlsx, ods, csv, pdf` (категория «Электронные таблицы» в матрице `ROADMAP.md`).
- **Презентации / LibreOffice Impress:** `ppt, pptx, odp` → `pptx, odp, pdf` (категория «Презентации» в матрице `ROADMAP.md`).
- **PDF→jpg постранично** (`pdftoppm`) — «jpg (страницы)» из PDF-операций (категория «PDF» в матрице `ROADMAP.md`).

**Критерии приёмки:**
- LibreOffice работает как **Streams-consumer** routing_key `document` (не HTTP-прокси); стрим `conv.document` потребляется, задачи не копятся.
- I/O идёт через S3: вход из `-inputs`, результат в `-results`; том `/share`/`/shared-files` не используется.
- Конвертации doc/pdf/markup(md) провалидированы против матрицы форматов `ROADMAP.md`; pandoc retained.
- `pytest workers/tests` зелёный.
- `make docker-check` проходит.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- **Поднято до MVP-критичного / топ-приоритета Стадии 1** при ре-груминге 2026-06-20: остальные воркеры уже Streams-consumer'ы, а `conv.document` (document + markup) без consumer'а — документы как core product не конвертируются вообще.
- **Модель: KeyDB Streams consumer** routing_key `document` (markup сворачивается в document), не HTTP — решение пользователя 2026-06-20, глобальное правило «воркеры только Streams + S3».
- **Таблицы / Презентации / PDF→jpg постранично — отложены в Стадию 7** (решение 2026-06-20).
- pandoc сохраняется для docs→markdown; MarkItDown отложен (см. [[add-open-ai]]).
- **epub как ВХОД — урезан до `epub→md` (pandoc); `pages` убран** (решение пользователя 2026-06-21): soffice не умеет ИМПОРТ epub (только экспорт), поэтому остальные epub-source цели рекламировались сломанными. epub остаётся валидной ЦЕЛЬю (soffice экспорт). pages (Apple Pages, libetonyek) — отложен. Расходится с ROADMAP стр. 147 → вынесено в follow-up [[stage7-libreoffice-extra-formats]].

**Execution Log (2026-06-21):**
- Переписан `workers/libreoffice/worker.py`: `LibreOfficeWorker(StreamConsumerBase)`, `routing_keys: [document]`, sync `convert()` по образцу ffmpeg/image/data. Движок по паре (src,target): soffice (office), pandoc (md-цели и md-вход), pdftotext (pdf-вход, цепочка pdf→docx). Flag-agnostic. Удалён HTTP-прокси `main.py` + старые прокси-тесты.
- I/O через S3: вход `_localInput` (база скачивает из `-inputs`), результат база заливает в `-results`. `/share`/`/shared-files` убраны.
- Dockerfile (`docker/workers/libreoffice.Dockerfile`) приведён к sibling-паттерну: non-root, `WORKER_MODULE`, soffice+pandoc+poppler, без EXPOSE/health-HTTP. `docker-compose.yml` `worker-libreoffice`: S3_*-env + сети `default,backend` (egress к S3 — закрывает [[finish-worker-compose-wiring]]-пробел).
- **Ревью (3 финдера + правки):** урезан epub→md / убран pages; устранено двойное копирование файла в RAM (`read_bytes` вход+выход → pass-through + `os.replace`, OOM на 500MB-тарифе); удалены мёртвые наборы `_SOFFICE_SOURCES`/`_MARKUP_SOURCES`.
- **Follow-up (заведены в grooming):** [[align-document-stream-matrix-dlq]] (PHP-реестр рекламирует Stage-7 пары в `conv.document` → медленный DLQ с обобщённой причиной; fast-DLQ для перманентных ошибок) · [[extract-worker-common-helpers]] (вынос `_run`/преамбулы `convert()` в `workers/common`) · [[stage7-libreoffice-extra-formats]] (epub-вход полностью / pages / таблицы / презентации / pdf→jpg).
- **Проверка:** `pytest workers/tests` → 104 passed, 10 skipped (skips = интеграция на реальных бинарях, нет на хосте — пойдут в контейнере/CI). `make docker-check` → EXIT 0. Реальные конвертации на хосте НЕ прогонялись (нет soffice/pandoc) — интеграционные тесты skip-gated.

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-ai-worker]]
