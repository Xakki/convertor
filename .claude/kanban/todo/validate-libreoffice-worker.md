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

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-ai-worker]]
