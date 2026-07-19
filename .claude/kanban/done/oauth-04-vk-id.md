### OAuth-провайдер VK ID (PKCE, OAuth 2.1)

**Criticality:** Medium

**TAGS:**
- feature
- backend
- auth

**Description:**
Часть эпика `oauth-00-epic.md`, зависит от `oauth-01-foundation.md`.
VK ID — свой протокол (OAuth 2.1 + обязательный PKCE), готового
актуального league-пакета нет — собственный `AbstractProvider`.

Endpoints:
- authorize: `https://id.vk.com/authorize`
- token: `POST https://id.vk.com/oauth2/auth`
  (параметры `grant_type`/`code`/`redirect_uri`/`client_id`/`device_id`/
  `code_verifier`/`state` — **без** `client_secret`, у VK ID его нет)
- userinfo: `POST https://id.vk.com/oauth2/user_info`

**PKCE обязателен (S256):** `code_challenge` на authorize,
`code_verifier` на token-обмене.

`device_id` возвращается VK на callback вместе с `code` и должен быть
прокинут в token-обмен — хранить его рядом с `code_verifier` в
Symfony-сессии/кеше (**НЕ в сыром `$_SESSION`** — это грабля из чужого
референса, см. Decisions).

Email не гарантирован → тот же fallback, что и у Yandex (`oauth-03-yandex.md`).

Референс (эндпоинты и PKCE-механика корректны, но **не импортировать как
есть** — там завязка на `$_SESSION`, которую нужно убрать):
`yasinovsky/oauth2-vkontakte` v3.0.3.

**Acceptance Criteria:**
- PKCE + `device_id` прокинуты через весь флоу — покрыто тестом с
  замоканным HTTP-обменом.
- userinfo-запрос (`POST`, не `GET`) корректно замаплен в `OAuthUserInfo`.
- Путь «email отсутствует» покрыт тестом.
- `make phpstan`, `make cs-check` — зелёные.

**Decisions:**
- `yasinovsky/oauth2-vkontakte` — только референс по эндпоинтам/PKCE, его
  `$_SESSION`-связку не переносим: state/verifier/device_id идут через
  инфраструктуру хранения из `oauth-01-foundation.md` (KeyDB/кеш).

**Status:** done.

**Execution Log:**
- Фундамент `oauth-01` уже полностью поддерживал PKCE и `device_id` без
  изменений: `OauthProviderInterface::fetchUserInfo(array $callbackParams, ?string $codeVerifier)`
  получает СЫРЫЕ query-параметры callback'а (значит, и `device_id`, и `state`
  — они приходят в query вместе с `code`), а `getAuthorizationUrl(string $state, ?string $codeVerifier)`
  уже принимает `codeVerifier`. Контроллер уже генерирует `code_verifier` при
  `usesPkce() === true` и кладёт его в `OauthStateStore`. **Интерфейс НЕ
  менялся** — правок в `OauthProviderInterface`/`OauthController` не
  потребовалось.
- `App\Service\Oauth\Provider\Vk\VkIdOauth2Provider` — кастомный
  `league/oauth2-client` `AbstractProvider` (2.9.0, у него есть встроенный
  PKCE-хук `getPkceMethod()`, но он сам генерирует `code_verifier` и не даёт
  передать готовый снаружи — поэтому НЕ используется; PKCE собран вручную):
  - `getAuthorizationParameters()` — переопределён: принимает `code_verifier`
    опцией (не уходит в query), считает `code_challenge` = base64url(SHA256)
    вручную, добавляет `code_challenge_method=S256`.
  - `getAccessTokenRequest()` — вырезает `client_secret` из `$params` (у VK ID
    его нет вовсе, PKCE — полная замена; базовый класс всегда кладёт секрет в
    params, единственная точка его убрать — этот хук).
  - `fetchResourceOwnerDetails()` — переопределён целиком: `POST
    /oauth2/user_info` с `access_token`+`client_id` в теле формы (базовый
    класс жёстко хардкодит `GET`+Bearer).
  - `getBaseAccessTokenUrl()` → `POST https://id.vk.com/oauth2/auth`.
  - Scopes (в карточке не были явно заданы) — выбраны `email phone
    vkid.personal_info` по официальной документации VK ID.
  - `checkResponse()` — тот же паттерн, что у Yandex (`error`/`error_description`).
- `App\Service\Oauth\Provider\Vk\VkResourceOwner` — типизированный ридер
  ответа `{"user": {...}}` (`user_id`/`id` fallback, `first_name`,
  `last_name`, `email`, `phone`).
- `App\Service\Oauth\Provider\VkProvider` — адаптер (`key() === 'vk'`,
  `usesPkce() === true`). `device_id`/`state` читаются из `$callbackParams`
  (query callback'а) и прокидываются опциями в `getAccessToken()` — грант
  мержит их в тело token-запроса поверх `client_id`/`redirect_uri`
  (`client_secret` вырезается хуком выше). EMAIL: VK ID не отдаёт verified-флаг
  для email в userinfo (в отличие от Yandex `default_email`) → `emailVerified`
  **всегда `false`**, даже если email присутствует — не изобретаем
  подтверждённый адрес.
- Wiring: `config/services.yaml` — `App\Service\Oauth\Provider\VkProvider`
  (`$clientId` из `VK_OAUTH_CLIENT_ID`, БЕЗ `$clientSecret` — конструктор его
  не принимает). `VK_OAUTH_CLIENT_ID` в `app-symfony/.env` уже был добавлен в
  `oauth-01` (пустой плейсхолдер) — новых env-переменных не потребовалось,
  секрета VK не заводилось нигде (у VK ID его нет).
- Тесты `tests/Unit/Service/Oauth/Provider/VkProviderTest.php` (5, mock-Guzzle,
  без реальной сети, история запросов через `GuzzleHttp\Middleware::history`):
  authorize-URL содержит `code_challenge`/`code_challenge_method=S256`/
  `redirect_uri`/`state`/`scope`, `code_verifier` НЕ в query; token-обмен —
  тело содержит `device_id`+`code_verifier`+`state`, БЕЗ `client_secret`;
  userinfo-запрос — `POST`; email отсутствует → `null`/`false`; фоллбек
  `id` вместо `user_id`.
- Quality gate зелёный: `make phpstan` (No errors), `make cs`/`cs-check`
  (0 fixes/0 issues), `make test-php-live` (303 tests OK, 0
  failures/errors — было 282 до этой карточки; 3 PHPUnit Notices
  унаследованы от прочих тестов, не от VK).
