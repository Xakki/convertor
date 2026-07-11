### Ban не отзывает refresh-family мгновенно

**Критичность:** Minor

**TAGS:**
- enhancement
- security

**Описание:**
Найдено при реализации `admin-panel-users` (2026-07-11). Админский ban
(`POST /api/v1/admin/users/{id}/ban` → `setIsActive(false)`) не отзывает
refresh-token family забаненного юзера. Enforcement — только на `/auth/refresh`
(`AuthController::refresh:59` убивает family для inactive), поэтому забаненный
юзер продолжает работать на уже выданном access-JWT до его истечения (≤1ч),
затем блокируется на следующем refresh. Нет `UserChecker`, который резал бы
активный access-JWT сразу.

**Проблема:**
- Между ban и истечением access-JWT (≤1ч) забаненный сохраняет доступ.

**Решение (черновик):**
- При ban проактивно инвалидировать refresh-family (как делает refresh-guard), И/ИЛИ
  завести короткий deny-list активных JWT (jti) в KeyDB + `UserChecker`/listener,
  проверяющий его → мгновенный lockout. Оценить стоимость проверки на каждый запрос.

**Open questions:**
- Нужен ли мгновенный lockout вообще (SLA бана), или ≤1ч приемлемо?
- Если да — deny-list по jti (стоимость на запрос) vs форс-логаут через версию токена (`tokenVersion` на User, в JWT-claim)?

**Status:** grooming.
