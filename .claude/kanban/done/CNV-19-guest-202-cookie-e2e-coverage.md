# Гость: e2e-тест success-пути 202 (материализация + cookie)

**Критичность:** Low (coverage-gap, не баг)
**Epic:** [[CNV-48]]
**TAGS:** backend, tests, auth, guest

## Проблема

Ленивая guest-модель (`guest-row-flood-hardening`) полагается на **инвариант
object-identity**: один и тот же `User`-инстанс проходит цепочку
`GuestAuthenticator` (request-атрибут `_guest_user`) → `#[CurrentUser]` →
`ConversionManager::persist($user)` → `GuestCookieResponseListener` (эмитит
cookie, если `id!==null`). Сейчас эта склейка покрыта только **разрозненными
unit-тестами**; ни один тест не гоняет полный success-путь 202 для гостя.

Риск: будущий рефактор, ломающий object-identity (напр. loader, который
перезагружает гостя по id), запишет строку в `users`, но НЕ выставит cookie —
и **все текущие тесты останутся зелёными**.

## Что сделать

Functional-тест гостевого convert (напр. `jpg→txt` или другой валидный
free non-ai/non-video пара), проверяющий одним прогоном:
- ответ **202**;
- в ответе выставлена подписанная cookie `guest_id`;
- в `users` появилась **ровно одна** новая guest-строка.

**Decisions:** e2e success-путь 202 (guest-материализация + cookie `guest_id` +
ровно одна guest-строка) гоняем в стеке `make test-e2e` на реальном KeyDB с
изолированным тест-префиксом стримов/ключей (НЕ in-memory override) —
консистентно с e2e-ws-transport-stack. Живой Messenger-транспорт использует
изолированный тест-стрим, чтобы не мусорить dev.

## Контекст / грабли

- S3 фейкается в `WebTestCase` через `container->set(S3Storage::class, …)`
  (образец — `FileCleanupServiceTest`).
- Реальная сложность: проект намеренно держит **живой** Messenger-транспорт
  (`conv+redis://` → KeyDB) в `APP_ENV=test`, поэтому `dispatch()` требует
  доступный KeyDB ИЛИ пер-тестовый override транспорта на `in-memory://`.
- Существующий `ConversionApiIntegrationTest` покрывает 202 только для
  Bearer/ROLE_USER на живом e2e-стенде (`#[Group('integration')]`), не для гостя.

Найдено ревью карты `guest-row-flood-hardening` (2026-07-12).

## Execution Log

- 2026-08-02: todo→progress; тест `GuestConvertCookieE2eTest` — WebTestCase jpg→txt,
  fake S3, живой Messenger на изолированный стрим `conv.__guest_cookie_e2e__`
  (KeyDB db3 тест-стенда), asserts 202 + HMAC `guest_id` + ровно +1 guest row.
- 2026-08-02: `phpunit GuestConvertCookieE2eTest` → OK (1 test, 13 assertions);
  `make TEST=1 cs` → OK; progress→test→ready.
