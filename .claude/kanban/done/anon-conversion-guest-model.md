### Анонимная конвертация + guest-User по cookie (гейт ai/video)

**Критичность:** High

**TAGS:**
- feature
- backend
- auth

**Описание:**
Разрешить конвертацию без логина ДЛЯ ВСЕХ категорий, КРОМЕ `ai` и `video` (эти требуют Telegram-логин). Сейчас `POST /api/v1/convert` под `ROLE_USER`, `Conversion.user` NOT NULL, анонимного доступа нет. Модель анонима — guest-User по httpOnly-cookie (owner остаётся NOT NULL, владеет guest). При логине история guest'а перепривязывается к реальному User.

**Зона (backend-A):**
- `User`: поля `isGuest` (bool) + `guestId` (nullable unique); миграция Doctrine.
- Cookie `guest_id` (httpOnly, Secure, SameSite=Lax, TTL 30д, HMAC-подпись).
- `GuestAuthenticator` в firewall `api` ПОСЛЕ jwt: нет Bearer → найти/создать guest-User по cookie, аутентифицировать как `ROLE_GUEST`, выставить cookie.
- `role_hierarchy: ROLE_USER: [ROLE_GUEST]`.
- security.yaml: `convert`/`status`/`download`/`history`/`quota` — под `ROLE_GUEST`.
- Гейтинг в `ConversionController::convert`/`ConversionManager`: `isAi || category===Video` и не-`ROLE_USER` → HTTP 403 `{error:"auth_required", message:"Войдите через Telegram для ai/video конвертаций"}`.
- `config/packages/rate_limiter.yaml`: guest по IP (стартово 20/час); применять в convert для guest. Размер free 50MB (сверить).
- `GET /quota` для guest (не 401): `plan:"guest"`, `ai_conversions:0`.
- `GuestUserService::mergeInto(User $real, string $guestId)` — переназначить `Conversion.user` guest'а на реального, деактивировать guest, погасить cookie. **Вызывается из [[telegram-bot-login-flow]] (backend-B) в poll.**

**Контракт:** ОБЯЗАТЕЛЬНО читать skill `redesign-auth-access-contract` (разделы «Guest-модель», «Гейтинг конвертации», «Rate-limit и размеры», «Anon status/download», «Роли и firewall»).

**AC:**
- Аноним конвертит не-ai/не-video; ai/video → 403 auth_required; guest владеет и скачивает свой результат по cookie; rate-limit по IP; quota для guest; `mergeInto` готов.
- `make phpstan` 0, `make cs` чисто, `make docker-check` ок, миграция применяется, тесты (guest-аутентификация, гейт 403, merge).

- 2026-07-10: реализовано (backend-A, worktree, off main), интегрировано в эпик-ветку (merge 9452238). Security-ревью guest-поверхности — чисто (нет critical/high): HMAC constant-time, эскалация guest→user невозможна, ai/video-гейт на серверных isAi/category до quota/S3, OCR-обход невозможен, mergeInto берёт только собственный cookie. Гейт зелёный (phpstan 0, cs, docker-check, 148 тестов). Residual: guest-row flood → карта [[guest-row-flood-hardening]]; guest-secret по умолчанию APP_SECRET (deploy: прод обязан override); nginx XFF override — ops-проверка; миграция применена только к тест-БД (dev — deploy-шаг).

**Status:** ready — реализовано, интегрировано, security+gate зелёные. Ждёт финального аппрува/мержа (ветка общая с эпиком).

Siblings: [[telegram-bot-login-flow]] · [[upload-ui-bot-auth-rework]] · [[upload-conversion-ui]]
