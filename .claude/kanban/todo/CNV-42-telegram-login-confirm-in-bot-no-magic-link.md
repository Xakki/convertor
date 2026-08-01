### Telegram-логин: подтверждение прямо в боте, poll вместо magic-link

**Criticality:** Medium

**TAGS:**
- feature
- ux

**Description:**
Сейчас Telegram-логин — same-device magic-link в два тапа внутри бота:
1. Страница дёргает `POST /api/v1/auth/telegram/start`
   (`TelegramLoginController::start`) → минт `code` + серверный `nonce`,
   httpOnly-cookie `tg_login_nonce` (Secure, SameSite=Lax, path `/api/v1/auth`,
   TTL 300s), JSON `{code, deep_link, expires_in:300}`; фронт открывает бота в
   новой вкладке (`login.html.twig:148-169`, `window.open`).
2. В боте юзер жмёт inline-кнопку «Войти» → webhook `callback_query`
   (`TelegramWebhookController::handleCallbackQuery`) авторизует код
   (`TelegramLoginCodeStore::authorize` → status `authorized`, генерит
   `linkSecret`), затем шлёт **вторую** кнопку «Войти на сайт» с magic-URL
   `.../auth/telegram/callback?code=…&s=<linkSecret>`.
3. Юзер жмёт magic-кнопку → `GET /api/v1/auth/telegram/callback` redeem'ит по
   трём факторам (`code` + `s` + nonce-cookie) → refresh-cookie + 302 на `/`
   (access-токен фронт берёт через `/auth/refresh`).

Проблема — второй тап уводит из исходной вкладки: magic-link открывается во
встроенном браузере Telegram (часто другой browser-контекст без nonce-cookie →
молчаливый облом), а исходная вкладка с загруженным файлом/конвертацией
остаётся неавторизованной.

**Problem:**
Лишний шаг + смена browser-контекста на самом ценном шаге воронки. Логин
происходит «не там, где начинал» — теряется контекст загруженной конвертации.

**Impact:**
Трение и потери на единственном (MVP) сценарии логина.

**Recommendation:**
Убрать magic-link целиком; исходная вкладка сама узнаёт об апруве через
polling и авторизуется на месте.

Backend:
- Новый `GET /api/v1/auth/telegram/poll?code=<code>` (cookie `tg_login_nonce`).
  Обмен по **двум** факторам — `code` + nonce-cookie (linkSecret на этом пути
  не нужен: сам факт `status=authorized` в KeyDB уже доказывает апрув в боте;
  nonce-cookie сохраняет same-device-привязку, утёкший код без cookie
  бесполезен).
  Ответы: `204 pending` | `200 authorized` (+ выдаёт refresh-cookie тем же
  `issueFamily`, мержит guest-историю — как сейчас в `callback`) |
  `410 expired`/`gone` | `403 mismatch` (nonce не совпал).
  Redeem — расширить/добавить Lua-ветку `redeem(code, nonce)` в
  `TelegramLoginCodeStore` (проверка nonceHash + status=authorized → `DEL`
  ключа, one-time), рядом с текущим `REDEEM_LUA`.
- Удалить `GET /api/v1/auth/telegram/callback`
  (`TelegramLoginController::callback`) и всю ветку `linkSecret`
  (генерация в `authorize()`, `AUTHORIZE_LUA` в части secret, `&s=` в
  webhook). `authorize()` теперь только переводит код в `authorized`.
- Webhook `handleCallbackQuery`: после апрува вместо второй кнопки с URL
  отправить текст **«Авторизация успешна. Вернитесь в браузер.»** (без ссылки).

Frontend (`templates/auth/login.html.twig` + Alpine `startLogin()`):
- После `start` — не только открыть бота, но и запустить poll-цикл на тот же
  `code`: `setTimeout` 2s + generation-токен (переиспользовать паттерн из
  `_converter_app_script.html.twig:878-917`), cap 150 попыток / TTL 300s.
- На `200 authorized` — дёрнуть `/auth/refresh`, обновить состояние
  (страница становится залогиненной без перезагрузки/редиректа).
- На `410`/timeout — показать «код истёк, начните заново».
- UI ожидания: hint-блок + спиннер (уточнить с дизайном при реализации —
  не блокирует).

Rate-limit: добавить limiter `anon_telegram_poll` соседней записью в
`config/packages/rate_limiter.yaml` (по IP), потребление — как в
`ConversionController`.

**Acceptance Criteria:**
- Апрув в боте → исходная вкладка авторизуется сама, без перехода по ссылке.
- Привязка same-device сохранена: без nonce-cookie `poll` не отдаёт сессию.
- `code` одноразовый: второй успешный `poll` по тому же коду → отказ.
- Истечение/таймаут кода корректно отражаются в UI; poll не крутится вечно
  (cap + TTL).
- Endpoint `/auth/telegram/callback` и вся `linkSecret`-логика удалены; сборка
  и тесты не ссылаются на них.
- Сообщение бота после апрува — «Авторизация успешна. Вернитесь в браузер.»,
  без inline-URL.
- Rate-limit на poll работает.
- Обновлены: skill `redesign-auth-access-contract` (модель poll вместо
  magic-link — закрывает дрейф из бывшей `auth-docs-drift-pairing-poll`),
  OpenAPI (новый `poll`, убран `callback`), e2e-карта
  [[CNV-14-e2e-magic-link-callback-mockbot]] ретаргетнута на poll-флоу.
- Tests/QA green: `make phpstan`, `make cs-check`, `make test`, `make test-e2e`.

**Decisions:** *(груминг 2026-07-28, с @user)*
- **Транспорт — HTTP-polling 2s** (не SSE). Переиспользуем готовый паттерн
  статуса конвертации; SSE держал бы PHP-FPM-воркер на всё время ожидания —
  новый класс проблем ради ~2s.
- **Magic-link выпиливаем полностью** (не оставляем фолбэком). Вместе с ним
  уходят `/auth/telegram/callback` и `linkSecret`. Компромисс осознан: если
  исходная вкладка закрыта/перезагружена — логин надо начать заново.
- **Факторы обмена через poll — `code` + nonce-cookie** (без linkSecret). Тот
  же уровень защиты, что у magic-link: same-device через httpOnly-nonce +
  доказательство апрува через `status=authorized`.
- **Дрейф доков закрываем реализацией.** `/auth/telegram/poll` становится
  реальным → карточка `auth-docs-drift-pairing-poll` удалена как поглощённая;
  правка skill `redesign-auth-access-contract` входит в AC этой задачи.

**Status:** todo.
