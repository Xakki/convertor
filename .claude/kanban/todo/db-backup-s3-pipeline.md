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
- [ ] Бакет `convertor-dump` создан; кастомная политика Get/List/Put **без Delete**
- [ ] Версионирование бакета включено
- [ ] `make db-dump-push` / `db-dump-pull` — отгрузка/скачивание из контейнера (docker-only)
- [ ] Расписание: cron или sidecar-контейнер рядом с dump (MVP)
- [ ] Документация таргетов в Makefile `##` help
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
- `[[docs-payments-integration]]` — тоже заморожённая интеграция

**Status:** todo.
