---
name: redesign-auth-access-contract
description: Единый контракт редизайна auth/доступа convertor — анонимная конвертация (guest-User по cookie), гейтинг ai/video, Telegram-логин через бота (Symfony webhook, magic-link на своём устройстве, nonce-bound). ОБЯЗАТЕЛЕН к прочтению для карт anon-conversion-guest-model (backend-A), telegram-bot-login-flow (backend-B), upload-ui-bot-auth-rework (frontend-C). Триггеры: guest-User, ROLE_GUEST, anon convert, гейт ai/video, telegram webhook, /auth/telegram/start, /auth/telegram/callback, magic-link login, tg_login_nonce, deep-link t.me start=CODE, merge guest history.
---

# Контракт: редизайн auth + доступа (convertor)

Общий источник истины для трёх карт эпика. Каждый имплементер читает СВОЮ зону + общие разделы. Контракт править только через тимлида.

## Решения (вшиты, не переоткрывать)
- **Бот** — Symfony webhook-контроллер (НЕ отдельный сервис).
- **Логин** — pairing + poll (cross-device): сайт генерит CODE → deep-link в бота → «Войти» в боте → backend помечает CODE authorized + биндит ТГ → исходный браузер поллит и получает JWT.
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
  { "error": "auth_required", "message": "Войдите через Telegram для ai/video конвертаций" }
  ```
- Иначе (любая не-ai/не-video пара) — guest конвертит свободно (в пределах rate-limit/размера).
- Проверку делать в `ConversionController::convert` / `ConversionManager` ДО постановки в очередь, на основе вычисленных `isAi`/`category`.

## Rate-limit и размеры (зона A)
- `config/packages/rate_limiter.yaml`: лимитер для guest по IP (например `anon_convert`: sliding_window, N/час — согласовать значение, стартовое N=20/час). Применять в `convert` для `ROLE_GUEST`.
- Размер файла: free = 50MB (как в CLAUDE.md). Проверка в `convert` (уже может быть — сверить).
- `GET /api/v1/quota` для guest: вернуть гостевые остатки (не 401). Форма как сейчас `{conversions, ai_conversions, plan}` — для guest `plan: "guest"`, `ai_conversions: 0`.

## Anon status/download (зона A, читает C)
- `GET /api/v1/convert/{id}/status` и `/download`: заменить проверку владельца на `conversion.user.id === currentUser.id`, где currentUser может быть guest (owner-check работает и для guest, т.к. guest владеет своей конвертацией). Т.е. НЕ добавлять отдельный signed-token — доступ по owner=guest через ту же guest-cookie-аутентификацию.
- Открыть в `security.yaml`: `POST ^/api/v1/convert`, `GET ^/api/v1/convert/{id}/status`, `/download`, `/history`, `/quota` — под `ROLE_GUEST` (guest проходит; ai/video режется на уровне контроллера 403, не firewall).

## Telegram bot login API (зона B, читает C)
Все три — под firewall `auth` (public, security:false), кроме webhook (свой секрет).

**МОДЕЛЬ = MAGIC-LINK на своём устройстве (НЕ poll).** Секрет завершения логина доставляется в Telegram-чат тому, кто нажал «Войти» — не торчит в публичном poll. Same-device: логин завершается в том же браузере, где начали (привязка nonce-cookie). Это закрывает login-CSRF/takeover (#1); session-fixation закрывается nonce-cookie.

1. **`POST /api/v1/auth/telegram/start`** → инициировать вход.
   - Генерит `code` (публичный, для deep-link, высокая энтропия) + серверный `nonce`.
   - **Ставит httpOnly cookie `tg_login_nonce`** (Secure, SameSite=Lax, TTL 5 мин) в браузер-инициатор; связывает `code ↔ hash(nonce)` в Redis (атомарно, паттерн `RefreshTokenService`), статус `pending`.
   - Ответ: `{ "code": "<opaque>", "deep_link": "https://t.me/YouFileConvertBot?start=<code>", "expires_in": 300 }`.
   - username бота — из `%env(TELEGRAM_BOT_USERNAME)%`.

2. **`GET /api/v1/auth/telegram/callback?code=<code>&s=<linkSecret>`** → завершение (браузер открывает magic-ссылку из бота).
   - Проверки (ВСЕ обязательны, атомарно в `redeem`): `code` существует и `status=authorized`; **cookie `tg_login_nonce` совпадает** с `nonceHash` (иначе 403 — не тот браузер → fixation отбит); **`s` (linkSecret) из query совпадает** с `linkSecretHash` (иначе 403 — нет секрета из TG-чата → takeover отбит); code не истёк.
   - Успех: `findOrCreateUser` уже связан с code при авторизации (см. webhook) → выдать JWT + refresh-cookie (`RefreshTokenService::issueFamily`) + merge guest-истории (`GuestUserService::mergeInto` по `guest_id` cookie) + погасить guest-cookie + **инвалидировать code (one-time)** + погасить `tg_login_nonce`. Ответ — редирект на страницу приложения в залогиненном виде (передать JWT фронту: см. раздел Frontend).
   - Провал (нет code/не authorized/nonce mismatch/expired): 4xx + понятная страница «ссылка недействительна, начните вход заново».
   - **НЕТ polling-эндпоинта.** Фронт после открытия deep-link просто ждёт, что пользователь завершит в Telegram и вернётся по magic-ссылке (навигация браузера).

3. **`POST /api/v1/telegram/webhook`** → приём апдейтов Telegram.
   - Защита: заголовок `X-Telegram-Bot-Api-Secret-Token` == `%env(TELEGRAM_WEBHOOK_SECRET)%` (завести env, секрет в `.env.local`). Отдельный firewall/паттерн `^/api/v1/telegram/webhook`, security:false, проверка секрета в контроллере.
   - Обработка `message` с `/start <code>`: показать инлайн-кнопку «Войти» (`answerCallback`/`sendMessage` c `reply_markup` inline_keyboard, callback_data несёт `code`).
   - Обработка `callback_query` «Войти»: **только если `code` в статусе `pending`** (guard: первый тап побеждает; повторный/форварженный тап НЕ перепривязывает) — `findOrCreateUser(telegramId, username, first_name)`, сгенерить **`linkSecret`** (высокая энтропия), пометить `code` → `authorized` + сохранить `user.id` + `hash(linkSecret)` (в Redis, `nonceHash` сохраняется). Затем **отправить в чат magic-ссылку** `https://<APP_HOST>/api/v1/auth/telegram/callback?code=<code>&s=<linkSecret>` — «Нажмите, чтобы войти на устройстве, где начали». Ссылка (и `linkSecret`) уходит ТОЛЬКО в чат авторизовавшего.
   - **ДВА секрета на `/callback` обязательны** (см. п.3): `tg_login_nonce`-cookie (закрывает fixation — привязка к браузеру-инициатору) И `linkSecret` из query (закрывает takeover — доставлен только авторизовавшему через TG). Без linkSecret magic-ссылка несла бы лишь публичный `code` → атакующий, заминтивший code+nonce, завершил бы вход как жертва. Оба хэша сравнивать; `redeem` гасит code (one-time) ТОЛЬКО при совпадении обоих; mismatch — без гашения (no-DoS).
   - **Merge-нюанс:** webhook не видит browser-cookie; merge делает `callback` (у него есть и `tg_login_nonce`, и `guest_id` cookie) при выдаче JWT — вызывает `GuestUserService::mergeInto(realUser, guestIdИзCookie)`.
   - `APP_HOST` для magic-ссылки — из env (`APP_URL`), не хардкод.

