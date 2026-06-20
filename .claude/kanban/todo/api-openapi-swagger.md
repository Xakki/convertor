### OpenAPI/Swagger-документация API (NelmioApiDocBundle)

**Критичность:** High

**TAGS:**
- feature

**Описание:**
Стадия 1. NelmioApiDocBundle установлен (есть в `composer.json` + зарегистрирован в `config/bundles.php`), но фактически не подключён: документация пустая.

**Проблема:**
- Маршрут `/api/doc` НЕ зарегистрирован.
- Конфига `config/packages/nelmio_api_doc.yaml` нет.
- `#[OA\*]`-аннотаций на эндпоинтах — ноль.
- В итоге `/api/doc` ничего не отдаёт, спека не генерируется.

**Влияние:**
Нет машиночитаемой и человекочитаемой документации API — затрудняет интеграцию, тесты и онбординг.

**Решение:**
- Зарегистрировать маршрут `/api/doc` (Swagger UI).
- Добавить конфиг `nelmio_api_doc.yaml`.
- Аннотировать (`#[OA\*]`) все реализованные эндпоинты:
  - `POST /api/v1/convert`
  - `GET /api/v1/convert/{id}/status`
  - `GET /api/v1/convert/{id}/download`
  - `GET /api/v1/convert/history`
  - `GET /api/v1/formats`
  - `GET /api/v1/quota`
  - `POST /api/v1/auth/telegram`

**Критерии приёмки:**
- `/api/doc` отдаёт полную спеку (Swagger UI + JSON).
- Все реализованные эндпоинты задокументированы: request, response, коды ответов.

**Decisions:**
- Выявлено при аудите 2026-06-20: бандл стоит, но не сконфигурирован и без аннотаций.

Siblings: [[api-integration-tests]]
