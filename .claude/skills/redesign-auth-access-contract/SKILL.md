---
name: redesign-auth-access-contract
description: Единый контракт редизайна auth/доступа convertor — анонимная конвертация (guest-User по cookie), гейтинг ai/video, Telegram-логин через бота (Symfony webhook, pairing + HTTP poll 2s, nonce-bound), мультипровайдерный OAuth-логин (Google/GitHub/Yandex/VK, эпик oauth-00). ОБЯЗАТЕЛЕН к прочтению для карт anon-conversion-guest-model (backend-A), telegram-bot-login-flow (backend-B), upload-ui-bot-auth-rework (frontend-C), и подзадач oauth-01…06. Триггеры: guest-User, ROLE_GUEST, anon convert, гейт ai/video, telegram webhook, /auth/telegram/start, /auth/telegram/poll, pairing+poll, tg_login_nonce, deep-link t.me start=CODE, merge guest history, OAuth, SocialIdentity, /auth/oauth/{provider}/start, /auth/oauth/{provider}/callback, findOrCreateUser, PKCE, VK ID, emailVerified.
---

# Контракт: редизайн auth + доступа (convertor)

Общий источник истины для трёх карт эпика. Каждый имплементер читает СВОЮ зону + общие разделы. Контракт править только через тимлида.

## Решения (вшиты, не переоткрывать)
- **Бот** — Symfony webhook-контроллер (НЕ отдельный сервис).
- **Логин** — pairing + poll (cross-device, same-tab): сайт минтит CODE → deep-link в бота → «Войти» в боте → backend помечает CODE authorized + биндит ТГ → исходный браузер поллит и получает JWT.
- **Аноним** — guest-User по httpOnly-cookie. `Conversion.user` остаётся **NOT NULL** (владеет guest). При логине история guest'а перепривязывается к реальному User.

## Роли и firewall
- `ROLE_GUEST` — аноним с guest-cookie. `ROLE_USER` — полноценный (залогинен через ТГ).
- Иерархия ролей: `ROLE_USER` включает `ROLE_GUEST` (`role_hierarchy: ROLE_USER: [ROLE_GUEST]`), чтобы залогиненный проходил guest-гейты.
- Firewall `api` (`^/api`, stateless, jwt): добавить кастомный **GuestAuthenticator** ПОСЛЕ jwt — если нет валидного Bearer, аутентифицировать как guest-User по cookie (создать при отсутствии). Порядок: сначала JWT, при его отсутствии — guest.

## Guest-модель (зона A, читают также B и C)
- **Cookie**: имя `guest_id`, httpOnly, Secure, SameSite=Lax, TTL 30 дней. Значение — подписанный (HMAC) opaque id, чтобы нельзя было подобрать чужой. Секрет — из env (переиспользовать `APP_SECRET` или отдельный).
- **User entity**: добавить `isGuest` (bool, default false) и `guestId` (nullable, unique — сырое значение cookie-id). `telegramId` у guest = null. Миграция Doctrine.
- **GuestAuthenticator**: на запрос без Bearer к `^/api` — читать `guest_id`; валидна подпись → найти User по `guestId`; нет → создать guest-User (isGuest=true, guestId=новый), выставить cookie в ответе. Аутентифицировать как этот User с `ROLE_GUEST`.
- **Merge при логине** (зона B вызывает сервис зоны A): при успешном Telegram-логине, если в запросе есть валидный `guest_id` и по нему найден guest-User — переназначить все его `Conversion.user` на реального User, деактивировать/удалить guest-User, погасить cookie. Метод: `GuestUserService::mergeInto(User $real, string $guestId): void`.

## Гейтинг конвертации (зона A)
- Правило «требует логина»: `conversion.isAi === true` ИЛИ `conversion.category === FileCategory::Video`.
  (`ai` — это флаг `isAi` из `ConversionRegistry::isAi()`, НЕ категория. `video` — категория `FileCategory::Video`. Категория/флаг вычисляются как сейчас в `ConversionManager`.)
- Если пара требует логина, а текущий пользователь — guest (не имеет `ROLE_USER`): вернуть **HTTP 403** с телом:
  ```json
  { "error": "auth_required", "message": "Для ai/video конвертаций нужен вход." }
  ```
- Иначе (любая не-ai/не-video пара) — guest конвертит свободно (в пределах rate-limit/размера).
- Проверку делать в `ConversionController::convert` / `ConversionManager` ДО постановки в очередь, на основе вычисленных `isAi`/`category`.

