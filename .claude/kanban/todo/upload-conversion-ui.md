### Frontend: страница загрузки/конвертации (хостит OCR-тоггл + выбор формата + статус)

**Критичность:** Medium

**TAGS:**
- feature
- frontend

**Описание:**
В проекте пока нет пользовательской страницы конвертации — есть только `templates/base.html.twig`. При реализации [[validate-image-worker]] (2026-06-21) добавлен фрагмент `templates/conversion/_upload_form.html.twig` с OCR-чекбоксом, но он **не подключён ни к одной странице** → визуально OCR (и вообще загрузка/конвертация) пользователю недоступны, хотя API готов (`POST /api/.../convert`, поля `file`, `to_format`, `ocr`).

**Проблема:**
- Нет страницы: drag&drop загрузка, выбор целевого формата (из матрицы реестра), сабмит на `convert`-эндпоинт.
- OCR-тоггл (`_upload_form.html.twig`) не отрендерен — фрагмент-сирота.
- Нет отображения статуса задачи (HTMX-поллинг `conv:status:{id}` / GET статуса) и ссылки на результат.

**Влияние:**
Бэкенд-конвертация (incl. OCR) реально не используется без UI — MVP неполон с точки зрения пользователя.

**Решение (черновик):**
- Страница конвертации на Alpine.js + HTMX + Tailwind (CDN, без npm — по правилам проекта): drag&drop, выбор формата, OCR-тоггл (показывать только для форматов, где он валиден — растр/pdf→txt/md/docx), сабмит.
- HTMX-поллинг статуса + ссылка на результат (presigned S3).
- Подключить/доработать `templates/conversion/_upload_form.html.twig` как фрагмент формы.

**Decisions:**
- Выделено при [[validate-image-worker]] (2026-06-21): OCR-тоггл прошит в API, но UI-хоста нет — вынесено отдельной карточкой по решению «всё в одной карточке» (UI признан вне scope воркер-карточки).
- own `ConversionController::index` (GET /) + Twig ; formats from existing GET /api/v1/formats ; OCR-toggle client-side + backend re-validate ; auth = public + anon rate-limits, Telegram-login for history. Wire the orphan `_upload_form.html.twig`. Note real infra issue: `templates/` is root-owned (resolve for host-side edits).

**Status:** ready (todo).

Siblings: [[validate-image-worker]] · [[docs-admin-panel]]
