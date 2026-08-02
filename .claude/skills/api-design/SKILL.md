---
name: api-design
description: Convertor REST API conventions — /api/v1 prefix and versioning, JSON request/response, error shape, JWT Bearer auth (LexikJWTAuthenticationBundle), OpenAPI docs via NelmioApiDocBundle, guest vs user vs admin access. Use when adding/changing an endpoint under src/Controller/Api or src/Controller/Admin/Api, touching security.yaml firewalls/access_control, or wiring OpenAPI attributes.
---

# API Design — Convertor REST API

Источник истины по факту — код. Ниже сведена карта того, что реально есть,
плюс конвенции, которых нужно держаться при добавлении новых эндпоинтов.

**Перед тем как полагаться на факт отсюда — проверь его по указанному
источнику (роуты/контроллеры/конфиг); нашёл расхождение — исправь этот
файл в том же изменении и сообщи тимлиду.**

## Конвенции

- **Префикс и версия**: весь публичный API — под `/api/v1/` (`#[Route('/api/v1...')]`
  на классе контроллера). Новой major-версии пока нет — если появится, второй
  префикс `/api/v2/` живёт рядом, старый не удаляется без миграционного плана.
- **Формат**: JSON запрос/ответ (кроме `POST /convert` и `POST /jobs/{id}/result` —
  `multipart/form-data` для загрузки файла; `GET .../download|source|preview` —
  бинарный/текстовый stream).
- **Форма ошибки**: `{"error": "<code-or-message>"}`, иногда с доп. `"message"`
  (напр. `AuthRequiredException` → `{"error": "auth_required", "message": "..."}`,
  см. `src/Exception/AuthRequiredException.php`). Глобального exception-listener
  нет — каждый контроллер сам ловит доменные исключения и решает HTTP-код
  (см. `ConversionController::convert()`, строки ~139-158). Новый эндпоинт должен
  следовать этому же паттерну: `{"error": ...}` + осмысленный HTTP-статус
  (400/401/403/404/409/422/429/500), не голый 500 с трассой.
- **Роутинг** — PHP-атрибуты `#[Route(...)]` на классе (базовый префикс) и на
  методе (суффикс + `methods:`), не YAML/XML роуты.
- **Rate limit (CNV-34)** — счётчики в `cache.app` (KeyDB DB0), конфиг
  `config/packages/rate_limiter.yaml`. Грубий пол `api_ip` 300/мин —
  `ApiIpRateLimitListener` на `/api/v1/*` (исключения: formats/examples/
  internal/worker/webhook/auth-start). Convert/quota — `ApiRateLimiter`
  (guest: `anon_*` по IP + `guest:{id}`; ROLE_USER: `user_*` по IP +
  `user:{id}`). 429: `{"error":"..."}` + `Retry-After`. Источник чисел —
  yaml, не этот абзац.

## Аутентификация и доступ

- **JWT Bearer** — `LexikJWTAuthenticationBundle`, конфиг
  `app-symfony/config/packages/lexik_jwt_authentication.yaml`: TTL токена 3600с
  (`token_ttl: 3600`), claim пользователя — `sub` (`user_id_claim: sub`), ключи
  из `JWT_SECRET_KEY`/`JWT_PUBLIC_KEY`/`JWT_PASSPHRASE`.
- **Refresh** — отдельный opaque refresh-token (httpOnly cookie, ротация в Redis),
  `POST /api/v1/auth/refresh` и `POST /api/v1/auth/logout`
  (`src/Controller/Api/AuthController.php`). Фронт после логина получает access-JWT
  через `POST /api/v1/auth/refresh`.
- **Firewalls** (`config/packages/security.yaml`, порядок важен — первое
  совпадение по `pattern` побеждает):
  - `^/api/v1/auth` — `security: false` (публичный, сам эндпоинт выдаёт токены).
  - `^/api/v1/internal` — кастомный `GatewayInternalAuthenticator` (только gateway).
  - `^/api/v1/worker` — кастомный `WorkerAuthenticator` (только воркеры, статичный
    bearer-токен, НЕ пользовательский JWT).
  - `^/api/v1/telegram/webhook` — `security: false` (защита — заголовок
    `X-Telegram-Bot-Api-Secret-Token` внутри контроллера).
  - `^/api` (catch-all) — `jwt: ~` + кастомный `GuestAuthenticator` ПОСЛЕ jwt
    (нет валидного Bearer → аутентифицирует как guest-User по cookie `guest_id`).
  - `role_hierarchy`: `ROLE_USER` наследует `ROLE_GUEST`, `ROLE_ADMIN` наследует
    `ROLE_USER`.
    - `access_control` (см. файл целиком для точного порядка): `/api/v1/formats` —
    `PUBLIC_ACCESS`; `/api/v1/admin` — `ROLE_ADMIN`; `/api/v1/convert/\d+/retry`
    и `DELETE /api/v1/convert/\d+` — `ROLE_USER` (ДО guest-правила);
    `/api/v1/convert`, `/api/v1/quota` — `ROLE_GUEST`; `/api/v1/me` —
    `PUBLIC_ACCESS` на уровне firewall (контроллер сам отдаёт чистый 401 гостю);
    остальной `/api` — `ROLE_USER`.
  - Гейтинг `ai`/`video` для гостя (403 `auth_required`) — НЕ на firewall, а
    внутри `ConversionController::convert()`.
- **Полный флоу логина через Telegram** (pairing+poll, nonce-cookie, webhook,
  merge истории гостя) — здесь НЕ дублируется, см. скилл
  `redesign-auth-access-contract`.