## Rate-limit и размеры (зона A)
- `config/packages/rate_limiter.yaml`: лимитер для guest по IP (например `anon_convert`: sliding_window, N/час — согласовать значение, стартовое N=20/час). Применять в `convert` для `ROLE_GUEST`.
- Размер файла: free = 50MB (как в CLAUDE.md). Проверка в `convert` (уже может быть — сверить).
- `GET /api/v1/quota` для guest: вернуть гостевые остатки (не 401). Форма CNV-30: `{ plan, tiers: { light|medium|heavy|ai: { daily|monthly: { used, limit, remaining } } }, max_upload_bytes }` — для guest `plan: "guest"`, AI-тир с нулевыми лимитами.

## Anon status/download (зона A, читает C)
- `GET /api/v1/convert/{id}/status` и `/download`: заменить проверку владельца на `conversion.user.id === currentUser.id`, где currentUser может быть guest (owner-check работает и для guest, т.к. guest владеет своей конвертацией). Т.е. НЕ добавлять отдельный signed-token — доступ по owner=guest через ту же guest-cookie-аутентификацию.
- Открыть в `security.yaml`: `POST ^/api/v1/convert`, `GET ^/api/v1/convert/{id}/status`, `/download`, `/history`, `/quota` — под `ROLE_GUEST` (guest проходит; ai/video режется на уровне контроллера 403, не firewall).

## Telegram bot login API (зона B, читает C)
Все три — под firewall `auth` (public, security:false), кроме webhook (свой секрет).

**МОДЕЛЬ = PAIRING + POLL (same-device, same-tab).** Исходная вкладка сама узнаёт об апруве в боте и завершает вход на месте. Nonce-cookie закрывает login-CSRF/session-fixation: атакующий, знающий публичный CODE, не завершит вход (его браузер не несёт nonce-cookie → 403 mismatch, код не сжигается).

1. **`POST /api/v1/auth/telegram/start`** → инициировать вход.
   - Генерит `code` (публичный, для deep-link, высокая энтропия) + серверный `nonce`.
   - **Ставит httpOnly cookie `tg_login_nonce`** (Secure, SameSite=Lax, TTL 5 мин) в браузер-инициатор; связывает `code ↔ hash(nonce)` в Redis (атомарно, паттерн `RefreshTokenService`), статус `pending`.
   - Ответ: `{ "code": "<opaque>", "deep_link": "https://t.me/anyconvertor_bot?start=<code>", "expires_in": 300 }`.
   - username бота — из `%env(TELEGRAM_BOT_USERNAME)%`.

2. **`GET /api/v1/auth/telegram/poll?code=<code>`** + cookie `tg_login_nonce` → опрос статуса (исходная вкладка).
   - Ответы: `204 pending` (апрува в боте ещё нет) | `200 authorized` (refresh-cookie + merge guest-истории + clear nonce + one-time redeem) | `410 expired` (код истёк / уже погашен) | `403 mismatch` (nonce-cookie не совпал — fixation отбита, код **не** сжигается) | `400 missing` (нет code или cookie) | `429 rate limit` (limiter `anon_telegram_poll` по IP).
   - Факторы обмена: **только `code` + nonce-cookie** (linkSecret не используется).
   - `redeem(code, nonce)` — Lua one-time: проверка nonceHash + `status=authorized` → `DEL` ключа; mismatch **не** сжигает code.
   - Успех `200`: `findOrCreateUser` уже связан с code при авторизации (см. webhook) → refresh-cookie (`RefreshTokenService::issueFamily`) + merge guest-истории (`GuestUserService::mergeInto` по `guest_id` cookie) + погасить guest-cookie + инвалидировать code (one-time) + погасить `tg_login_nonce`. Тело: `{"status":"authorized"}`; access-JWT фронт берёт через `POST /auth/refresh`.

3. **`POST /api/v1/telegram/webhook`** → приём апдейтов Telegram.
   - Защита: заголовок `X-Telegram-Bot-Api-Secret-Token` == `%env(TELEGRAM_WEBHOOK_SECRET)%` (завести env, секрет в `.env.local`). Отдельный firewall/паттерн `^/api/v1/telegram/webhook`, security:false, проверка секрета в контроллере.
   - Обработка `message` с `/start <code>`: показать инлайн-кнопку «Войти» (`answerCallback`/`sendMessage` c `reply_markup` inline_keyboard, callback_data несёт `code`).
   - Обработка `callback_query` «Войти»: **только если `code` в статусе `pending`** (guard: первый тап побеждает; повторный/форварженный тап НЕ перепривязывает) — `findOrCreateUser(telegramId, username, first_name)`, `authorize(code, userId)` помечает `code` → `authorized` (bool, без linkSecret). Затем отправить текст **«Авторизация успешна. Вернитесь в браузер.»** — без URL-кнопки, без magic-link.
   - **Merge** происходит в `poll` (не в webhook): у poll есть и `tg_login_nonce`, и `guest_id` cookie → `GuestUserService::mergeInto(realUser, guestIdИзCookie)`.

