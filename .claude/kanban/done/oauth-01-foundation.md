### OAuth-фундамент: league/oauth2-client, SocialIdentity, callback, findOrCreateUser

**Criticality:** High

**TAGS:**
- feature
- backend
- auth

**Description:**
Базовая абстракция, в которую подключаются все конкретные провайдеры
(oauth-02…oauth-04). Часть эпика `oauth-00-epic.md`. Контекст и техническое
решение — там же; здесь — конкретный scope этой подзадачи.

Scope:
- `composer require league/oauth2-client`.
- Doctrine-сущность `SocialIdentity` + миграция (поля и уникальный индекс —
  см. `oauth-00-epic.md` → «Модель данных»).
- Provider-registry/конфиг, env-driven: base для `redirect_uri` = `APP_URL` +
  per-provider client id/secret (плейсхолдеры провайдеров пока пустые — сами
  провайдеры появятся в oauth-02…04, но конфиг-контракт задаётся здесь).
- Контроллер `GET /api/v1/auth/oauth/{provider}/start`:
  - генерирует CSRF `state`, хранит в KeyDB/кеше с TTL 10 минут;
  - для VK ID дополнительно генерирует и сохраняет PKCE `code_verifier`
    (и позже — `device_id`, приходящий с VK на callback).
- Контроллер `GET /api/v1/auth/oauth/{provider}/callback`:
  - валидирует одноразовый `state`;
  - обменивает `code` на токен;
  - нормализует ответ провайдера в DTO
    `OAuthUserInfo{provider_uid, email, email_verified, username, display_name}`;
  - вызывает `findOrCreateUser`;
  - минтит JWT + refresh-cookie (существующий механизм Lexik);
  - прогоняет существующий guest-merge (`GuestUserService::mergeInto`);
  - редиректит на `/`.
- `SocialIdentityRepository` + сервис `findOrCreateUser` с логикой
  link/create/race — см. «Ядро корректности» в `oauth-00-epic.md`.
- `security.yaml`: добавить `^/api/v1/auth/oauth` в stateless auth firewall +
  PUBLIC access_control (по образцу существующей записи `^/api/v1/auth`).

Конкретного провайдера в этой подзадаче ещё нет — для теста флоу можно
застабить один провайдер-адаптер (не для продакшна, только чтобы прогнать
callback end-to-end в тесте).

**Acceptance Criteria:**
- Сущность `SocialIdentity` + миграция существуют и накатываются.
- Контроллеры `start`/`callback` подключены в роутинг.
- `findOrCreateUser` покрыт unit-тестами: new-user, existing-by-social,
  link-by-verified-email, race (IntegrityError → re-resolve),
  unverified-reject.
- `make phpstan`, `make cs-check` — зелёные.

**Decisions:**
- Это точка расширения для остальных провайдеров — интерфейс адаптера
  провайдера фиксируется здесь и не меняется в oauth-02…04 без ревизии этой
  карточки.
- CSRF `state` и PKCE `code_verifier`/`device_id` — в KeyDB/кеше, не в
  `$_SESSION` (см. предупреждение в oauth-04 про чужой референс-пакет).

**Status:** done.

**Execution Log:**
- `composer require league/oauth2-client` (2.9.0) — via `make composer`.
- Сущность `App\Entity\SocialIdentity` (+ `SocialIdentityRepository`):
  user FK (nullable=false, ON DELETE CASCADE), provider/providerUid/email
  (NOT NULL; синтетический `{provider}:{uid}@{provider}.oauth.local` без email),
  username/displayName nullable, createdAt, UNIQUE(provider, provider_uid).
- Миграция `Version20260719141857` (сгенерирована `make migrate-diff`, обрезана
  до одной таблицы `social_identities` — автоген заодно поймал несвязанный дрифт
  схемы, не включён; та же политика, что Version20260718115536). Накатана на dev.
