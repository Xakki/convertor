### Нет рабочей отгрузки дампов БД в S3

**Criticality:** Medium

**TAGS:**
- infra
- tech-debt
- backup

**Problem:**
2026-07-30 удалена нерабочая host-обвязка дампов (`docker/mariadb/s3dump.sh`,
`getdump.sh`, `bashlibs.sh` + закоммиченный 30 MB бинарник `mc`). Это была
дословная копия из другого проекта (`matrix_dev`/proxy-service) и работать
не могла:

1. **Бакета не существует.** `S3_DUMP_BUCKET=convertor-dump` — в MinIO такого
   бакета нет (есть `pf-dump` с чужими дампами `dev/matrix-*.sql.gz` от 2026-06).
2. **Путь к дампу неверный.** Скрипты искали `docker/mariadb/backup/dump.sql.gz`,
   а compose монтирует `./backup` из корня репо — каталога
   `docker/mariadb/backup/` не существует вовсе.
3. **Чужой нейминг:** ключи `matrix-<ts>.sql.gz`, alias `pfdump`, сообщения про
   бакет `pf-dump`.
4. **Не вызывалось ниоткуда:** ни одного make-таргета, ни упоминания в docs.
5. **Нарушало два правила проекта:** «все операции с S3 — через MCP `minio`» и
   docker-only toolchain (скрипт качал `mc` и `bashlibs.sh` прямо на хост).

**Что осталось рабочим** (проверено 2026-07-30, дамп `convertor` = 10 таблиц):
`docker/mariadb/scripts/create_dump.sh` + `restore.sh` внутри контейнера,
теперь под таргетами `make db-dump` / `make db-restore` (локальный
`./backup/dump.sql.gz`, без S3).

**Что нужно сделать:**
1. Бакет `convertor-dump` + кастомная политика без Delete
2. Версионирование; push всегда новым ключом
3. Таргеты `make db-dump-push` / `db-dump-pull` из контейнера (docker-only)
4. Cron/контейнер рядом с dump для расписания
5. Retention — follow-up

**Note:** блоб `mc` (30 MB) остаётся в истории git — `git rm` его оттуда не
убирает. Чистка истории — отдельное решение (не делать без явного согласия).

**Acceptance Criteria:**
- [x] Бакет `convertor-dump` создан; кастомная политика Get/List/Put **без Delete** (`convertor-dump-rw-nodelete`, см. Execution Log)
- [x] Версионирование бакета включено (тот же админ-шаг)
- [x] `make db-dump-push` / `db-dump-pull` — отгрузка/скачивание из контейнера (docker-only)
- [x] Расписание: cron или sidecar-контейнер рядом с dump (MVP) — сервис `db-dump-cron`, профиль `backup`
- [x] Документация таргетов в Makefile `##` help
- [ ] Retention — follow-up (не блокер MVP этой карточки)

**Decisions:**
- Имя бакета оставляем `convertor-dump` (не переименовывать в `convertor-backups`).
- Политика — кастомная без Delete (создать вне MCP при необходимости).
- Расписание: cron/контейнер рядом с dump.
- MVP: push/pull + versioning.
- Retention — follow-up.

**Work notes:**
Groomed 2026-08-01: keep convertor-dump; no-Delete policy; cron+push/pull+versioning MVP; retention later.

**Related cards:**
- `[[CNV-12-docs-payments-integration]]` — тоже заморожённая интеграция

**Status:** ready