## Карта эндпоинтов (по факту кода — сверяй при драфте нового)

Источник: `grep -rn '#\[Route' app-symfony/src/Controller/`.

**`src/Controller/Api/AuthController.php`** (`/api/v1/auth`, `security: false`):
`POST /refresh`, `POST /logout`, `POST /sms/request`, `POST /sms/verify` (SMS —
пока заглушка, 501).

**`src/Controller/Api/TelegramLoginController.php`** (`/api/v1/auth/telegram`):
`POST /start`, `GET /poll`.

**`src/Controller/Api/OauthController.php`** (`/api/v1/auth/oauth`, под firewall `auth`
`security: false`, доп. явный `access_control` на `^/api/v1/auth/oauth` → `PUBLIC_ACCESS`;
мультипровайдерный OAuth-логин google/github/yandex/vk, эпик `oauth-00`, детали флоу —
скилл `redesign-auth-access-contract`): `GET /{provider}/start`, `GET /{provider}/callback`.

**`src/Controller/Api/TelegramWebhookController.php`** (`/api/v1/telegram/webhook`):
`POST ''` (webhook-приёмник от Telegram Bot API).

**`src/Controller/Api/MeController.php`** (`/api/v1`, `ROLE_GUEST`/`PUBLIC_ACCESS`
на firewall, 401 в контроллере для чистого гостя):
`GET /me`.

**`src/Controller/Api/ConversionController.php`** (`/api/v1`, `ROLE_GUEST` для
`convert`/`quota`/`history`/status/download; гейт ai/video — в контроллере;
`POST /convert/{id}/retry` и `DELETE /convert/{id}` — только `ROLE_USER`,
firewall rules ДО guest-префикса + `#[IsGranted('ROLE_USER')]`):
`POST /convert`, `POST /convert/{id}/retry` (CNV-8: новая строка + S3 copy
исходника, 410 если gone), `DELETE /convert/{id}` (CNV-8: hard delete + S3),
`GET /convert/{id}/status`, `GET /convert/{id}/download`,
`GET /convert/{id}/source`, `GET /convert/{id}/preview`, `GET /convert/history`,
`GET /formats` (`PUBLIC_ACCESS`), `GET /quota`.

**`src/Controller/Api/WorkerController.php`** (`/api/v1/worker`, firewall
`WorkerAuthenticator`, статичный bearer воркера, НЕ выставляется в Nelmio):
`POST /register`, `GET /jobs/{jobId}/input`, `POST /jobs/{jobId}/result`.

**`src/Controller/Api/InternalWorkerController.php`** (`/api/v1/internal/worker`,
firewall `GatewayInternalAuthenticator`, только для gateway, НЕ в Nelmio):
`POST /result`, `POST /fail`, `POST /dlq-fail`, `POST /liveness` (registry-06:
батч-пуш liveness от WS-Gateway, обновляет `last_seen` по составному ключу
`(workerType, instanceId)` — UPDATE ONLY, никогда не создаёт capability-строки).

**`src/Controller/Admin/Api/*.php`** (все под `/api/v1/admin`, `ROLE_ADMIN`):
`AdminApiController` → `GET /ping`; `ConversionLogController` → `GET /conversions`;
`ConversionToggleController` → `GET|POST /conversions-toggle`; `QueueController` →
`GET /queues`; `StatsController` → `GET /stats`; `UserController` (`/api/v1/admin/users`)
→ `GET ''`, `POST /{id}/ban`, `POST /{id}/unban`, `POST /{id}/reset-quota`,
`POST /{id}/plan`.

Внутренние (`internal/worker`, `worker`) и админские зоны — обычные REST-эндпоинты,
но НЕ часть публичного контракта из `nelmio_api_doc.yaml` (см. ниже) и не
предназначены для внешних клиентов.

## OpenAPI / документация

- Бандл — `NelmioApiDocBundle`, конфиг `config/packages/nelmio_api_doc.yaml`.
- Область документирования (`areas.default.path_patterns`) — только
  `^/api/v1(?!/internal|/worker)`: `internal/*` и `worker/*` намеренно исключены
  негативным lookahead (это не публичный контракт, а gateway/worker-протокол).
- Security scheme — `Bearer` (`type: http, scheme: bearer, bearerFormat: JWT`),
  применяется глобально (`security: [Bearer: []]`).
- UI/JSON-роуты — `config/routes/nelmio_api_doc.yaml`: `GET /api/doc` (Swagger UI),
  `GET /api/doc.json` (raw OpenAPI JSON).
- Каждый экшн описывается атрибутами `OpenApi\Attributes` (`use OpenApi\Attributes as OA;`)
  прямо над методом: `#[OA\Tag(name: '...')]`, `#[OA\Post(...)]`/`#[OA\Get(...)]`,
  `#[OA\RequestBody(...)]` и т.д. — см. `ConversionController::convert()` или
  `AuthController::refresh()` как образцы. Новый публичный эндпоинт ДОЛЖЕН нести
  такие же атрибуты, иначе выпадет из `/api/doc`.
- **Фактическая конвенция для `Admin/Api`:** несмотря на то что `^/api/v1/admin`
  попадает в область Nelmio, все существующие admin-контроллеры OA-атрибутов
  **НЕ несут** (admin — не публичный контракт, `ROLE_ADMIN`). Новый admin-эндпоинт
  следует этой sibling-конвенции (без OA). Требование «ДОЛЖЕН нести OA» относится
  к публичным user-facing эндпоинтам, не к admin. (Зафиксировано 2026-07-18 при
  добавлении `POST /api/v1/admin/dead-letter/requeue`.)
