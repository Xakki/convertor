### Chunked-загрузка файлов при client_max_body_size 3 МБ (анти-DDoS)

**Criticality:** High

**TAGS:**
- feature
- security
- backend
- frontend

**Description:**
Лимит размера тела входящего запроса (`client_max_body_size`) сокращается до
**3 МБ** — чтобы одиночный запрос не мог занять канал/диск/память и не давал
дешёвый вектор DDoS. Плановые лимиты размера файла при этом НЕ меняются:
`free` 50 МБ, `basic` 200 МБ, `pro` 500 МБ (`plans.max_file_size_mb`, проверено
в БД 2026-08-17). Следствие: файл больше 3 МБ физически не может приехать одним
`POST /api/v1/convert` — нужна загрузка **частями**. Для фронта — chunked-загрузка;
для API-клиентов механизм ещё не выбран (см. Open questions).

Текущее состояние nginx (всё в этом репозитории):
- `docker/nginx/dev/conf.d/default.conf:40` и `docker/nginx/prod/conf.d/default.conf:35`
  — `client_max_body_size 512M`;
- там же (`:50` / `:49`) — `fastcgi_param PHP_VALUE "upload_max_filesize = 512M
  \n post_max_size = 512M"`;
- `docker/nginx/params/http_params:13` — `1M` (дефолт для остальных location);
- `docker/nginx/params/s3_location:20` — `32M`;
- `docker/nginx/params/websocket_location:19` — `5M`;
- шаренный фронт saFin (`shared-nginx-1`, `/home/soft/shared/nginx/`) — **вне
  этого репозитория**, свой лимит, требует отдельной правки.

Где сейчас живёт приём и лимит:
- `app-symfony/src/Controller/Api/ConversionController.php` — `POST /api/v1/convert`
  (multipart `file` XOR `text`+`source_format`);
- `app-symfony/src/Service/Conversion/ConversionManager.php:622` — enforcement
  лимита плана (`413`);
- `app-symfony/src/Service/Quota/QuotaService.php:325` — `maxUploadBytes(User)`,
  фолбэк `FREE_MAX_UPLOAD_MB = 50` (строка 39);
- `app-symfony/src/Service/Storage/S3Storage.php` — обёртка над S3.

**Problem:**
Сейчас 3 МБ и плановые лимиты 50/200/500 МБ несовместимы: как только
`client_max_body_size` опустится до 3 МБ, всё крупнее начнёт отбиваться **nginx-ом
без JSON-тела** — пользователь получит пустой `413` без объяснения, а плановые
лимиты станут недостижимыми. То есть chunked-загрузка — это не улучшение, а
**предусловие** сохранения текущих тарифных лимитов.

Дополнительно: проект сознательно НЕ использует presigned-URL — скачивание
результатов стримится через бэкенд (явно зафиксировано в комментариях
`app-symfony/src/Controller/Api/ExampleController.php:29-30` и
`MeController.php:20`). Presigned **upload** будет отклонением от этой
конвенции — решение должно быть осознанным, а не «заодно».

**Impact:**
Без нового механизма: снижение `client_max_body_size` ломает загрузку файлов
крупнее 3 МБ для всех, включая платные планы (регресс, а не защита). Если же
оставить 512 МБ — сохраняется дешёвый вектор перегруза: один запрос забивает
канал и диск воркера/PHP.

**Recommendation:**
Порядок работ (снижение лимита — ПОСЛЕДНИМ шагом, иначе прод ломается):
1. Выбрать механизм для API (Open questions) и зафиксировать контракт загрузки.
2. Реализовать приём частями на бэкенде + чистку незавершённых загрузок по TTL.
3. Перевести фронт на chunked-загрузку с прогрессом и retry по части.
4. Проверить лимит плана НА ВХОДЕ (по заявленному размеру) и ФАКТИЧЕСКИ (по
   собранному объекту) — заявленному размеру от клиента доверять нельзя.
5. Только после этого опустить `client_max_body_size` до 3 МБ в
   `docker/nginx/{dev,prod}` и на шаренном фронте saFin; `upload_max_filesize` /
   `post_max_size` привести в соответствие.
6. Обновить внешний контракт: OpenAPI + **глобальный скил `convertor-api`**
   (`~/.claude/skills/convertor-api/`) — там зафиксирован старый способ загрузки
   одним multipart-запросом.

**Варианты механизма для API (на выбор, см. Open questions):**