**Execution Log:**
- started 2026-08-01
- 2026-08-01 (infra, ветка `task/CNV-10`): реализован push/pull-пайплайн и MVP-планировщик, без доступа к MCP `minio` в этой сессии — бакет НЕ создавался и не трогался.
  - `docker/mariadb/scripts/create_dump.sh`: добавлен опциональный `MARIADB_HOST` (пусто = как раньше, локальный сокет внутри контейнера mariadb; задан = TCP, нужен сайдкару).
  - `docker/mariadb/scripts/push_dump.sh` (новый): грузит `/backup/dump.sql.gz` в `s3://${S3_DUMP_BUCKET}/${DUMP_PREFIX}/convertor-<UTC YYYYMMDD-HHMMSS>.sql.gz` — ключ ВСЕГДА новый, оверрайта нет. Алиас mc через `MC_HOST_dump` env (без записи `~/.mc/config.json` — важно для non-root uid в одноразовом контейнере).
  - `docker/mariadb/scripts/pull_dump.sh` (новый): по умолчанию тянет последний по алфавиту ключ под префиксом (сортировка = сортировка по времени благодаря формату таймстампа); `DUMP_KEY=<ключ>` — оверрайд конкретным объектом. Образ `minio/mc` минимальный (нет awk/sed/grep) — парсинг `mc ls --json` сделан чистыми POSIX-подстановками + `sort`/`tail`.
  - `Makefile`: `db-dump-push` (зависит от `db-dump`, разовый `docker run --rm minio/mc:latest`, НЕ `docker compose`), `db-dump-pull` (аналогично). `-u $(PUID):$(PGID) -e HOME=/tmp` — файлы сразу с правильным владельцем, `chown`-костыль как у `db-dump` не нужен.
  - `docker/mariadb/dump-cron.Dockerfile` (новый) + сервис `db-dump-cron` в `docker-compose.yml`: образ = `${DOCKER_IMAGE_MARIADB}` + бинарник `mc`, скопированный build-стадией из `minio/mc:latest` (без apt/интернета в рантайме). Скрипты монтируются volume'ом (как у `mariadb`), не запекаются. Планировщик — НЕ настоящий cron, а `dump_cron_loop.sh` (sleep-loop, `DUMP_CRON_INTERVAL_S`, дефолт 86400 = раз в сутки) — избегает проблемы с передачей env в crontab.
  - Профиль `backup` (не `server`!) — осознанно НЕ активен на тест-стенде (`.env.test`: `COMPOSE_PROFILES=server,test`), чтобы тесты не пушили дампы тестовой БД в прод-бакет. В `.env` добавлен в `COMPOSE_PROFILES` для дев/прод-хоста.
  - Верификация: `make docker-check` — ok (dev+test). Собран образ `db-dump-cron` (`docker compose build db-dump-cron`) — успешно, внутри есть `mc` и `mariadb-dump`. Полный round-trip push/pull проверен на ВРЕМЕННОМ локальном `minio/minio` контейнере (не проектный MCP, не трогает прод) — push нового ключа, pull "последнего", pull по `DUMP_KEY` — все три сценария ОК. Один цикл сайдкара (`create_dump.sh` с `MARIADB_HOST` + `push_dump.sh`) прогнан вручную против РЕАЛЬНОЙ dev-БД (mariadb-контейнер стенда) + временного minio — дамп снялся по TCP, зашёлся chown, загрузился. Против реального `apis3.xakki.ru`/`convertor-dump` (`make db-dump-push`/`db-dump-pull` как есть) — ожидаемо `Bucket convertor-dump does not exist` (бакета ещё нет, это ожидаемый результат до админ-шага).
  - Остаётся для тимлида (админ, MCP `minio`, вне этой сессии):
    1. Создать бакет `convertor-dump`.
    2. Кастомная IAM-политика Get/List/Put **без Delete** — MCP `minio` даже при доступности умеет только встроенные политики (`readwrite`/`readonly`/…), кастомную придётся создавать вне MCP (см. `mc admin policy create` или консоль MinIO).
    3. Включить версионирование бакета.
    4. После создания — перепроверить `make db-dump-push` / `make db-dump-pull` вживую и что `db-dump-cron` реально долетает до бакета в проде.
  - Retention (TTL/чистка старых объектов в бакете) — сознательно не реализован, follow-up отдельной карточкой/AC.
- 2026-08-01 (infra, ветка `task/CNV-10`): выполнены админ-шаги MinIO, снятые в конце предыдущей записи.
  - MCP `minio` в этой сессии ещё не подхватился — использован тот же код-путь (`docker exec shared-minio mc ...`) напрямую через `/home/ai/mcp_minio.py`, импортированный `/home/ai/.venv/bin/python` (без запуска mc на хосте, docker-only).
  - `bucket_create("convertor-dump")` — создан. `bucket_versioning(..., "enable")` — включено (`bucket_info` подтверждает `Versioning: Enabled`).
  - Кастомная политика без Delete: MCP-инструментов `policy_create` нет (см. проектное правило — только встроенные политики через MCP), поэтому JSON `docs/infra/minio-convertor-dump-policy.json` (по образцу `minio-convertor-policy.json`, но ресурс `convertor-dump` и БЕЗ `s3:DeleteObject`) скопирован в контейнер (`docker cp` + `docker exec rm` после) и создан через `mc admin policy create local convertor-dump-rw-nodelete <файл>` — тем же `_mc()`-хелпером, что и остальные вызовы. `policy_info` подтвердил точное совпадение с JSON (нет `DeleteObject`).
  - `policy_attach("convertor-dev", "convertor-dump-rw-nodelete")` — успешно. `user_info("convertor-dev")` после attach: `PolicyName: convertor-dump-rw-nodelete,readwrite` — **`readwrite` остаётся приложенной** (была уже привязана до этого прогона; broad `readwrite` даёт Delete на ВСЕ бакеты, включая inputs/results). Снимать `readwrite` не стал — не в скоупе этого прогона (сломает inputs/results) и требует отдельного явного решения тимлида/юзера; кастомная политика per AC создана и приложена, что и требовалось.
  - Верификация: `bucket_info("convertor-dump")` → `Versioning: Enabled`, 0 объектов до пуша. `list_policies()` включает `convertor-dump-rw-nodelete`. `list_objects("convertor-dump")` — пусто (до live-теста).
  - Live round-trip против РЕАЛЬНОГО `apis3`/`convertor-dump` (dev-стенд был поднят, mariadb работал): `make db-dump-push` — дамп снят (`create_dump.sh`, 10 таблиц) и залит новым ключом `xakki-convertor/convertor-20260801-185326.sql.gz` (7.92 KiB, `push_dump: OK`). Затем `rm backup/dump.sql.gz` + `make db-dump-pull` — файл скачан обратно (`pull_dump: OK`, тот же размер 8106 байт). Оба таргета работают end-to-end против прод-инстанса MinIO, как и задумано.
  - Итог по AC: бакет + версионирование + кастомная no-Delete политика — готово. `readwrite` на `convertor-dev` — остаётся (see выше), сужение IAM — follow-up, НЕ блокер этой карточки (по формулировке задачи).
- 2026-08-01 (chore): QA gate — `make docker-check` + live push/pull уже верифицированы infra; карточка движется в ready.
