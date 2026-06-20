### LibreOffice воркер — определить модель (Streams vs HTTP) + валидация doc/pdf/markup

**Критичность:** High

**TAGS:**
- feature
- tech-debt

**Описание:**
LibreOffice-воркер (`workers/libreoffice/main.py`) сейчас — HTTP-прокси-сервис, который PHP дёргает напрямую. Это **не** consumer очереди: в отличие от остальных воркеров он не читает ни Redis-LISTS, ни KeyDB Streams. Содержит реальную конвертацию документов (LibreOffice + pandoc). Файлы теперь только в S3 (`${S3_BUCKET_PREFIX}-inputs` / `-results`), общий том `/shared-files` удалён (storage-input-to-s3, 2026-06-20).

**Проблема:**
- Архитектурная развилка: остальные воркеры идут к единой модели «KeyDB Streams consumer», а LibreOffice — отдельный HTTP-сервис. Нужно решить, приводить ли его к общей модели или оставить HTTP-сервисом, который PHP зовёт напрямую (см. Open questions).
- I/O рассчитан на `/shared-files`, которого больше нет → нужно перевести на S3 в любом из вариантов.
- Матрица документов/разметки (doc/pdf/md) не провалидирована end-to-end.

**Влияние:**
Без решения по модели LibreOffice остаётся вне общей очередной архитектуры (особый случай в оркестрации). Без перевода на S3 конвертация документов сломана (нет `/shared-files`).

**Решение:**
- Принять решение по модели (Open questions) и зафиксировать в Decisions.
- В любом варианте перевести I/O на S3: вход из `-inputs`, результат в `-results`.
- Провалидировать конвертации doc/pdf/markup(md) против матрицы `docs/plan.md`.
- pandoc сохраняется; MarkItDown отложен (зафиксировано в [[add-open-ai]]).

**Кандидаты в scope (matrix-advertised, тот же движок soffice) — решить MVP vs defer при груминге этой карточки:**
- **Таблицы / LibreOffice Calc:** `xls, xlsx, ods, csv` → `xlsx, ods, csv, pdf` (`docs/plan.md` строка 26).
- **Презентации / LibreOffice Impress:** `ppt, pptx, odp` → `pptx, odp, pdf` (`docs/plan.md` строка 27).
- **PDF→jpg постранично** (`pdftoppm`) — «jpg (страницы)» из PDF-операций (`docs/plan.md` строка 14).

**Критерии приёмки:**
- Модель LibreOffice определена (Streams-consumer **или** HTTP-сервис) и зафиксирована в Decisions; реализация соответствует выбору.
- I/O идёт через S3: вход из `-inputs`, результат в `-results`; `/shared-files` не используется.
- Конвертации doc/pdf/markup(md) провалидированы против `docs/plan.md`; pandoc retained.
- `pytest workers/tests` зелёный (где применимо к выбранной модели).
- `make docker-check` проходит.

**Open questions:**
- **Модель воркера не определена.** Сделать LibreOffice **Streams-consumer** как остальные (единая очередная модель) **или** оставить **HTTP-сервисом**, который PHP вызывает напрямую (минимум изменений, но особый случай вне очереди)? От решения зависит, как PHP-сторона ставит задачи документов и как считается их статус.
- **MVP-приоритет matrix-advertised кандидатов (тот же движок soffice).** Включать ли в этот воркер таблицы (Calc), презентации (Impress) и PDF→jpg постранично (`pdftoppm`) в MVP или вынести в defer? Решить при груминге.

**Decisions:**
- Выделено из эпика [[docs-workers-conversion-validation]] при груминге 2026-06-20 (split per-worker).
- pandoc сохраняется для docs→markdown; MarkItDown отложен (см. [[add-open-ai]]).

Siblings: [[validate-ffmpeg-worker]] · [[validate-image-worker]] · [[validate-data-worker]] · [[validate-ai-worker]]