- **Bot-API клиент** (зона B): сервис `TelegramBotClient` — `sendMessage`, `answerCallbackQuery`, `editMessageReplyMarkup`, `setWebhook`; base `https://api.telegram.org/bot<TOKEN>/`, токен `%env(TELEGRAM_BOT_TOKEN)%` (уже забинжен в services.yaml как `$telegramBotToken`).
- **make-таргет** `tg-set-webhook` — регистрирует webhook-URL + секрет (через console-команду или curl-таргет; docker-only паттерн проекта).

## OAuth-провайдеры (эпик `oauth-00`, отдельно от Telegram)

Мультипровайдерный OAuth-логин (Google/GitHub/Yandex/VK) — параллельный Telegram-логину механизм входа,
НЕ его замена. `User.telegramId` не трогается; связь с внешним провайдером хранится отдельной сущностью
`SocialIdentity` (many-to-one → `User`). Источники — `App\Controller\Api\OauthController`,
`App\Service\Oauth\SocialIdentityResolver`, `App\Service\Oauth\Provider\*`, `App\Entity\SocialIdentity`.

- **Провайдеры** — `google`, `github`, `yandex`, `vk` (`OauthProviderRegistry`, ключ = `key()` адаптера).
  Google/GitHub — обёртки над `league/oauth2-*`; Yandex/VK — кастомные `AbstractProvider` (готовых
  league-пакетов нет). Расширяемо: новый провайдер = новый класс `App\Service\Oauth\Provider\*`,
  реализующий `OauthProviderInterface`, регистр/контроллер не меняются.
- **Эндпоинты** (`App\Controller\Api\OauthController`, под firewall `auth`, `^/api/v1/auth`, security:false,
  публичны как и `/auth/telegram/*`):
  - `GET /api/v1/auth/oauth/{provider}/start` — минтит одноразовый `state` (CSRF, `OauthStateStore`),
    для PKCE-провайдера (VK) генерит `code_verifier` и кладёт его в state-store вместе со `state`,
    редиректит на authorize-URL провайдера.
  - `GET /api/v1/auth/oauth/{provider}/callback?code=&state=` — гасит `state` атомарно (invalid/повтор →
    редирект `/login?oauth_error=state`), обменивает `code` на профиль (`fetchUserInfo`, любая ошибка →
    `/login?oauth_error=exchange`), резолвит/создаёт `User` (`SocialIdentityResolver::findOrCreateUser`,
    ошибка → `/login?oauth_error=internal`), мержит guest-историю, выдаёт сессию, редиректит на `/`.
  - Неизвестный/несконфигурированный `provider` → 404.
- **`SocialIdentity`** (`social_identities`, `App\Entity\SocialIdentity`): `user` (ManyToOne → `User`,
  CASCADE), `provider` (string(32)), `providerUid` (string(255)), `email` (string(180), NOT NULL —
  verified-адрес на момент линковки ИЛИ синтетический плейсхолдер `{provider}:{uid}@{provider}.oauth.local`),
  `username`, `displayName`, `createdAt`. **UNIQUE(provider, provider_uid)** — одна учётка провайдера не
  привязывается дважды; на этот индекс опирается race-обработка в резолвере.
- **`SocialIdentityResolver::findOrCreateUser`** (ядро корректности, порядок строго такой):
  1. Есть `SocialIdentity` по `(provider, provider_uid)` → логиним её `User`.
  2. Иначе, если `email` провайдера **VERIFIED** и не зарезервирован/не синтетический → найден `User` по
     `email` → линкуем к нему новую `SocialIdentity` (кросс-провайдерная привязка, напр. Google + GitHub
     на одном аккаунте).
  3. Иначе → создаём passwordless-`User` (НЕ гость: `isGuest=false`, `isActive=true`) + `SocialIdentity`.
  - **Anti-takeover инвариант:** линковка к существующему `User` по email происходит ТОЛЬКО если email
    verified у провайдера — иначе чужой аккаунт можно угнать, зарегистрировав у провайдера
    неподтверждённый адрес жертвы. `User.email` заполняется исключительно verified-адресом.
  - **Гонки:** два параллельных callback'а могут вставлять одну и ту же связь — проигравший ловит
    `UniqueConstraintViolationException` на `UNIQUE(provider, provider_uid)`, сбрасывает EM
    (`ManagerRegistry::resetManager()`, паттерн `ConversionResultPersister`) и повторно резолвит на
    свежем EM (`resolveAfterRace`).