- **A. Presigned multipart upload прямо в S3.** `POST /api/v1/uploads` отдаёт
  `upload_id` + presigned URL на части; клиент льёт части в `apis3.xakki.ru`
  (S3 Multipart Upload), затем `POST /api/v1/convert` со ссылкой на готовый ключ.
  *+* тело через PHP/nginx вообще не идёт, 3 МБ не мешает; чанки нативные,
  масштабируется до гигабайт; готовые SDK (boto3/aws-sdk/mc).
  *−* отклонение от «никаких presigned» конвенции проекта; валидация размера/MIME
  только ПОСЛЕ загрузки (`HeadObject` + сниф байт); нужен GC брошенных
  multipart-загрузок; presigned-URL — новая поверхность атаки.
- **B. Свой chunked-upload API через PHP.** `POST /api/v1/uploads` (init) →
  `PUT /api/v1/uploads/{id}/parts/{n}` частями ≤3 МБ → `POST
  /api/v1/uploads/{id}/complete` → `upload_id` передаётся в `/convert`. Сервер
  стримит части в S3 multipart.
  *+* авторизация как сейчас (JWT/guest), S3-креды не покидают сервер, один и тот
  же механизм для фронта и API, квоту и лимит можно резать по мере приёма.
  *−* 500 МБ при частях по 3 МБ = ~170 запросов через PHP-FPM (нагрузка,
  rate-limit `user_convert` 120/час придётся разделить); нужно состояние
  загрузки + TTL-чистка; retry/идемпотентность по частям.
- **C. Pull-by-URL: сервер сам качает источник.** `POST /api/v1/convert` с
  `source_url`; тело запроса — сотни байт.
  *+* для агентов/интеграций часто самый удобный путь; тело крошечное.
  *−* SSRF/egress-policy (смежная работа — `CNV-89`, `CNV-114`, но там URL для
  browser-конвертации, а не как источник произвольного файла); не годится для
  локального файла у клиента; лимит размера надо резать в процессе скачивания.
- **D. Гибрид (предпочтительный по трудозатратам):** B как базовый механизм
  (один код на фронт и API) + C как удобство для интеграций; A — только если
  замеры покажут, что ~170 запросов через PHP неприемлемы.

**Acceptance Criteria:**
- `client_max_body_size` ≤ 3 МБ во всех location этого репозитория И на шаренном
  фронте saFin; `upload_max_filesize` / `post_max_size` согласованы.
- Файл 500 МБ реально конвертируется e2e для `pro` через новый механизм.
- Файл, превышающий лимит плана, отбивается **приложением** с JSON-телом
  (`413`/`422`), а не пустым `413` от nginx; проверка не доверяет заявленному
  клиентом размеру.
- Незавершённые/брошенные загрузки удаляются по TTL (в т.ч. брошенные
  S3-multipart, если выбран вариант A).
- Старый однозапросный `POST /api/v1/convert` продолжает работать для файлов
  ≤ 3 МБ и для `text`-входа (обратная совместимость).
- Фронт: прогресс загрузки, retry отдельной части, корректная отмена.
- Обновлены OpenAPI (`/api/doc.json`) и глобальный скил `convertor-api`.
- Tests/QA green: `make phpstan`, `make cs-check`, `make test`.

**Open questions:** *(only for `grooming/` cards — fold each resolution into **Decisions:** below, then remove this section before moving to `todo/`)*
- Какой вариант механизма для API: A / B / C / D?
- Размер части: 2 МБ (запас под overhead multipart в 3 МБ лимите) или ровно 3 МБ
  с поднятием лимита конкретно на upload-endpoint?
- Где держать состояние незавершённой загрузки: KeyDB (TTL из коробки) или
  таблица в MariaDB (видно в админке, но нужна своя чистка)?
- Разрешать ли guest-сессии chunked-загрузку (сейчас гостю доступно 50 МБ), или
  крупные файлы — только для аутентифицированных?
- Считать ли одну chunked-загрузку одним запросом для rate-limit, или части
  вынести в отдельный лимитер?

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- 2026-08-17, @user: **плановые лимиты НЕ меняем** — free 50 / basic 200 /
  pro 500 МБ остаются как есть; исходная постановка «увеличить размер для
  подписки» снята.
- 2026-08-17, @user: `client_max_body_size` **сокращаем до 3 МБ** — защита от
  DDoS и перегруза сервера.
- 2026-08-17, @user: загрузка файлов **частями**; для фронта — chunked-загрузка.
- Заводится отдельной карточкой: живых карточек по теме нет, смежные
  (`CNV-28-pay-per-use-credits`, `CNV-30-plan-quota-daily-monthly`,
  `upload-mime-size-validation`) уже в `done/`.

**Execution Log:** *(add concise, secret-free evidence after work starts)*
- Authorization: explicit user approval at hand-off or recorded EPIC-scoped upfront autonomous authorization
- Agent/zone: <owner and zone>; Gate: `<command>` → <result>
- Reviewer: <verdict>; Commit: <SHA>
