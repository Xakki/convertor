### Эпик: мультипровайдерная OAuth-авторизация (Google/GitHub/Yandex/VK ID)

**Criticality:** High

**TAGS:**
- epic
- feature
- backend
- auth

**Description:**
Цель — добавить социальный вход через Google, GitHub, Yandex и VK ID **в
дополнение** к уже реализованному Telegram-бот magic-link логину (см. skill
`redesign-auth-access-contract`), который этой эпикой НЕ трогается. Эпик
разворачивает заморозку из `.claude/kanban/freeze/backlog-auth-providers.md`
(там был зафиксирован только Google/GitHub на будущее — теперь добавляем
полный набор и реализуем).

Это карточка-интегратор (родитель для подзадач oauth-01…oauth-06), сама по
себе кода не пишет — трекает готовность подзадач и финальную сборку.

**Референс-архитектура:**
proxy-service (FastAPI/Python) — **архитектура для вдохновения, НЕ копипаст
кода** (другой стек). Файлы стоит прочитать как референс модели данных и
потока OAuth:
- `/home/xakki/proxy-service/app-api/api/routers/auth_oauth.py`
- `/home/xakki/proxy-service/app-api/models/social_identities.py`
- `/home/xakki/proxy-service/app-api/models/users.py`

**Техническое решение:**
- `league/oauth2-client` (PHP) + отдельный класс-адаптер на каждый провайдер.
- Свой callback-контроллер, который минтит **существующий** Lexik JWT +
  refresh-cookie и прогоняет **существующий** `GuestUserService::mergeInto`
  для слияния гостевой истории.
- **НЕ использовать** HWIOAuthBundle (лишняя абстракция поверх и так простого
  флоу).
- **НЕ рефакторить** уже поставленный Telegram-флоу (`telegramId` на User) в
  SocialIdentity — Telegram остаётся как есть, отдельным механизмом.

**Модель данных:**
Новая Doctrine-сущность `SocialIdentity` (один User → много провайдеров):
- `id`
- `user_id` (FK → users, ON DELETE CASCADE)
- `provider` (string: `google`|`github`|`yandex`|`vk`)
- `provider_uid` (string)
- `email` (string; verified на момент линковки; синтетический плейсхолдер
  `{provider}:{uid}@{provider}.oauth.local`, если провайдер email не отдал)
- `username` / `display_name` (nullable)
- `created_at`
- `UNIQUE(provider, provider_uid)`

**Ядро корректности — `findOrCreateUser`:**
1. Поиск SocialIdentity по `(provider, provider_uid)` → найден — логиним.
2. Иначе — поиск существующего User по **verified** email → линкуем новую
   SocialIdentity к нему.
3. Иначе — создаём passwordless User + SocialIdentity.
4. Обработка гонок (`IntegrityError` на уникальном индексе) — rollback +
   повторный resolve.
5. Отклонять зарезервированные/неверифицированные email на этапе линковки.

Это самая хрупкая часть эпика — обязательны реальные unit-тесты на все ветки
(new-user, existing-by-social, link-by-verified-email, race, unverified-reject).

**Подзадачи (порядок выполнения):**
1. `oauth-01-foundation.md` — фундамент: league/oauth2-client, SocialIdentity,
   callback-контроллеры, `findOrCreateUser`.
2. `oauth-02-google-github.md` — провайдеры Google + GitHub (готовые league-пакеты).
3. `oauth-03-yandex.md` — провайдер Yandex (кастомный AbstractProvider).
4. `oauth-04-vk-id.md` — провайдер VK ID (PKCE, OAuth 2.1).
5. `oauth-05-login-page.md` — served-страница `/login` (кнопки провайдеров + Telegram).
6. `oauth-06-contract-docs.md` — обновление auth-контракта и разморозка бэклога.

**Definition of Done эпика:**
- Все подзадачи в `ready/`.
- `make test`, `make phpstan`, `make cs-check` — зелёные.
- Skill `redesign-auth-access-contract` обновлён — включает OAuth-флоу.
- `.claude/kanban/freeze/backlog-auth-providers.md` разморожен (см. oauth-06).

**⚠️ HARD GATE:** реальная end-to-end проверка провайдеров требует OAuth-app
credentials (client_id/secret + публичный HTTPS redirect URI на каждый
провайдер), которые **пользователь** зарегистрирует к моменту тестирования.
До этого момента провайдеры — code-complete + unit-tested, но НЕ e2e-verified.
Это ожидаемое, не блокирующее сдачу подзадач состояние.

**Redirect URI конвенция:**
`https://<API_URL>/api/v1/auth/oauth/<provider>/callback`

**Env-переменные:**
`<PROVIDER>_OAUTH_CLIENT_ID` / `<PROVIDER>_OAUTH_CLIENT_SECRET` (у VK ID
секрета нет — только PKCE). Base для `redirect_uri` — `APP_URL`. Секреты — ТОЛЬКО в
`.env.local` (в трекаемом `.env` — пустые плейсхолдеры), по правилам проекта.

**Decisions:**
- `league/oauth2-client` вместо HWIOAuthBundle — меньше магии, полный контроль
  над callback-флоу и интеграцией с существующим JWT/guest-merge.
- Telegram-логин не трогаем и не переносим на SocialIdentity — отдельный
  устоявшийся механизм, риск регрессии не оправдан объёмом эпика.
- E2E-верификация провайдеров зависит от внешних credentials пользователя —
  зафиксировано как hard gate, не как блокер для code-complete подзадач.

**Status:** progress.