- **Per-provider правила `emailVerified`** (маппятся в `OAuthUserInfo` каждым адаптером — это единственное,
  чем провайдеры отличаются друг от друга для резолвера):
  - **Google** (`GoogleProvider`) — userinfo (OpenID Connect) отдаёт `email` + булев `email_verified`
    напрямую. **Fail-closed:** отсутствие claim `email_verified` ⇒ считаем НЕ verified (Workspace-аккаунты,
    провизированные админом, могут иметь `email_verified=false`; отсутствие claim не равно подтверждению).
  - **GitHub** (`GithubProvider`) — `/user` не отдаёт verified-флаг вовсе (и email там может быть `null`,
    если непубличный). Адаптер делает отдельный `GET /user/emails` (scope `user:email`) и берёт
    primary+verified адрес оттуда (НЕ через встроенный league-fallback, который берёт первый email без
    проверки). `providerUid` — числовой GitHub id (стабилен), не login.
  - **Yandex** (`YandexProvider`) — userinfo без top-level `email`: только `default_email` (string|null) и
    `emails[]`. `default_email` присутствует → `emailVerified=true` (Yandex отдаёт его как подтверждённый
    primary). Только `emails[]` без `default_email` → берём первый адрес, но `emailVerified=false`
    (Yandex не подтверждает явным флагом, что это тот же адрес). Email отсутствует → `null`/`false`.
  - **VK ID** (`VkProvider`) — verified-флага для email в userinfo НЕТ вовсе ⇒ `emailVerified` **всегда
    `false`**, даже если email присутствует — email годится только для отображения/create, никогда для
    линковки к существующему `User`.
- **PKCE для VK ID** (`usesPkce() === true`, RFC 7636) — единственный провайдер без `client_secret`;
  `code_verifier` генерит `OauthController` (`base64url(random_bytes(32))`), хранит в `OauthStateStore`
  между `/start` и `/callback`. VK также возвращает `device_id` на callback (не известен на `/start`,
  читается прямо из query и прокидывается в token-обмен).
- **Переиспользование сессионного механизма** — идентично Telegram-poll, тот же код:
  `RefreshTokenService::issueFamily()` + `RefreshCookieFactory` (JWT в URL НЕ уходит, ставится
  refresh-cookie, SPA берёт access-токен через `POST /auth/refresh`) + `GuestUserService::mergeInto()`
  (валидный `guest_id` cookie → перепривязка истории + гашение cookie).
- **Env-конвенция** (`app-symfony/.env`, Symfony-only Dotenv, НЕ через compose environment, как
  `TELEGRAM_*`/`REFRESH_*`): base для `redirect_uri` — `APP_URL` (уже абсолютный,
  публичный origin приложения), конкатенируется как
  `{APP_URL}/api/v1/auth/oauth/{provider}/callback`), `<PROVIDER>_OAUTH_CLIENT_ID` /
  `_SECRET` на провайдера (Google/GitHub/Yandex). **VK — исключение: только `VK_OAUTH_CLIENT_ID`, секрета
  нет** (PKCE его заменяет). Реальные значения — `app-symfony/.env.local`; в трекаемом `.env` — пустые
  плейсхолдеры.
- **`/login`** (`GET /login`, `App\Controller\Web\LoginController`, route name `app_login`,
  `templates/auth/login.html.twig`) — страница с кнопками провайдеров; читает `?oauth_error=` для показа
  ошибки после неудачного callback (`state`/`exchange`/`internal`).

## Frontend (зона C, читает A и B)
- Снести из `templates/conversion/index.html.twig`: `<script src=telegram-widget.js>`, `window.onTelegramAuth`.
- Кнопка «Войти через Telegram»: `POST /auth/telegram/start` (`credentials:'include'` — чтобы принять `tg_login_nonce`) → открыть `deep_link` + запустить poll-цикл на тот же `code`: `setTimeout` 2s + generation-токен (паттерн из `_converter_app_script.html.twig`), cap 150 попыток / TTL 300s. Показать hint «Продолжите в Telegram: нажмите «Войти», затем вернитесь в браузер» + спиннер ожидания.
- Завершение — через poll: на `200 authorized` → `POST /auth/refresh`, обновить состояние / перейти на главную без magic-link. На `410`/timeout — показать «код истёк, начните заново».
- Аноним конвертит без логина. Для ai/video-таргетов: показать гейт «войдите»; на 403 `auth_required` от `convert` — предложить логин.
- `authFetch`: guest-запросы (status/download/convert без Bearer) идут с `credentials:'include'` (guest-cookie); при наличии JWT — Bearer. Download guest — тоже через fetch+blob с cookie.

## Устаревшее (удалить в зоне C/B)
- `POST /api/v1/auth/telegram` (widget callback), `TelegramAuthService` (HMAC виджета), `TelegramAuthDTO` — удалить как obsolete (виджет снят). Карта `login-widget-bot-username` — obsolete.

## Общие правила
- Docker/тесты/lint — только через Makefile-таргеты. `make phpstan` (0 ошибок), `make cs`, `make docker-check`.
- Секреты — в `.env.local` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`); в трекаемых `.env` — пустые плейсхолдеры.
- Комментарии/доки — на русском, идентификаторы как есть.