- DTO `App\DTO\OAuthUserInfo` (readonly).
- Абстракция провайдера: `App\Service\Oauth\OauthProviderInterface`
  (`key`/`usesPkce`/`getAuthorizationUrl`/`fetchUserInfo`) + реестр
  `OauthProviderRegistry` (tagged-итератор `app.oauth_provider`, пуст в oauth-01)
  + `UnknownOauthProviderException`. Seam для PKCE (VK) и кастом-userinfo (Yandex)
  заложен: провайдеры — самодостаточные сервисы, контроллер/реестр не меняются.
- State-store `OauthStateStore` (KeyDB db1 sessions, TTL 600, one-time GET+DEL
  через Lua; payload {provider, codeVerifier?}) + `OauthStateData` +
  `InvalidOauthStateException`. Зеркалит `TelegramLoginCodeStore`.
- Контроллер `App\Controller\Api\OauthController` — `GET .../{provider}/start`
  (404 если провайдер не сконфигурирован) + `.../{provider}/callback`. Сессия —
  ТЕМИ ЖЕ сервисами, что Telegram-callback: `RefreshTokenService::issueFamily`
  + `RefreshCookieFactory` + guest-merge `GuestUserService::mergeInto` (JWT в URL
  НЕ уходит — SPA берёт access через /auth/refresh). Ошибки → 302
  `/login?oauth_error=...` (страница /login придёт в oauth-05).
- `SocialIdentityResolver::findOrCreateUser` (ядро корректности): по-social →
  link-by-verified-email → create passwordless. Гонки: UNIQUE(provider,uid) →
  `UniqueConstraintViolationException` → `ManagerRegistry::resetManager()` →
  повторный резолв на СВЕЖЕМ EM (паттерн `ConversionResultPersister`). Линковка
  по email — ТОЛЬКО verified+не-зарезервированный; users.email заполняется лишь
  verified-адресом (инвариант защиты от угона).
- `security.yaml`: добавлена явная PUBLIC-запись `^/api/v1/auth/oauth` (firewall
  `auth` и запись `^/api/v1/auth` уже покрывают префикс — отдельный firewall НЕ
  заводился).
- Тесты (все зелёные): unit `SocialIdentityResolverTest` (6 веток incl. race с
  проверкой свежего EM), unit `OauthProviderRegistryTest`, functional
  `OauthControllerTest` (стаб-провайдер: start→redirect, callback→refresh-cookie,
  404, invalid-state→login-error). Плюс `#[Group('integration')]`
  `SocialIdentityResolverDbTest` (реальная convertor-test БД): round-trip
  create+re-resolve-by-social, реальный UNIQUE(provider,provider_uid), реальный
  ON DELETE CASCADE — доказывает, что констрейнт race-пути существует в схеме, а
  не только эмулируется исключением. Группа `integration` исключена из дефолтного
  прогона (как `GuestUserServiceDbTest`) → `--group integration`.
- Quality gate зелёный: `make phpstan` (No errors), `make cs`/`cs-check`
  (0 fixes), `make test-php-live` (282 tests OK, 0 failures/errors/deprecations).

**Drift замечен (для team-lead):**
- Env-имена провайдеров: карта эпика говорит `<PROVIDER>_OAUTH_CLIENT_ID`, а
  трекаемый ROOT `.env` имел `OAUTH_<PROVIDER>_CLIENT_ID` — и это МЁРТВЫЕ
  плейсхолдеры: `docker-compose.yml` (anchor `x-app-env`) их НЕ прокидывает в php,
  а Symfony `%env()%` читает `app-symfony/.env*`, не root `.env`. Перенесены в
  `app-symfony/.env` с канонической конвенцией `<PROVIDER>_OAUTH_CLIENT_ID/_SECRET`
  (VK — только client_id, PKCE, без секрета); base для `redirect_uri` — `APP_URL`.
  В root `.env` мёртвый блок заменён пояснением. Секреты — в `app-symfony/.env.local`.
