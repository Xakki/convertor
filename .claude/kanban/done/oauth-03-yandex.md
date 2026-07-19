### OAuth-провайдер Yandex (кастомный AbstractProvider)

**Criticality:** Medium

**TAGS:**
- feature
- backend
- auth

**Description:**
Часть эпика `oauth-00-epic.md`, зависит от `oauth-01-foundation.md`.
У Yandex нет готового актуального league-пакета — нужен собственный
`AbstractProvider` (`league/oauth2-client`).

Endpoints:
- authorize: `https://oauth.yandex.ru/authorize`
- token: `https://oauth.yandex.ru/token`
- userinfo: `GET https://login.yandex.ru/info?format=json`, заголовок
  `Authorization: OAuth <token>`

**QUIRK:** userinfo-ответ **не содержит** top-level `email` — нужно брать
`default_email` / перебирать `emails[]`. Email может отсутствовать вовсе →
нужен defensive fallback (синтетический email или флаг «email не получен, для
последующего дособирания»).

Референс (только для аудита логики эндпоинтов, НЕ доверять вслепую — пакет не
поддерживается с 2018 года): `rakeev/oauth2-yandex`.

**Acceptance Criteria:**
- Адаптер корректно маппит `default_email` в `OAuthUserInfo.email`.
- Путь «email отсутствует» покрыт тестом (fallback работает, не падает).
- `make phpstan`, `make cs-check` — зелёные.

**Decisions:**
- Не использовать `rakeev/oauth2-yandex` как зависимость (unmaintained с
  2018 года) — только сверяться по нему при написании собственного адаптера.
- **emailVerified для Yandex:** `true` ТОЛЬКО когда email взят из `default_email`
  (Yandex отдаёт его как подтверждённый primary-адрес аккаунта). Фоллбек на
  первый элемент `emails[]` (когда `default_email` отсутствует, но список
  непуст) — email заполняется, но `emailVerified = false`: Yandex не
  подтверждает явным флагом, что это тот же адрес, поэтому линковка по email
  для него небезопасна. Email отсутствует полностью → `email = null`,
  `emailVerified = false` (foundation уходит по synthetic-email/new-user
  пути в `SocialIdentityResolver`).

**Execution Log:**
- Кастомный `League\OAuth2\Client\Provider\AbstractProvider`-сабкласс
  `App\Service\Oauth\Provider\Yandex\YandexOauth2Provider` (+ `YandexResourceOwner`)
  — `getBaseAuthorizationUrl/getBaseAccessTokenUrl/getResourceOwnerDetailsUrl/
  getDefaultScopes/checkResponse/createResourceOwner` + переопределён
  `getAuthorizationHeaders()` под схему `OAuth <token>` (вместо
  `BearerAuthorizationTrait`).
- `App\Service\Oauth\Provider\YandexProvider implements OauthProviderInterface`
  (key `yandex`, `usesPkce() === false`) — маппинг email-квирка, см. Decisions
  выше. Форма — как `GoogleProvider`/`GithubProvider` (env-инъекция в
  конструкторе, `$client`-seam для тестов).
- Wiring: `App\Service\Oauth\Provider\YandexProvider` в `services.yaml`
  (`YANDEX_OAUTH_CLIENT_ID/SECRET`, уже были задекларированы пустыми в `.env`
  с oauth-01 — правок `.env` не потребовалось; `redirectBaseUrl` = переиспользуемый
  `APP_URL`, см. 2026-07-19 fix).
- Тесты: `tests/Unit/Service/Oauth/Provider/YandexProviderTest.php` (5 тестов,
  mock-Guzzle, без сети) — key/PKCE, authorize-URL (redirect_uri/scope/state),
  happy-path `default_email`, fallback на `emails[0]`, полное отсутствие email;
  плюс перехват заголовка `Authorization` через Guzzle middleware — подтверждает
  схему `OAuth`, не `Bearer`.
- QA: `make phpstan` — 92/92 OK; `make cs` — 1 файл авто-выровнен, `make cs-check`
  — 0/150 чисто; `make test-php-live` — 298 тестов / 1265 assertions OK (3
  pre-existing notices не связаны с этой задачей, воспроизводятся и без
  Yandex-тестов).
- Файлы oauth-01/oauth-02 (`OauthProviderInterface`, `OauthProviderRegistry`,
  `OAuthUserInfo`, `OauthStateStore`, `OauthController`, `GoogleProvider`,
  `GithubProvider`) НЕ менялись.

**Status:** done.
