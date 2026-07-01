### Квота: незасиженная таблица plans → всё падает в free

**Критичность:** Low

**TAGS:**
- tech-debt

**Описание:**
Найдено при ревью `backend-hardening-bugs` (2026-06-22). `QuotaService::limitsFor()` при отсутствии плана в таблице `plans` откатывается на free-baseline. Миграция `Version20260419000001` сидит free/basic/pro, поэтому prod/test корректны. Но любое окружение, где сидинг пропущен (новый стенд, частичный rollback, ручная БД), молча урежет basic/pro до free → under-provision платных юзеров без ошибки.

**Проблема:**
- Тихий fallback в free при незасиженной таблице — нет сигнала, что лимиты неверны.

**Решение (черновик):**
- Логировать warning при fallback на free для не-free плана, ИЛИ healthcheck/`make`-проверка наличия сидов, ИЛИ бросать в non-prod.

**Decisions:**
- FROZEN. When done: warning log always + throw/healthcheck in non-prod ; keep FREE_FALLBACK as last resort. Bundle with quota-charge-refund-atomicity.

**Status:** grooming — FROZEN.
