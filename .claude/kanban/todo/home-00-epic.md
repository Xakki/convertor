### Редизайн главной страницы (лендинг) — EPIC

**Criticality:** High

**TAGS:**
- feature
- frontend
- backend
- epic

**Description:**
Сейчас `GET /` (`App\Controller\Web\HomeController::index()` →
`app-symfony/templates/conversion/index.html.twig`, extends
`templates/base.html.twig`) — это голая форма загрузки: drag&drop-зона,
исходный формат авто-определяется из расширения файла (read-only), целевой
формат — `<select>` из `GET /api/v1/formats`, чекбокс OCR (условно), кнопка
отправки, карточка статуса с поллингом `GET /api/v1/convert/{id}/status`
(2с), кнопка скачивания, история «Мои конвертации» (для залогиненных). Нет
хедера/шапки, hero-блока, фич, футера, матрицы форматов, медиапревью, легал-
страниц, переключения языка. `templates/base.html.twig` — минимальный
Symfony-скелет без header/footer-блоков. ROADMAP Stage 5 явно фиксирует
лендинг как un-carded («Лендинг … карточки нет — завести») — этот эпик
закрывает пробел.

Есть статичный HTML-мок `app-front/*.html` (index.html — hero/features/CTA,
components/header.html — sticky nav с quota-бейджем и ссылкой на админку,
components/footer.html — заглушки легал-ссылок и ссылка на бота) — он **не
раздаётся** никаким сервисом, это чисто визуальный референс. Переносим
ЗАМЫСЕЛ в Twig + Alpine + Tailwind-CDN, само `app-front/` как отдельный
сервис не подключаем.

**Problem:**
Главная страница не выполняет функцию лендинга: нет объяснения, что за
сервис, какие форматы поддерживаются, нет примеров результатов, нет
предпросмотра медиа-результатов, нет легал-страниц (privacy/terms/cookie-
consent — сейчас футера вообще нет, ссылки в моке ведут на `#`), нет
интернационализации (весь текст — хардкод на русском), вход через Telegram
подан как единственная кнопка вместо унифицированного входа через уже
готовую `/login`.

**Impact:**
Пропущенная функциональность Stage 5: сервис выглядит как голая форма,
непонятен новому пользователю, не масштабируется на не-русскоязычную
аудиторию, отсутствие легал-страниц — комплаенс-риск (GDPR/cookie-consent).

**Recommendation:**
Девять подзадач одним эпиком, ветка `epic/home`, **порядок выполнения**
(зависимости, не порядковые номера файлов):

1. **[[home-07-i18n]]** — фундамент: Symfony translator, детект локали по
   `Accept-Language` + cookie, переключатель языка. ДЕЛАЕТСЯ ПЕРВОЙ, чтобы
   остальные подзадачи сразу заводили ключи переводов, а не хардкодили текст.
2. **[[home-01-header-nav]]** — хедер/навигация: `Войти` → `/login`, докс,
   админка, бот.
3. **[[home-02-text-input]]** — текстовый ввод без файла (`POST /api/v1/
   convert` принимает файл ИЛИ текст).
4. **[[home-03-conversion-variants]]** — витрина вариантов конвертации
   (категории/форматы).
5. **[[home-04-format-info-examples]]** — блок «форматы + лимиты» и реальные
   примеры результатов из S3 (нужна seed-команда).
6. **[[home-05-file-viewers]]** — инлайн-превью результатов (audio/video/
   image/text/PDF), остальное — только «Скачать».
7. **[[home-06-legal-docs]]** — privacy policy, terms of use, cookie-consent
   баннер (нужен i18n для EN/RU).
8. **[[home-08-login-providers-by-locale]]** — провайдеры логина по локали
   (RU → Yandex+VK; остальное → Google+GitHub+Telegram) — нужна серверная
   локаль из i18n.
9. **[[home-09-seo-conversion-pages]]** — SEO-лендинги под конкретные пары
   конвертации (`/convert/{source}-to-{target}`) + dropdown «Конвертации» в
   хедере. ДЕЛАЕТСЯ ПОСЛЕДНЕЙ — зависит от i18n, хедера и данных/примеров из
   [[home-03-conversion-variants]]/[[home-04-format-info-examples]].

**Acceptance Criteria:**
- Все 9 подзадач выполнены и смёржены в `epic/home`.
- Главная страница даёт: понятный hero/объяснение сервиса, вход через
  `/login` (без popup), витрину форматов с реальными примерами, инлайн-
  превью результатов там, где это уместно (see [[home-05-file-viewers]]),
  переключение языка EN/RU, легал-страницы.
- Анонимный guest-cookie флоу конвертации не сломан ни одной подзадачей.
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
- **D1 (логин, [[home-01-header-nav]]):** хедер заменяет текущую кнопку
  Telegram на ссылку/кнопку «Войти», ведущую на СУЩЕСТВУЮЩУЮ `/login`.
  `/login` уже полностью готова (VK/Yandex/Google/GitHub OAuth + Telegram
  magic-link, RU-first порядок) — auth НЕ переделываем, без popup.
  Анонимный guest-cookie флоу конвертации остаётся как есть.
- **D2 (скоуп):** ОДИН эпик `home`, одна ветка `epic/home`, все 9 подзадач в
  нём (пользователь выбрал «всё сразу»).
- **D3 (примеры, [[home-04-format-info-examples]]):** реальные примеры
  конвертаций генерируются ОДИН РАЗ заранее и складываются в S3, страница
  показывает их оттуда (presign/inline). Курируемый небольшой набор — по
  одной показательной паре source→target на категорию конвертации. Нужна
  Symfony console-команда / make-таргет, прогоняющий эти конвертации через
  реальный пайплайн и пишущий результаты в стабильный S3-префикс (например
  `examples/<category>/…` в бакете результатов `${S3_BUCKET_PREFIX}-results`).
  Все S3-операции — через MCP `minio` по правилам проекта; фронт обращается
  через presign либо anonymous-get.