- **Bot-API клиент** (зона B): сервис `TelegramBotClient` — `sendMessage`, `answerCallbackQuery`, `editMessageReplyMarkup`, `setWebhook`; base `https://api.telegram.org/bot<TOKEN>/`, токен `%env(TELEGRAM_BOT_TOKEN)%` (уже забинжен в services.yaml как `$telegramBotToken`).
- **make-таргет** `tg-set-webhook` — регистрирует webhook-URL + секрет (через console-команду или curl-таргет; docker-only паттерн проекта).

## Frontend (зона C, читает A и B)
- Снести из `templates/conversion/index.html.twig`: `<script src=telegram-widget.js>`, `window.onTelegramAuth`.
- Кнопка «Войти через Telegram»: `POST /auth/telegram/start` (`credentials:'include'` — чтобы принять `tg_login_nonce`) → открыть `deep_link` + показать «Продолжите в Telegram: нажмите «Войти», затем откройте пришедшую ссылку на этом же устройстве». **Никакого поллинга.**
- Завершение — через `GET /auth/telegram/callback` (пользователь открывает magic-ссылку из бота): backend редиректит на приложение уже залогиненным. Передача JWT фронту: callback ставит JWT во временную httpOnly-cookie/сессию ИЛИ редиректит на страницу с one-time-обменником — согласовать реализацию (проще: callback выставляет тот же механизм, что и refresh, и фронт на загрузке тянет access-token через `/auth/refresh`). Итог: после возврата фронт видит себя залогиненным.
- Аноним конвертит без логина. Для ai/video-таргетов: показать гейт «войдите»; на 403 `auth_required` от `convert` — предложить логин.
- `authFetch`: guest-запросы (status/download/convert без Bearer) идут с `credentials:'include'` (guest-cookie); при наличии JWT — Bearer. Download guest — тоже через fetch+blob с cookie.

## Устаревшее (удалить в зоне C/B)
- `POST /api/v1/auth/telegram` (widget callback), `TelegramAuthService` (HMAC виджета), `TelegramAuthDTO` — удалить как obsolete (виджет снят). Карта `login-widget-bot-username` — obsolete.

## Общие правила
- Docker/тесты/lint — только через Makefile-таргеты. `make phpstan` (0 ошибок), `make cs`, `make docker-check`.
- Секреты — в `.env.local` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`); в трекаемых `.env` — пустые плейсхолдеры.
- Комментарии/доки — на русском, идентификаторы как есть.
