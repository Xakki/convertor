### Cron авто-удаления файлов через 24ч (Symfony Scheduler)

**Criticality:** Medium

**TAGS:**
- feature
- tech-debt

**Описание:**
Выделено из docs-prod-polish (Stage 6 split, 2026-07-11). Стадия 6 (production polish),
не срочно.

Cron через **Symfony Scheduler** удаляет через 24ч и S3-объекты, и строки БД **вместе**
(единый источник логики). Покрывает input- и result-бакеты
`${S3_BUCKET_PREFIX}-inputs` / `${S3_BUCKET_PREFIX}-results`.

**Проблема:**
Загруженные файлы и результаты не удаляются — накапливается устаревший мусор в S3
и записи в БД растут неограниченно.

**Влияние:**
Не production-safe: раздувание S3 и таблиц, рост затрат, деградация запросов.

**Recommendation:**
- PHP-команда (Symfony Scheduler cron), которая единым проходом удаляет S3-объекты + строки БД.
- Порог 24ч; оба бакета (inputs + results).

**Acceptance Criteria:**
- Cron-job удаляет объекты старше 24ч из `${S3_BUCKET_PREFIX}-inputs` и `-results`
  **и** соответствующие строки БД в одном проходе.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- 24h cleanup = **Symfony Scheduler cron**: PHP-команда удаляет S3-объекты + строки БД
  вместе (single source of logic) — **НЕ** S3 lifecycle policy (явное решение пользователя,
  2026-06-20).
- Покрывает input + result бакеты `${S3_BUCKET_PREFIX}-inputs` / `-results`.
- **Порог — ПЕР-КОНВЕРСИЯ**, разрешается по приоритету осей (решение пользователя
  2026-07-12; вместо хардкода 24ч). Первая ось с настроенным значением > 0 выигрывает:
  `статус → тариф → категория → глобальный дефолт`. `0`/пусто = ось пропускается.
  - статус: Failed/Expired = 24ч; тариф: guest = 24ч, paid = 720ч, free = 0 (провал);
    категория: video = 48ч; дефолт `FILE_RETENTION_HOURS` = 240ч.
  - Итог: paid+video = 720 (тариф), free+video = 48 (категория), guest = 24, любой
    Failed = 24, остальное = 240. Все значения env-тюнятся (7 переменных).
  - Тариф из `User`: `isGuest`→guest; иначе `plan==='free'`→free; иначе→paid.
- **Статус `Processing` из очистки исключён** (in-flight; зависшие ловит
  отдельный stuck-алерт `ConversionRepository::findStuck()`).
- Отбор по `Conversion.createdAt`; расписание — `every 1 hour`.

**Реализация:**
- `App\Schedule` (`#[AsSchedule]`, stateful) → `FileCleanupMessage` →
  `FileCleanupMessageHandler` → `FileCleanupService::run()` (резолвер осей +
  грубый пре-фильтр `now - min(порогов)` + курсор по `id` → без зацикливания).
- `ConversionRepository::findExpiredCandidates()`, `S3Storage::deleteObject()`.
- 7 env-переменных `FILE_RETENTION_HOURS[_STATUS_*|_TARIFF_*|_CATEGORY_*]`
  (root `.env` + эффективный `app-symfony/.env` + дефолты в `services.yaml`).
- Cron-воркер: `[program:app-cron]` в `docker/php/supervisor.app.ini`
  (`messenger:consume scheduler_default`).
- QA: phpstan OK, cs-check clean, PHPUnit 195 зелёных (cleanup: 3 теста, 29 assert —
  вкл. пер-осевые кейсы + инвариант «пропуск-в-батче двигает курсор»).
- Развёрнуто: контейнеры перезапущены, `app-cron` RUNNING, DI собран с картами
  осей, расписание живо (env-переменные доходят до рантайма).

**Status:** ready (реализовано + ревью пройдено; ждёт финального подтверждения).
