### Бэкенд: рантайм-баги и security-харднинг

**Критичность:** High

**TAGS:**
- bug

**Описание:**
Сборник рантайм-багов и security-дыр, выявленных при аудите кода (вкл. uncommitted working tree) 2026-06-20. Каждый пункт — закрыть либо явно отложить с записью.

**Проблема:**
- **Archive без транспорта:** `FileCategory::Archive='archive'` есть в enum, но транспорта `conv_archive` нет → `ConversionManager::dispatch()` бросит исключение на ЛЮБОЙ archive-конверсии.
- **Telegram replay:** `TelegramAuthService::verify()` не проверяет свежесть `auth_date` → перехваченный hash валиден вечно.
- **download getChunks():** `S3Storage::downloadResponse` использует `getChunks()`, которого может не быть в async-aws/s3 v2 → эндпоинт может падать в рантайме.
- **Квота не возвращается при сбое:** списывается на submit, но не возвращается при failure воркера; лимиты захардкожены в сервисе, а не в `Plan`-сущности (рассинхрон источника истины).
- **Refresh-token отсутствует:** правило CLAUDE.md «JWT TTL 1h + refresh 30д в httpOnly cookie» выполнено лишь частично — access TTL 1ч есть, refresh-механики нет.

**Влияние:**
Падения эндпоинтов в рантайме (archive, download), вечно валидный Telegram-hash (security), некорректный учёт квот, несоответствие auth-правилам проекта.

**Решение:**
- **Archive:** убрать/загейтить `Archive` до Стадии 7 либо явно отклонять archive-запросы с 4xx (понятная ошибка вместо 500).
- **Telegram replay:** ввести окно свежести `auth_date` (напр. ≤ 24ч) в `TelegramAuthService::verify()`. **Приоритетно (security).**
- **download getChunks():** проверить наличие `getChunks()` в текущей версии async-aws/s3; починить стриминг (fallback / корректный API).
- **Квота:** возвращать квоту при failure воркера; перенести лимиты в `Plan`-сущность (единый источник истины).
- **Refresh-token:** реализовать refresh (30д, httpOnly cookie) либо явно отложить с фиксацией решения.

**Критерии приёмки:**
- Каждый пункт закрыт ИЛИ явно отложен с записью причины.
- Security-пункты (Telegram replay) закрыты в первую очередь.

**Decisions:**
- Сгруппировано из аудита 2026-06-20; security приоритетнее остального.
