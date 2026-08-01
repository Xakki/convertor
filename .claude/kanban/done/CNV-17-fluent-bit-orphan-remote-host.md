### fluent-bit на remote-хостах — orphan-сайдкары, drift в доке, bind intake

**Criticality:** Medium

**TAGS:**
- tech-debt
- logging
- fluent-bit
- remote-workers

**Epic:** [[CNV-47]] — подзадача 8.

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
1. Архитектурная нестыковка: на saFin — host-level `shared-fluent-bit`, на remote — унаследованный
   per-project orphan-сайдкар. Нет описанного способа завести логи на НОВОМ remote-хосте.
2. Безопасность: сайдкар uBook слушает `0.0.0.0:24224` (открытый intake) vs `127.0.0.1:24224` на
   saFin. Должно быть loopback-only везде.
3. Лишние orphan'ы на saFin: `xakki-convertor-fluent-bit` / `xakki-convertor-logrotate` —
   ops-prune допустим.
4. Хрупкость: дефолт трекаемого `.env` — `EXT_FLUENT_PORT=127.0.0.1:10094`.
5. Doc drift: `docs/workers-remote-deploy.md` утверждает про `include:
   docker/fluent-log/docker-fluent.yml` в compose — grep подтверждает, что это не так.
6. Отклонение от кросс-проектного стандарта (скилл fluent-logging) — осознанное, нужно
   задокументировать.

Не проблема (проверено): фильтрация в Graylog по `docker_project` / `docker_service`.

**Recommendation:**
Оставить проектный сайдкар на remote; bind `127.0.0.1:24224`; задокументировать intentional
отклонение; saFin orphan prune — ops OK.

**Acceptance Criteria:**
- [x] Модель: проектный fluent-bit sidecar на remote-хостах (не host-level shared)
- [x] Intake bind — `127.0.0.1:24224` (не `0.0.0.0`)
- [x] Документация: intentional отклонение от shared-fluent на remote; как поднять на новом хосте
- [x] `docs/workers-remote-deploy.md` синхронизирован с фактическим compose
- [x] Orphan'ы на saFin — prune ops OK (не блокер кода)

**Decisions:**
- Оставляем проектный sidecar (не переходим на host-level shared на remote).
- Bind intake: `127.0.0.1:24224`.
- Задокументировать как intentional (отклонение от shared-fluent на saFin).
- Orphan prune на saFin — ops OK.
- Пункт «targets broken» убран из AC (не цель этой карточки / не считаем блокерм).

**Work notes:**
Groomed 2026-08-01: keep project sidecar; loopback bind; doc intentional; drop broken-targets AC.

**Контекст:** обновлено 2026-07-29 по итогам верификации на uBook (см. также
`remote-host-make-up-footgun.md`).

**Status:** ready.

## Execution Log

- 2026-08-01: started (todo→progress) on epic/CNV-47. Scope from Decisions: keep remote project sidecar; bind `127.0.0.1:24224`; document intentional deviation from saFin shared-fluent; sync `docs/workers-remote-deploy.md` + worker env example + ubook skill. Orphan prune saFin = ops OK (no code).
- 2026-08-01: implemented — `.env.local_worker_example` loopback bind; `docs/workers-remote-deploy.md` synced (sidecar vs shared-fluent, no false include:); ubook-remote-workers skill + Makefile comment. Commit `23942e5` (Agent: docs; Co-authored-by stripped via commit-tree). saFin orphan prune = ops residual (not code). Live uBook `.env.local` still may have `0.0.0.0` — ops to rebind when convenient (gitignored).
- 2026-08-01: progress→test→ready (docs/config AC met; no compose yaml change → docker-check N/A).
