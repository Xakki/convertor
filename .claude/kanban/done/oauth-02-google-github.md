### OAuth-провайдеры Google + GitHub (drop-in league)

**Criticality:** Medium

**TAGS:**
- feature
- backend
- auth

**Description:**
Часть эпика `oauth-00-epic.md`, зависит от `oauth-01-foundation.md`
(абстракция провайдера, `findOrCreateUser`, callback-контроллер уже есть).

Scope:
- `composer require league/oauth2-google` и `league/oauth2-github`.
- Классы-адаптеры, реализующие интерфейс провайдера из `oauth-01-foundation.md`.
- Google: userinfo-ответ уже содержит `email` + `email_verified` — маппинг
  прямой.
- GitHub: primary email не всегда публичный в основном ответе — нужен
  дополнительный запрос `GET /user/emails` (scope `user:email`), чтобы
  получить verified primary email.

**Acceptance Criteria:**
- Оба провайдера зарегистрированы в provider-registry из `oauth-01-foundation.md`.
- Адаптеры возвращают нормализованный `OAuthUserInfo`.
- Unit-тесты с замоканными ответами провайдеров (в т.ч. GitHub `/user/emails`).
- `make phpstan`, `make cs-check` — зелёные.

**Decisions:**
- GitHub требует отдельного запроса за email — учитывать это в адаптере, а не
  полагаться на основной userinfo-ответ.

**Status:** done.

**Execution Log:**
- `composer require league/oauth2-google league/oauth2-github` (5.0.0 / 3.1.1) —
  via `make composer`. Оба тянут `guzzlehttp/guzzle` (уже был транзитивной
  зависимостью `league/oauth2-client`) — пригодился в тестах как seam для
  MockHandler.
- `App\Service\Oauth\Provider\GoogleProvider` (`key() === 'google'`,
  `usesPkce() === false`) — обёртка над `League\OAuth2\Client\Provider\Google`.
  `fetchUserInfo`: `getAccessToken` → `getResourceOwner` (один HTTP-запрос на
  `openidconnect.googleapis.com/v1/userinfo`, Google не требует доп. вызовов).
  `emailVerified` = `email_verified`-claim, если он есть в ответе; если claim
  отсутствует — считаем true (Google подтверждает email при регистрации
  аккаунта; в практике claim всегда присутствует при scope `email`).
- `App\Service\Oauth\Provider\GithubProvider` (`key() === 'github'`,
  `usesPkce() === false`) — обёртка над `League\OAuth2\Client\Provider\Github`,
  scope `user:email`. `fetchUserInfo` делает СВОЙ запрос `GET /user` (напрямую
  через `getAuthenticatedRequest`/`getParsedResponse`, а не через
  `getResourceOwner()`/`fetchResourceOwnerDetails()`) + отдельный
  `GET /user/emails` — так избегаем встроенного fallback-запроса
  `League\Github` (он берёт первый email без проверки `verified`/`primary`) и
  двойного обращения к `/user/emails`, когда email приватный.
  `providerUid` = числовой GitHub `id` (стабилен, в отличие от `login`).
  Выбор email: primary-запись из `/user/emails` → её `verified`-флаг; если
  primary не найден — первая запись списка; пустой список → `email = null`,
  `emailVerified = false`.
- Оба сервиса — обычные автодискавери-сервисы (`App\:` resource в
  `services.yaml`), поэтому автоматически попадают в `app.oauth_provider` через
  `_instanceof` из oauth-01. Явный блок `arguments` в `services.yaml` инжектит
  `client_id`/`client_secret`/`redirectBaseUrl` из `%env(...)%`
  (`GOOGLE_OAUTH_CLIENT_ID/_SECRET`, `GITHUB_OAUTH_CLIENT_ID/_SECRET` —
  плейсхолдеры уже были заведены в `app-symfony/.env` в oauth-01, менять не
  пришлось; `redirectBaseUrl` = переиспользуемый `APP_URL`, см. 2026-07-19 fix).
  Пустой client_id/secret
  НЕ ломает конструктор (league-провайдер не валидирует опции при создании,
  проверено чтением `AbstractProvider::__construct`) — контейнер не падает при
  незаполненном `.env.local`; сломается только реальный OAuth-запрос, что и
  ожидается для несконфигурированного провайдера.
- Оба класса принимают опциональный 4-й конструкторский параметр `$client`
  (готовый `League\...\Google`/`Github`) — seam для тестов: юнит-тесты
  подставляют инстанс с `Guzzle`-хендлером на `MockHandler`, реальная сеть не
  используется.
- Тесты (все зелёные, без сети): `GoogleProviderTest` (5 тестов — happy path,
  unverified-claim, отсутствие claim → default true, key/usesPkce,
  getAuthorizationUrl НА РЕАЛЬНОМ league-клиенте без seam'а — redirect_uri/
  scope/state в query), `GithubProviderTest` (6 тестов — то же для
  getAuthorizationUrl + публичный primary+verified email, приватный email
  только через `/user/emails`, email есть но не verified →
  `emailVerified=false`, пустой список `/user/emails` → `email=null`,
  key/usesPkce).
- Регистрация в реестре проверена НЕ только тестами (functional-тесты
  контроллера подменяют реестр стабом): `docker exec php bin/console
  debug:container --tag=app.oauth_provider` → оба сервиса
  (`GoogleProvider`/`GithubProvider`) реально попадают в
  tagged_iterator `app.oauth_provider`.
- Quality gate зелёный: `make phpstan` (No errors, без новых ignore),
  `make cs`/`cs-check` (0 fixes после автоформатирования новых файлов),
  `make test-php-live` (293 tests, 1245 assertions, 0 failures/0 errors; 3
  PHPUnit Notices — все ДО-существующие и НЕ из этой карточки, проверено
  прогоном без Provider-тестов: `QueueStatsProviderTest`/
  `ConversionRegistryFallbackTest` — мок без expectations, отдельная
  pre-existing проблема; заведена карточка
  `grooming/phpunit-notice-mock-without-expectations.md`).

**Открытый нюанс (флагирую team-lead, не меняю самовольно):**
- `GoogleProvider`: если claim `email_verified` отсутствует в userinfo-ответе —
  трактую email как verified (`?? true`). Это по прямому указанию задачи
  («Google emails are verified true in practice»), но формально в напряжении с
  инвариантом DTO `OAuthUserInfo` («провайдер обязан ставить false, если НЕ
  подтвердил адрес») — де-факто claim у Google всегда присутствует при scope
  `email`, так что ветка вырожденная, но решение всё равно на усмотрение
  team-lead/ревью.

**Friction/находки по фундаменту oauth-01 (для team-lead):**
- Контракт из oauth-01 не менялся — интерфейс/реестр/контроллер использованы
  как есть, без правок.
