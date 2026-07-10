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

- 2026-07-10: старт. Recon: проект API-only, web-контроллера/GET `/` нет; asset-pipeline нет → CDN. `POST /api/v1/convert` и `/status` под `ROLE_USER` (security.yaml:48), `GET /api/v1/formats` — PUBLIC. Статусы: pending/processing/completed/failed/expired; poll `GET /api/v1/convert/{id}/status`. Инфра: `templates/` был root:root — chown'нут на coder.
- 2026-07-10 (решение пользователя): auth = **требовать логин, API как есть** (convert под JWT; НЕ делаем public+ratelimit). Следствие: странице нужен рабочий Telegram Login Widget → этот таск включает рендер виджета из Twig-переменной + прокидку `TELEGRAM_BOT_USERNAME` в Symfony, чем **закрывает [[login-widget-bot-username]]** (запаркованную). После мержа — снять с неё blocked / закрыть.

**Execution Log:**
- 2026-07-10: карта → progress, ветка `task/upload-conversion-ui`. Recon-агент отработал. Диспатч имплементера web-зоны.

- 2026-07-10: имплементер сдал (коммит d3df825, гейт зелёный). Ревью (3 агента, high). Находки: [корр] нет алиасинга расширений (.jpeg/.tif → неконвертируемы), гонка поллинга при повторном сабмите, тупик скачивания по истечении JWT (Bearer null), файл без расширения → мусор, слабый functional-тест; [чистота] HTMX подключён но не используется, копипаст статус-массивов; [altitude] дубль OCR-списков JS vs registry. → фикс-раунд #1-5,#7,#8 в этой же ветке; #6 (ocrCapable в /formats) вынесен в grooming [[formats-api-ocr-capable-flag]].

- 2026-07-10: фикс-раунд #1-5,#7,#8 (коммит 88ed438). Верификация дельты (authFetch/poll-gen) — поймала 1 новый баг: дедлок `canSubmit` при экспирации сессии в поллинге → доправлено (коммит 2ca2f2b: `sessionExpired()`→`resetJob()`). Тимлид перепроверил poll/resetJob вручную — ок. Гейт зелёный (phpstan OK, cs чисто, docker-check 0, HomeControllerTest 4 assertions).
- Коммиты ветки: d3df825 (page) · 88ed438 (review fixes) · 2ca2f2b (deadlock fix).
- Файлы: +`src/Controller/Web/HomeController.php`, +`templates/conversion/index.html.twig`, +`tests/Functional/Controller/Web/HomeControllerTest.php`, ~`config/services.yaml` (bind), ~`.env` (TELEGRAM_BOT_USERNAME), −`templates/conversion/_upload_form.html.twig` (orphan свёрнут).
- Ограничение: e2e логина/конвертации локально не проверить (Telegram Login Widget аутентифицирует только на домене из BotFather `/setdomain`); тест покрывает маршрут + env-bind + рендер + `data-telegram-login` атрибут. Проверять на реальном домене.

**AC:** страница `GET /` (drag&drop, формат-селект из `/api/v1/formats`, OCR-тоггл по (from,to), сабмит на `/convert`, поллинг статуса, скачивание результата, Telegram-логин) — готово. Также закрывает [[login-widget-bot-username]] (виджет с реальным username + env в Symfony).

**Status:** ready — auto-review passed, ждёт финального аппрува пользователя. (done — только пользователь; ветка не мержена.)

Siblings: [[validate-image-worker]] · [[docs-admin-panel]]
