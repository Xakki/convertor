### Rate limiting per-IP и per-user (KeyDB-backed)

**Criticality:** Medium
**Epic:** [[CNV-54]]

**TAGS:**
- feature
- infra

**Описание:**
Выделено из docs-prod-polish (Stage 6 split, 2026-07-11). Стадия 6 (production polish).
Rate limiting на API: лимиты на частоту запросов одновременно **per-IP** и **per-user**,
счётчики в **KeyDB** (общий стор для всех PHP-инстансов).

**Проблема (историческая, до CNV-34):**
Часть guest-лимитеров уже была в контроллерах, но storage был filesystem
(`cache.rate_limiter`) — per-container, не общий. Не было per-user анти-burst
и грубого пола на `/api/v1/*`.

**Влияние:**
Без KeyDB-backed счётчиков лимиты не шарятся между PHP-инстансами; без per-user
залогиненный клиент обходит guest IP-лимиты.

**Recommendation:**
- KeyDB-backed счётчики (sliding window), ключи по IP и по user_id/guestId.
- Единая точка: `ApiRateLimiter` + `ApiIpRateLimitListener`.

**Acceptance Criteria:**
- [x] Лимиты применяются per-IP и per-user (и guestId для гостя).
- [x] Счётчики KeyDB-backed (`cache.app` / Redis DB0); `when@test` → array.
- [x] Tests/QA: PHPUnit (узкие), `make phpstan`, `make cs`/`cs-check`.

**Decisions:**
- Storage: все лимитеры → `cache.app` (prod/dev); test → `cache.adapter.array`.
- Guests: `anon_*` по IP + по `guest:{id}` если есть.
- ROLE_USER: `user_*` по IP **и** `user:{id}` — reject если любой превышен.
- Новые: `user_convert`/`user_quota` 120/час; `api_ip` 300/мин.
- Анон-числа (`anon_convert` 20/ч, `anon_quota` 60/ч, `anon_telegram_poll` 200/5м) сохранены.

**Status:** progress → implementation done (await review/close). Stage 6.

## Execution Log

**2026-08-02 (implementer):**
- Wired all rate limiters to `cache.app` (KeyDB DB0); `when@test` keeps array pool.
- Added `user_convert`, `user_quota`, `api_ip`.
- `ApiRateLimiter` + `ApiIpRateLimitListener`; ConversionController convert/quota use service.
- Tests: `ApiRateLimiterTest`, `ApiIpRateLimitListenerTest`, `RateLimiterPoolConfigTest` — OK (10).
- `make phpstan` OK; `make cs`/`cs-check` OK.
- Updated skill `api-design` rate-limit note.
