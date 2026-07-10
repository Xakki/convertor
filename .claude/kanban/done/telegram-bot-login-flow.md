### Telegram-логин через бота (Symfony webhook + pairing/poll)

**Критичность:** High

**TAGS:**
- feature
- backend
- auth

**Описание:**
Заменить Telegram Login Widget на логин через нашего бота. Флоу pairing+poll (cross-device): сайт генерит CODE → кнопка ведёт в бота `t.me/YouFileConvertBot?start=CODE` → пользователь жмёт «Войти» в боте → backend помечает CODE authorized + биндит ТГ → исходный браузер поллит и получает JWT. Бот-бэкенда сейчас НЕТ (`TELEGRAM_BOT_TOKEN` использовался только для HMAC виджета) — строим с нуля как Symfony webhook.

**Зона (backend-B):**
- `POST /api/v1/auth/telegram/start` — выдать CODE + deep_link + expires_in.
- `GET /api/v1/auth/telegram/poll?code=` — pending | authorized(JWT + refresh-cookie) | expired.
- `POST /api/v1/telegram/webhook` — приём апдейтов (защита `X-Telegram-Bot-Api-Secret-Token`), `/start <code>` → инлайн-кнопка «Войти», `callback_query` → findOrCreateUser + code→authorized + ответ в бот.
- Сервис `TelegramBotClient` (sendMessage/answerCallbackQuery/editMessageReplyMarkup/setWebhook) на `%env(TELEGRAM_BOT_TOKEN)%`.
- One-time CODE в Redis атомарно (паттерн `RefreshTokenService`); переиспользовать `RefreshTokenService::issueFamily` для refresh.
- env `TELEGRAM_WEBHOOK_SECRET` (плейсхолдер в `.env`, реальный в `.env.local`).
- make-таргет `tg-set-webhook`.
- merge guest-истории при выдаче JWT в `poll` — вызвать `GuestUserService::mergeInto()` (сервис из карты anon-conversion-guest-model) если в запросе валидный `guest_id`.

**Контракт:** ОБЯЗАТЕЛЬНО читать skill `redesign-auth-access-contract` (разделы «Telegram bot login API», «Merge при логине», «Общие правила»).

**Зависимость:** `GuestUserService::mergeInto()` — из [[anon-conversion-guest-model]] (backend-A). Если ещё не готов — заложить вызов по интерфейсу/TODO, тимлид состыкует при мерже.

**AC:**
- Три эндпоинта работают по контракту; webhook защищён секретом; CODE one-time с TTL; JWT+refresh выдаются; setWebhook-таргет есть.
- `make phpstan` 0 ошибок, `make cs` чисто, `make docker-check` ок, тесты на start/poll/webhook (юнит/функциональные).

- 2026-07-10: реализовано (backend-B), интегрировано (merge 9b76714). **Модель сменена pairing+poll → magic-link на своём устройстве** (решение пользователя после находки login-CSRF). Security-ревью нашло HIGH account-takeover (непривязанный CODE); закрыто двухсекретной схемой: `tg_login_nonce`-cookie (привязка к браузеру-инициатору, бьёт fixation) + `linkSecret` в magic-ссылке, доставляемый ТОЛЬКО в TG-чат авторизовавшего (бьёт takeover) + status-guard authorize + no-burn на mismatch. Финальная adversarial-верификация: takeover+fixation закрыты (`[]`). Токен бота больше не течёт в логи (скраб). Гейт зелёный (phpstan 0, cs, docker-check, 148 тестов, merge-seam на реальных KeyDB+MariaDB).
- Эндпоинты: `POST /auth/telegram/start` (nonce-cookie + code + deep_link), `GET /auth/telegram/callback?code=&s=` (redeem обоих секретов → JWT+refresh+merge+302 на `/`), `POST /telegram/webhook`. `/poll` удалён. Residual: linkSecret в nginx access-log — accepted (nonce httpOnly обязателен + no-burn); e2e login-хелпер на obsolete виджете → карта [[e2e-login-helper-magic-link]].

**Status:** ready — реализовано, security+gate зелёные, #1 закрыт. Ждёт финального аппрува/мержа (ветка общая с эпиком).

Siblings: [[anon-conversion-guest-model]] · [[upload-ui-bot-auth-rework]] · [[upload-conversion-ui]]
