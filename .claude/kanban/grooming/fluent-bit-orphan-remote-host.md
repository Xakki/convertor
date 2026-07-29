### Логи remote-хостов не доходят до Graylog — fluent-bit-сайдкар выпилен, а shared-модель не настроена

**Criticality:** High

**TAGS:**
- tech-debt
- logging
- fluent-bit
- remote-workers

**Description:**
Коммит `cab0124` («fix fluent-logging - use shared fluent») убрал сервис `fluent-bit` из
`docker/fluent-logging.yml`. Теперь convertor ожидает ОБЩИЙ (host-level) fluent-bit, доступный по
`EXT_FLUENT_PORT`.

Главный сервер saFin: `.env.local` задаёт `EXT_FLUENT_PORT=127.0.0.1:24224` (shared fluent) — ОК.
Но контейнеры `xakki-convertor-fluent-bit` и `xakki-convertor-logrotate` там до сих пор работают
как неуправляемые ORPHAN'ы (compose их больше не описывает).

Remote-хост uBook: shared fluent-bit НЕ существует. Работает только orphan-сайдкар
`convertor-remote-xbook-fluent-bit` (создан 6 дней назад), публикующий `0.0.0.0:24224`. Но
трекаемый `.env` по умолчанию задаёт `EXT_FLUENT_PORT=127.0.0.1:10094`, а в `.env.local` на uBook
эта строка закомментирована → все 6 воркеров настроены слать на `fluentd-address:
127.0.0.1:10094`, где НИЧЕГО не слушает (`ss -ltn` показывает только `:24224`). С
`fluentd-async: "true"` докер тихо дропает записи → **логи воркеров uBook сейчас НЕ доходят до
Graylog**.

**Problem:**
- `make fluent-up` / `fluent-restart` / `fluent-logs` (в `workers/Makefile:182-192`) сейчас
  СЛОМАНЫ на любом хосте: `no such service: fluent-bit` (сервис убран из compose коммитом
  `cab0124`, но таргеты Makefile не обновлены).
- Если orphan-сайдкар на uBook когда-нибудь остановится — нет поддерживаемого способа вернуть
  доставку логов на remote-хосте (сервис `fluent-bit` больше не определён в compose, только
  вручную).
- Доковый drift: `docs/workers-remote-deploy.md` (строки 21, 36-42, 97, 154, 168, 209) по-прежнему
  утверждает, что `docker-compose.yml`/`docker/fluent-logging.yml` содержит `include:
  docker/fluent-log/docker-fluent.yml` и что на remote-хосте поднимается СВОЙ локальный
  fluent-bit-сайдкар из сабмодуля `docker/fluent-log` — это больше не так (grep подтверждает:
  `include` в `docker/fluent-logging.yml` отсутствует после `cab0124`). Сабмодуль `docker/fluent-log`
  всё ещё проинициализирован на v0.1.4, но `COMPOSE_FILE` на него больше не ссылается.
- Отдельно: в `.env.local` главного сервера строка 1 (`COMPOSE_FILE=...`) до сих пор ссылается на
  несуществующий `docker-compose.worker-ai.yml`. Практически безвредно (Makefile делает `unexport
  COMPOSE_FILE`), но это устаревшее значение, которое стоит почистить заодно.

**Impact:**
- Прямо сейчас: полная потеря логов воркеров на uBook в Graylog (тихий дроп, без ошибки в
  консоли/логах контейнера).
- Любой новый remote-хост, поднятый по текущей `docs/workers-remote-deploy.md`, повторит ту же
  проблему — документация описывает несуществующий сайдкар-путь.
- `fluent-up`/`fluent-restart`/`fluent-logs` бесполезны как diagnostic/recovery-инструмент везде.

**Recommendation (решение не принято):**
Нужно явное архитектурное решение по способу доставки логов с remote-хостов — один из двух путей
(или гибрид):
1. **Вернуть собственный fluent-bit-сервис в compose** (per-remote-host sidecar) — ближе к
   исходной модели `docker/fluent-log`, не требует provisioning хоста извне контейнеров, но
   расходится с духом коммита `cab0124` («use shared fluent»).
2. **Провижининг host-level shared fluent-bit на КАЖДОМ remote-хосте** (аналог того, что стоит на
   saFin) — единообразно с главным сервером, но требует отдельного non-Docker provisioning-шага
   на каждом новом remote-хосте (сейчас нигде не описан).

В любом случае:
- Почистить orphan-контейнеры (`xakki-convertor-fluent-bit`/`-logrotate` на saFin,
  `convertor-remote-xbook-fluent-bit` на uBook) после решения.
- Починить `fluent-up`/`fluent-restart`/`fluent-logs` под выбранную модель (или удалить таргеты,
  если больше не применимы).
- Обновить `docs/workers-remote-deploy.md` (убрать упоминания несуществующего `include`, описать
  актуальный способ доставки логов на remote-хосте).
- Задать/раскомментировать `EXT_FLUENT_PORT` на uBook под фактически работающий сайдкар (`:24224`)
  как немедленный workaround, независимо от итогового архитектурного решения.
- Почистить устаревшую ссылку на `docker-compose.worker-ai.yml` в `.env.local` главного сервера.

**Acceptance Criteria:**
- Принято решение (свой сайдкар в compose vs host-level shared fluent на remote-хостах).
- Логи всех 6 воркеров uBook доходят до Graylog (проверено `HOST_NAME`-фильтром в интерфейсе
  Graylog).
- `make fluent-up`/`fluent-restart`/`fluent-logs` работают на всех хостах согласно выбранной
  модели, либо явно удалены с объяснением почему.
- `docs/workers-remote-deploy.md` синхронизирован с фактическим состоянием compose (нет упоминаний
  несуществующего `include`).
- QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest.

**Open questions:**
- Собственный сайдкар в compose или host-level shared fluent — какой путь предпочтителен для
  remote-хостов, учитывая, что `cab0124` сознательно ушёл от сайдкара на главном сервере?
- Кто и как провижинит shared fluent-bit на новом remote-хосте, если выбран путь 2 (нет пока
  скрипта/плейбука)?
- Нужно ли удалить сабмодуль `docker/fluent-log`, если ни один хост в итоге не использует его
  напрямую, или он остаётся источником конфигурации для host-level fluent-bit?

**Контекст:** найдено 2026-07-29 на хосте uBook в ходе диагностики простоя воркеров (связано с
`remote-host-make-up-footgun.md`).

**Status:** grooming
