### Ban не отзывает refresh-family мгновенно

**Criticality:** Minor

**TAGS:**
- enhancement
- security

**Description:**
Найдено при реализации `admin-panel-users` (2026-07-11). Админский ban
(`POST /api/v1/admin/users/{id}/ban` → `setIsActive(false)`) не отзывает
refresh-token family забаненного юзера. Enforcement — только на `/auth/refresh`
(`AuthController::refresh:59` убивает family для inactive), поэтому забаненный
юзер продолжает работать на уже выданном access-JWT до его истечения (≤1ч),
затем блокируется на следующем refresh. Нет `UserChecker`, который резал бы
активный access-JWT сразу.

**Problem:**
- Между ban и истечением access-JWT (≤1ч) забаненный сохраняет доступ.

**Impact:**
Забаненный пользователь может сохранять доступ по уже выданному access-JWT до его истечения.

**Recommendation:**
Сохранить карточку замороженной; не начинать реализацию до отдельного решения о разморозке, после которого применить зафиксированный подход `tokenVersion` + `UserChecker`.

**Acceptance Criteria:**
- Карточка остаётся замороженной; реализация и изменение lifecycle не начинаются до отдельной разморозки.
- После разморозки ban немедленно отзывает access-JWT и refresh-family согласно зафиксированному решению.

**Decisions (2026-07-11):** мгновенный lockout через `tokenVersion` + `UserChecker`.
- Добавить колонку `tokenVersion` (int, default 0) на сущность `User`; включать её в claims access-JWT при выпуске (LexikJWT payload-enricher / `JWTCreatedEvent`).
- `UserChecker` (или authenticated-listener) сравнивает `tokenVersion` из JWT-claim с `User.tokenVersion`; при несовпадении — отказ (мгновенно режет уже выданные access-JWT). Стоимость: требует загрузки User из БД на запрос.
- Ban (`POST /api/v1/admin/users/{id}/ban`) инкрементирует `User.tokenVersion` И проактивно инвалидирует refresh-family (как refresh-guard) → полный мгновенный lockout.
- Нужна миграция Doctrine на новую колонку.

**Status:** grooming.
