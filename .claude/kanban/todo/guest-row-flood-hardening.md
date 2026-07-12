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
