### Фронт: переработка логина под бот-флоу + анонимную конвертацию

**Критичность:** High

**TAGS:**
- feature
- frontend

**Описание:**
Переработать `templates/conversion/index.html.twig`: убрать Telegram Login Widget, поставить кнопку бот-логина (pairing+poll), поддержать анонимную конвертацию с гейтом ai/video. Продолжение [[upload-conversion-ui]] (страница-шелл уже готова).

**Зона (frontend-C):**
- Снести `<script telegram-widget.js>` и `window.onTelegramAuth`.
- Кнопка «Войти через Telegram»: `POST /auth/telegram/start` → открыть `deep_link` (нов. вкладка) + «ожидаем подтверждения» + поллинг `GET /auth/telegram/poll?code=` (2с, до `expires_in`, `credentials:'include'`) → на `authorized` сохранить JWT, показать залогинен.
- Аноним конвертит без логина. Для ai/video-таргетов — гейт «войдите»; на 403 `auth_required` от convert — предложить логин.
- `authFetch`: без JWT → guest-запросы с `credentials:'include'` (guest-cookie); с JWT → Bearer. Download guest — fetch+blob с cookie.
- Удалить obsolete-бэкенд виджета: `POST /api/v1/auth/telegram`, `TelegramAuthService`, `TelegramAuthDTO` (согласовать с backend-B, чтобы не пересечься — координирует тимлид).

**Контракт:** ОБЯЗАТЕЛЬНО читать skill `redesign-auth-access-contract` (разделы «Frontend», «Telegram bot login API», «Anon status/download», «Гейтинг», «Устаревшее»).

**Зависимость:** рабочие эндпоинты из [[telegram-bot-login-flow]] (B) и [[anon-conversion-guest-model]] (A). Стартовать после их мержа (или против контракта, интеграция в конце).

**AC:**
- Виджет убран; кнопка бот-логина + поллинг работают; аноним конвертит не-ai/не-video; ai/video гейтятся; guest-скачивание по cookie; obsolete-бэкенд удалён.
- `make phpstan` 0, `make cs` чисто, `make docker-check` ок, functional-тест страницы зелёный.

- 2026-07-10: реализовано (frontend-C, коммиты 53535f9/8ded393/35709f2). Виджет+`onTelegramAuth`+login-poll убраны; кнопка «Войти» → `POST /auth/telegram/start` (credentials:include) → deep-link + инструкция; сессия подхватывается refresh-on-load (`POST /auth/refresh`); анон конвертит без логина; ai/video → 403 `auth_required` surfaced с кнопкой логина; `authFetch` guest(cookie)/Bearer; guest-скачивание по cookie. Obsolete widget-бэкенд удалён (`POST /auth/telegram`, `TelegramAuthService`, `TelegramAuthDTO`, bind) — грепом висячих ссылок нет. 4 e2e-кейса скипнуты с пойнтером на [[e2e-login-helper-magic-link]] (`tests/Integration/Api/ConversionApiIntegrationTest.php:78`). Self-review APPROVE (6 фокус-пунктов), minor-регресс `jwt_username` починен. Гейт зелёный: phpstan 0, cs, docker-check, 142 теста (4 skipped, 0 failures).

**Status:** ready — реализовано + ревью APPROVE + gate зелёный. Ждёт финального аппрува/мержа (ветка общая с эпиком).

Siblings: [[telegram-bot-login-flow]] · [[anon-conversion-guest-model]] · [[upload-conversion-ui]]
