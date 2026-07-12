### Rate limiting per-IP и per-user (KeyDB-backed)

**Criticality:** Medium

**TAGS:**
- feature
- infra

**Описание:**
Выделено из docs-prod-polish (Stage 6 split, 2026-07-11). Стадия 6 (production polish),
не срочно. Самый ценный из четырёх пунктов split'а — базовая защита от abuse.

Rate limiting на API: лимиты на частоту запросов одновременно **per-IP** и **per-user**,
счётчики в **KeyDB** (общий стор для всех PHP-инстансов).

**Проблема:**
Сейчас нет никакой защиты от abuse: один клиент/IP может завалить конвертацию запросами,
обойти квоты через параллельные запросы, устроить DoS на воркеры и S3.

**Влияние:**
Не production-safe: нет ограничения злоупотреблений, воркеры и S3 под нагрузкой без throttle.

**Recommendation:**
- KeyDB-backed счётчики (sliding window / token bucket), ключи по IP и по user_id.
- Интеграция на уровне API (Symfony) — единая точка перед dispatch задач.

**Acceptance Criteria:**
- Лимиты применяются per-IP и per-user.
- Счётчики KeyDB-backed (видны всем инстансам, не per-container).
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- KeyDB-backed rate limiting, per-IP **и** per-user (из docs-prod-polish).
- Наивысший приоритет среди четырёх пунктов split'а (rate-limit first).

**Status:** todo (Stage 6, не срочно).
