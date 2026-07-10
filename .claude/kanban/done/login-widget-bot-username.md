### Прокинуть TELEGRAM_BOT_USERNAME в Telegram Login Widget на фронте

**Критичность:** Medium

**TAGS:**
- feature
- frontend

**Описание:**
В `app-front/components/login-modal.html:96` Telegram Login Widget содержит хардкод-плейсхолдер `data-telegram-login="YOUR_BOT_USERNAME"`. Нужно, чтобы туда подставлялось реальное имя бота из env `TELEGRAM_BOT_USERNAME` (задан в корневом `.env` = `YouFileConvertBot`). Пользователь явно вернул переменную в `.env` («понадобится по всему проекту»).

**Проблема:**
- `app-front/` сейчас вообще НЕ отдаётся стеком: nginx монтирует только `app-symfony/public`, node-сервиса нет (`DOCKER_IMAGE_NODE`/`EXT_NODE_PORT` объявлены, но сервиса в compose нет), HTMX-запрос `/components/login-modal.html` сейчас даёт 404. → визуального эффекта от подстановки сейчас не будет.
- Нет механизма доставки публичного конфига на фронт: ни `window.__CONFIG__`, ни конфиг-эндпоинта, ни build-time подстановки. Все значения на фронте захардкожены.
- `TELEGRAM_BOT_USERNAME` намеренно убран из `docker-compose` `x-app-env` (он не нужен бэку: верификация Telegram-логина в `TelegramAuthService` идёт по **токену**, не по username; username — чисто атрибут виджета). Любая Symfony-доставка потребует снова сделать переменную доступной Symfony.

**Влияние:**
Telegram-логин на фронте не заработает с реальным ботом, пока username не доставлен. Блокируется фактической задачей «подключить app-front к стеку».

**Решение (черновик) — выбран вариант B (Symfony public-config API + JS):**
- Эндпоинт `GET /api/v1/public/config` → JSON `{ telegram_bot_username, ... }` (масштабируется на прочий публичный конфиг «по всему проекту»: фиче-флаги, URL-ы сервисов и т.п.).
- `app-front/js` фетчит конфиг на загрузке и проставляет `data-telegram-login` (учесть гонку с HTMX-загрузкой модалки — грузить конфиг блокирующе в `<head>` до HTMX-вызовов, либо ждать события `config:loaded`).
- Сделать `TELEGRAM_BOT_USERNAME` доступным Symfony. По принятому в проекте правилу «переменная живёт там, где её реальный потребитель»: единственный потребитель — Symfony-контроллер конфига → положить значение в `app-symfony/.env*` (Symfony-only), НЕ возвращать инъекцию в `x-app-env`. (Сверить с тем, что юзер держит его и в корневом `.env`.)

**Decisions:**
- 2026-06-25: при дедупе `.env*` обнаружено, что `app-front` не отдаётся стеком и логин-флоу на бэке использует токен, не username. Реализацию отложили — по решению пользователя завести карточку (вариант доставки B рекомендован, но старт — после/вместе с подключением фронта).
- `TELEGRAM_BOT_USERNAME` stays in **root `.env`** (Q12, user intent) and is made reachable by Symfony ; delivery = **Twig server-side render** (Q12.1) — widget rendered by Symfony template, no separate config endpoint needed. Depends on upload-conversion-ui (front served via Twig). Touch: login-modal.html:96.
- 2026-07-10: попытка старта — зависимость НЕ готова. Проверено: `upload-conversion-ui` всё ещё в `todo/`; Twig-фронт — голый скелет (`base.html.twig` = дефолтная welcome-страница, из шаблонов только `conversion/_upload_form.html.twig`, web/page-контроллера нет — только `Api/`). Рендерить виджет пока не во что. `TELEGRAM_BOT_USERNAME=YouFileConvertBot` есть только в корневом `.env`, Symfony его не видит. **По решению пользователя — заблокировать/парковать**: сначала `upload-conversion-ui`, потом эта карточка.

- 2026-07-10: **OBSOLETE.** Пользователь сменил модель авторизации: логин через **бота** (deep-link + «Войти» + pairing/poll), Telegram Login Widget убран вовсе. Премиса карточки (username в `data-telegram-login` виджета) исчезла. Прокидка `TELEGRAM_BOT_USERNAME` в Symfony (сделана в upload-conversion-ui) переиспользуется для deep-link. Заменяется картами [[telegram-bot-login-flow]] + [[upload-ui-bot-auth-rework]].

**Status:** OBSOLETE — виджет отменён, заменён бот-флоу. Можно удалить/закрыть.

Siblings: [[upload-conversion-ui]] · [[docs-admin-panel]]
