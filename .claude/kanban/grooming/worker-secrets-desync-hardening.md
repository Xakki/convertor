### Единый источник для shared-секретов воркеров/gateway (root .env.local vs app-symfony)

**Criticality:** High

**TAGS:**
- bug-fix
- reliability
- security
- config

**Description:**
Root-причина инцидента «#6 завис». `WORKER_API_TOKEN` и `GATEWAY_INTERNAL_TOKEN`
разъехались между двумя источниками:
- корневой `/home/xakki/convertor/.env.local` — его читает compose и прокидывает
  в контейнеры воркеров и gateway;
- `app-symfony/.env.local` — его читает Symfony через Dotenv.

`docker-compose.yml` (строки 15–17) намеренно НЕ инжектит эти два токена в
env-anchor php/cron — это и есть поверхность рассинхрона: значения в двух файлах
живут независимо, никто не гарантирует их равенство.

**Problem:**
Когда токены разъехались, Symfony отбивала запросы воркеров с **401** →
задачи падали → уходили в DLQ. Внешне выглядело как «задача зависла».

**Impact:**
Полный отказ конвертации (все воркерские запросы к Symfony получают 401).
Отказ тихий: конфиги валидны по отдельности, ошибка проявляется только в
рантайме на relay/result-эндпоинтах. Может повториться незаметно при любой
правке одного из двух файлов.

**Recommendation:**
Единый источник истины для этих shared-секретов. Варианты (выбрать на grooming):
- инжектить корневое значение `WORKER_API_TOKEN`/`GATEWAY_INTERNAL_TOKEN` и в
  env php/cron (тот же `${VAR}` из root `.env.local`), чтобы Symfony и
  воркеры читали одно значение;
- startup-assertion (health/boot-check), падающий, если значения двух сторон
  не совпадают;
- `make`-таргет проверки консистентности (напр. `make check-secrets`),
  сверяющий root `.env.local` и `app-symfony/.env.local`.
Цель — исключить тихий рецидив.

**Acceptance Criteria:**
- Значения `WORKER_API_TOKEN` и `GATEWAY_INTERNAL_TOKEN` физически не могут
  разъехаться между воркерами/gateway и Symfony (единый источник ИЛИ
  fail-fast-проверка расхождения).
- Рассинхрон обнаруживается на старте/в CI, а не рантаймовым 401.
- Секреты остаются в gitignored `.env.local` (плейсхолдеры пустые в трекаемых
  `.env`/`.env.local_example`).
- Tests/QA green: `make phpstan`, `make cs-check`, `make docker-check`.

**Open questions:** *(grooming)*
- Какой из трёх вариантов (общий инжект / boot-assertion / make-check) — или их
  комбинация? Инжект в php-env устраняет корень, но расширяет env-поверхность
  Symfony; проверка дешевле, но оставляет два источника.
- Нужно ли то же самое для остальных потенциально-shared значений (напр. REDIS
  DSN, S3), или строго для этих двух токенов.

**Контекст:** инцидент «#6 stuck» (2026-07-12). Поверхность — `docker-compose.yml`
строки 15–17.

**Status:** grooming.