- **D4 (превью, [[home-05-file-viewers]]):** LIGHT MVP. audio/video → HTML5
  `<audio>/<video>`; image/text/PDF → инлайн-попап (изображения через
  object URL/presigned; текст — существующий XSS-safe подход `x-text`,
  ≤64KB, без HTML-инъекций; PDF — через `<embed>`/`<iframe>` на presigned
  URL); ОСТАЛЬНЫЕ документы (docx/xlsx/pptx/odt/…) → только кнопка
  «Скачать», БЕЗ инлайн-превью, БЕЗ серверного рендеринга.
- **D5 (текстовый ввод, [[home-02-text-input]]):** `POST /api/v1/convert`
  принимает ЛИБО загруженный файл, ЛИБО поле `text` + `source_format`
  (взаимоисключимо — оба или ни одного → `400`) — материализация текста в
  файл для пайплайна происходит НА СЕРВЕРЕ, воркеры не меняются
  (flag-agnostic). Заменяет прежнее решение «клиент синтезирует `File`-объект
  из текста, бэкенд не трогаем» — от него отказались, т.к. пользователь
  пересмотрел дизайн в пользу явного серверного текстового входа.
- **i18n ([[home-07-i18n]]):** EN по умолчанию + RU. Локаль на первом визите
  определяется по заголовку запроса `Accept-Language` (НЕ User-Agent — в нём
  нет языка), выбор сохраняется в cookie; виден переключатель языка.
  Symfony translator + `translations/messages.<locale>.xlf` (или `.yaml`);
  хардкод RU-строк из Twig/inline-JS выносится в ключи перевода. На момент
  написания эпика в проекте НЕТ ни `config/packages/translation.yaml`, ни
  каталога `translations/`, ни `framework.default_locale` /
  `set_locale_from_accept_language` в `config/packages/framework.yaml` —
  всё это заводится с нуля в [[home-07-i18n]].
- **[[home-08-login-providers-by-locale]] (провайдеры логина по локали):**
  локаль RU → показываем ТОЛЬКО Yandex + VK; любая другая локаль → Google +
  GitHub + Telegram. Применяется и к `templates/auth/login.html.twig`, и к
  пункту входа в хедере. Зависит от серверной локали из i18n. Сейчас
  `LoginController`/`login.html.twig` рендерят все 4 провайдера БЕЗ
  условий — это меняется.
- **Хедер-ссылки ([[home-01-header-nav]]):** докс → `/api/doc` (Swagger UI
  от NelmioApiDocBundle, роут уже существует —
  `app-symfony/config/routes/nelmio_api_doc.yaml`); админка → `/admin`
  (`App\Controller\Admin\AdminController`, роут `admin_dashboard`, видна
  только для `ROLE_ADMIN`); бот → deep-link `t.me/<TELEGRAM_BOT_USERNAME>`
  (env уже есть, значение `anyconvertor_bot`).
- **Хедер получает dropdown «Конвертации» ([[home-01-header-nav]] →
  [[home-09-seo-conversion-pages]]):** пункт навигации с выпадающим списком
  ссылок на SEO-страницы конкретных пар конвертации
  (`/convert/{source}-to-{target}`), заводимые в [[home-09-seo-conversion-
  pages]]. Список — курируемый показательный подсписок пар (не полная
  матрица форматов).

**Что уже готово (не переделывать):**
- OAuth/login UI — `/login` (`App\Controller\Web\LoginController`,
  `templates/auth/login.html.twig`): все 4 провайдера (Google/GitHub/
  Yandex/VK) + Telegram magic-link, RU-first порядок. Меняется ТОЛЬКО набор
  видимых провайдеров по локали ([[home-08-login-providers-by-locale]]), не
  сам флоу.
- `GET /api/v1/formats` (публичный, `App\Service\Conversion\
  ConversionRegistry`) — источник данных о форматах/категориях/AI-флаге для
  [[home-03-conversion-variants]] и [[home-04-format-info-examples]] — новый
  бэкенд для самих данных не нужен.
- Админка `/admin` (`App\Controller\Admin\AdminController`) — только
  ссылка из хедера, без изменений в самой админке.
- Swagger UI `/api/doc` (NelmioApiDocBundle) — только ссылка из хедера.
- Анонимная конвертация по guest-cookie (`ROLE_GUEST`) и гейтинг ai/video —
  контракт см. skill `redesign-auth-access-contract`, не трогаем, только
  сохраняем при рефакторинге хедера/страницы.
- Соседняя открытая карточка `todo/user-dashboard-page.md` строит полноценную
  `/dashboard` — история/квоты/аккаунт там НЕ дублируются в этом эпике;
  после появления `/dashboard` переиспользовать её хедер/nav (кросс-ссылка
  в [[home-01-header-nav]]).

**Ветка:** `epic/home` (все 9 подзадач коммитятся в неё; PR/мёрж в `main` —
после того, как все 8 закрыты и `make test`/`make phpstan`/`make cs-check`
зелёные).

**Хэндофф:** каждая подзадача при завершении оставляет Execution Log с тем,
какие Twig-ключи/маршруты/файлы затронуты, чтобы следующая по цепочке
подзадача не переоткрывала контекст заново. Тимлид эпика сверяет фактическое
поведение анонимного guest-флоу после каждой подзадачи (регрессия
недопустима).
