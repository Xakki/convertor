### Кабинет: действия над конверсиями (повтор / удаление)

**Criticality:** Low
**Epic:** [[CNV-48]]

**TAGS:**
- feature
- frontend
- backend

**Description:**
Выделено из карточки `user-dashboard-page` (2026-07-22): первая итерация
кабинета — только просмотр (история + квоты + аккаунт). Действия над
конверсиями отложены сюда.

**Problem:**
Из личного кабинета нельзя повторить конверсию с теми же параметрами и нельзя
удалить запись/файлы конверсии.

**Recommendation:**
Добавить бэкенд-эндпоинты и UI-кнопки в таблице истории на `/dashboard`:
- повтор конверсии (переиспользовать исходник из S3 `-inputs`, если не истёк
  retention → иначе понятная ошибка 410);
- удаление конверсии (запись + объекты в S3 inputs/results).

**Acceptance Criteria:**
- Эндпоинт повтора: создаёт **новую** строку Conversion; проверка владельца;
  учёт квот/лимитов (повтор = обычная конверсия по квоте); постановка в нужный
  stream; 410 если исходник истёк.
- Эндпоинт удаления: **hard delete** записи + объектов S3 (inputs/results);
  проверка владельца.
- Кнопки повтор/удалить в истории на `/dashboard`, с подтверждением удаления.
- Действия доступны только `ROLE_USER` (не `ROLE_GUEST`).
- Tests/QA green: targeted PHPUnit + `make phpstan` + `make cs-check`
  (полный `make test` — на epic-gate CNV-48).

**Decisions:**
- (2026-08-01) Retry = новая строка Conversion (не reuse существующей).
- Hard delete строки + объектов S3 (не soft-delete).
- Retry списывает/учитывает квоту как обычная конверсия.
- Только `ROLE_USER` (гостю действия недоступны).
- (2026-08-02) Retry копирует S3-объект в новый ключ (не shared FileStorage) —
  независимый lifecycle при delete.
- (2026-08-02) Path-safe ключи: префикс `inputs/`|`results/`, без `..`.

**Контекст:** родительская карта `.claude/kanban/progress/user-dashboard-page.md`
(или done — сверить при старте).

**Status:** ready.

## Execution Log
- (2026-08-02) start: todo→progress на `epic/CNV-48`.
- (2026-08-02) backend: `ConversionManager::retryConversion` / `deleteConversion`;
  API `POST /convert/{id}/retry`, `DELETE /convert/{id}`; security.yaml ROLE_USER;
  UI кнопки (chore); unit+functional тесты; phpstan/cs-check OK.
- (2026-08-02) QA: targeted PHPUnit 14/14, phpstan OK, cs-check OK → test→ready.
