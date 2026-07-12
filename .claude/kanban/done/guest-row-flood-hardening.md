# Гость: защита от флуда guest-строк (unauth abuse)

**Критичность:** Medium
**TAGS:** backend, auth, hardening, security

## Проблема

`GuestAuthenticator` (карта `anon-conversion-guest-model`) персистит НОВУЮ guest-строку
в `users` (с `flush`) во время аутентификации — ДО того, как контроллер применит
rate-limit. Следствия:

- Каждый `POST /api/v1/convert` без cookie создаёт guest-User, даже если запрос
  завершится 400 (нет файла) или 429 (лимит). Лимитер стоит в контроллере, ПОСЛЕ
  создания гостя, поэтому guest-строку он не режет.
- `GET /api/v1/quota` вообще без лимитера → неограниченное создание guest-строк на
  любой запрос без cookie.

Итог: per-IP rate-limit НЕ ограничивает рост таблицы `users`. Бот, долбящий `/quota`
или `/convert` без cookie, растит `users` неограниченно (DB-growth / abuse-вектор).
Создание гостя на первом визите — by design (контракт), проблема именно в
unauth-флуде без cookie.

**Decisions (2026-07-11):** Lazy guest + лимитер на `/quota`.

- НЕ персистить guest-строку в `GuestAuthenticator` на no-cookie запрос. Аноним
  аутентифицируется «виртуальным» гостем; строка в `users` материализуется ТОЛЬКО
  при первой успешной постановке `convert`.
- Добавить rate-limit на `GET /api/v1/quota` (сейчас без лимитера).

## Контекст

Найдено при реализации `anon-conversion-guest-model` (backend-A). Вне скоупа той
карты (базовая guest-модель + гейт ai/video). Здесь — отдельная hardening-задача.

## Execution Log (2026-07-12)

Ветка `task/guest-row-flood-hardening`. Реализована ленивая материализация гостя
+ лимитер на `/quota`.

- **`src/Entity/User.php`**: `id` → `?int` (nullable, транзиентный гость id===null),
  `getId(): ?int`, `getUserIdentifier()` guest-aware (гость → guestId, иначе id) с
  guard на непустой identifier (контракт `non-empty-string`).
  - *Enabling change (не в явном тексте спеки, но её дизайн этого требует):* без
    nullable-id `getId() === null` из шагов 4/5 бросал бы Error на транзиентном
    госте. Идиоматичный Doctrine-паттерн.
- **`src/Security/GuestAuthenticator.php`**: транзиентный гость без persist/flush;
  `SelfValidatingPassport(UserBadge(getUserIdentifier(), fn()=>$guest))`; атрибут
  переименован `ATTR_SET_COOKIE` → `ATTR_GUEST_USER` (кладём User-объект только
  для нового транзиентного гостя); удалена неиспользуемая зависимость `$em`.
- **`src/EventListener/GuestCookieResponseListener.php`**: читает User из атрибута,
  эмитит cookie ТОЛЬКО если `getId() !== null` (материализовался за запрос);
  инжектит `GuestTokenService` для подписи.
- **`src/Service/Conversion/ConversionManager.php`**: ленивая материализация —
  `persist($user)` при `getId()===null` перед `persist($conversion)` (после всех
  гейтов + storeInput).
- **`src/Controller/Api/ConversionController.php`**: `/history` guard (транзиентный
  гость → пустой items); лимитер `anon_quota` на `GET /quota` для не-ROLE_USER
  (429 при отказе).
- **`config/packages/rate_limiter.yaml`**: лимитер `anon_quota` (sliding_window,
  60/час) + `when@test` in-memory override.
- **`src/Controller/Api/TelegramWebhookController.php`**: `\assert($userId !== null)`
  перед `authorize()` (findOrCreateUser всегда персистит → id есть; PHPStan).
- **Тесты**: `GuestAuthenticationTest` — quota-тест инвертирован (no cookie/no row),
  convert-тест разбит на негативные кейсы (400/403 → no row/no cookie); новый
  `GuestCookieResponseListenerTest` (транзиентный vs материализованный); расширен
  `ConversionManagerGuestGateTest` (материализация + no-re-persist);
  `AdminAccessControlTest::testAdminApiForbiddenForGuestRole` →
  `testAdminApiUnreachableForGuestJwt` (guest-JWT теперь 401: identifier=guestId
  не резолвится id-провайдером; синтетический сценарий, 403-гейт роли покрыт
  regular-user тестом).

### Верификация (всё через Docker / Makefile)
- `make phpstan` → **OK, 0 errors**.
- `make cs` (0 fixed) + `make cs-check` → **0 to fix**.
- `make test-php-live` (PHPUnit, live тест-БД) → **200 tests, 866 assertions, OK**.
  3 PHPUnit Notices — пре-существующие в нетронутых файлах (мои 21 тест — чисто).

### Feasibility note (202-convert функционально)
Реальный 202-convert in-process невозможен без оверрайда S3Client + messenger-
транспорта (в APP_ENV=test транспорт настоящий, integration-тест skipped, требует
живой стек). Положительная эмиссия cookie покрыта на unit-уровне
(`GuestCookieResponseListenerTest`: материализованный гость → cookie), материализация
— unit-тестом ConversionManager. Функционально покрыты негативные кейсы (no row/no
cookie). Ассерты не ослаблялись.
