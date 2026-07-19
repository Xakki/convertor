### Обновить auth-контракт и разморозить бэклог

**Criticality:** Medium

**TAGS:**
- docs
- auth

**Description:**
Завершающая подзадача эпика `oauth-00-epic.md` — синхронизация документации
и статуса бэклога после того, как OAuth-провайдеры поставлены (oauth-01…05).

Scope:
- Обновить skill `redesign-auth-access-contract`
  (`.claude/skills/redesign-auth-access-contract/SKILL.md`) — задокументировать
  новый OAuth-флоу (start/callback, `SocialIdentity`, `findOrCreateUser`,
  переиспользование guest-merge) рядом с существующим описанием Telegram-флоу.
- Обновить раздел «Аутентификация» в `ROADMAP.md` (строки ~252-255, и строку
  P2-бэклога ~107) — сверить актуальные номера строк перед правкой, ROADMAP
  мог измениться.
- `.claude/kanban/freeze/backlog-auth-providers.md` → переместить в
  `.claude/kanban/done/` (или пометить superseded этим эпиком) — выполняется
  ПОСЛЕ завершения эпика, не раньше.
- Обновить skill `api-design`, если конвенции эндпоинтов изменились
  (например появление `^/api/v1/auth/oauth/*` как отдельного паттерна).

**Acceptance Criteria:**
- Skill `redesign-auth-access-contract` упоминает OAuth-провайдеров.
- `ROADMAP.md` обновлён.
- Карточка `backlog-auth-providers.md` разрешена (перемещена/помечена
  superseded).

**Decisions:**
- Перемещение `backlog-auth-providers.md` — намеренно последний шаг эпика, не
  выполняется до того, как остальные подзадачи (oauth-01…05) реально в
  `ready/`.

**Execution Log:**
- Верифицировал факты по коду (не по карточке): `OauthController` (`/start`,
  `/callback`, PKCE-verifier для VK, error-редиректы `state`/`exchange`/`internal`),
  `SocialIdentity` (UNIQUE(provider,provider_uid), email varchar(180) NOT NULL,
  синтетический плейсхолдер), `SocialIdentityResolver::findOrCreateUser` (3 ветки +
  race-обработка через `resetManager`), все 4 адаптера в `Service/Oauth/Provider/`
  (per-provider emailVerified: Google fail-closed, GitHub через `/user/emails`,
  Yandex только `default_email`, VK всегда false), `app-symfony/.env` (env-конвенция,
  VK без secret), `security.yaml` (firewall `auth` покрывает `^/api/v1/auth/oauth`
  + явный `access_control`), `LoginController`/`app_login`.
- Добавил раздел «OAuth-провайдеры» в `redesign-auth-access-contract/SKILL.md`
  (после Telegram bot login API, перед Frontend) + расширил frontmatter
  `description` OAuth-триггерами. Оставил строку 12 (pairing+poll) как есть —
  добавил только one-line pointer на карту `auth-docs-drift-pairing-poll` (не в
  scope этой карты).
- Обновил `ROADMAP.md`: строка ~107 (Стадия 5, backlog-auth-providers) снята с
  ❄️ frozen → `[x]` implemented (Yandex/VK отмечены как добавленные сверх
  исходного scope); строка ~255 (раздел «Аутентификация») — Google/GitHub/Yandex/VK
  вместо «Стадия 5».
- Добавил `OauthController` в карту эндпоинтов `api-design/SKILL.md` (эндпоинты
  реально перечисляются в этом файле — не skip).
- `backlog-auth-providers.md` НЕ перемещал — по инструкции перемещение остаётся
  последним шагом эпика (делает тимлид при финальной интеграции); ROADMAP больше
  не называет провайдеров "frozen".
- Расхождение с карточкой: карточка (Decisions) говорит "не выполняется до того,
  как остальные подзадачи (oauth-01…05) реально в `ready/`" — по факту все 5 карт
  УЖЕ лежат в `ready/`, но внутри файлов `Status: progress` (не синхронизировано
  с директорией). Код всех 4 провайдеров при этом уже реализован и верифицирован
  по исходникам — задокументировал как implemented, следуя коду, а не
  Status-полю карточек.

**Status:** done.
