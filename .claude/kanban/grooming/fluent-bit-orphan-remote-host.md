### fluent-bit на remote-хостах — сломанные make-таргеты, orphan-сайдкары, drift в доке

**Criticality:** Medium

**TAGS:**
- tech-debt
- logging
- fluent-bit
- remote-workers

**Description:**
Коммит `cab0124` («fix fluent-logging - use shared fluent») убрал сервис `fluent-bit` из
`docker/fluent-logging.yml` — проект перешёл на host-level shared fluent-bit. Доставка логов с
uBook **работает**: гитигнорнутый `.env.local` задаёт `EXT_FLUENT_PORT=0.0.0.0:24224`, orphan-сайдкар
`convertor-remote-xbook-fluent-bit` слушает этот порт, записи подтверждены в Graylog
(`source: 192.168.10.12`, "connected to gateway, registered as convertor-remote-xbook-worker-data").
Проверено 2026-07-29.

Грабли исходной ложной тревоги: `docker compose config` по голому ssh без `make` рендерит только
трекаемый `.env` (`EXT_FLUENT_PORT=127.0.0.1:10094`) — `.env.local` попадает в compose только через
`include .env.local` + `export` в корневом Makefile.

**Problem:**
1. `fluent-bit` убран из compose коммитом `cab0124`, но `make fluent-up`/`fluent-restart`/
   `fluent-logs` (`workers/Makefile`) не обновлены — СЛОМАНЫ на любом хосте (`no such service:
   fluent-bit`). На uBook сайдкар держится только как неуправляемый orphan; если он остановится —
   нет поддерживаемого способа вернуть доставку логов.
2. Архитектурная нестыковка: на saFin — host-level `shared-fluent-bit`
   (`/home/soft/shared/docker-compose.yml`, `127.0.0.1:24224`), на remote-хостах — унаследованный
   per-project orphan-сайдкар. Нет описанного способа завести логи на НОВОМ remote-хосте.
3. Безопасность: сайдкар uBook слушает `0.0.0.0:24224` (открытый intake) vs `127.0.0.1:24224` на
   saFin. Должно быть loopback-only везде.
4. Лишние orphan'ы на saFin: `xakki-convertor-fluent-bit` (`127.0.0.1:10094`) и
   `xakki-convertor-logrotate` — избыточны, `.env.local` уже указывает на shared fluent (`:24224`).
5. Хрупкость: дефолт трекаемого `.env` — `EXT_FLUENT_PORT=127.0.0.1:10094`, ни на что не
   указывает на remote-хосте. Закомментируй override в `.env.local` — логи тихо пропадут
   (`fluentd-async: "true"` без ошибки).
6. Doc drift: `docs/workers-remote-deploy.md` утверждает про `include:
   docker/fluent-log/docker-fluent.yml` в compose — grep подтверждает, что это не так. Сабмодуль
   `docker/fluent-log` (v0.1.4) проинициализирован, но `COMPOSE_FILE` на него не ссылается.
7. Отклонение от кросс-проектного стандарта (скилл `ai-agents-skills:fluent-logging`): он
   предписывает проектный сайдкар через `include:` сабмодуля `docker-fluent.yml` в
   `docker/fluent-logging.yml`. `cab0124` от этого отошёл в пользу host-level shared —
   отклонение осознанное, но нигде не зафиксировано.

Не проблема (проверено, чтобы не искать заново): фильтрация в Graylog идёт по
`docker_project:<COMPOSE_PROJECT_NAME>` / `docker_service`, а `source` — IP хоста (общий).
`container_name` не индексируется — это норма, а не пробел.

**Recommendation (решение не принято):**
(a) провижинить host-level shared fluent-bit на каждом remote-хосте по образцу
`/home/soft/shared`, или (b) вернуть `fluent-bit` как опциональный compose-оверлей для
remote-хостов. В любом случае: починить `fluent-*` таргеты, забиндить intake на loopback,
исправить drift в доке, почистить orphan'ы на saFin.

**Acceptance Criteria:**
- Принято решение (a)/(b) выше.
- `fluent-up`/`fluent-restart`/`fluent-logs` работают согласно выбранной модели, либо удалены.
- Все fluent-intake порты — loopback-only.
- `docs/workers-remote-deploy.md` синхронизирован с фактическим compose.
- Orphan-контейнеры на saFin убраны или легализованы.

**Контекст:** обновлено 2026-07-29 по итогам верификации на uBook (см. также
`remote-host-make-up-footgun.md`).

**Status:** grooming
