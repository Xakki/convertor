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

**Status:** todo (Stage 6, не срочно).
