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
1. Решить нейминг бакета — по свежей конвенции соседей это `convertor-backups`
   (ср. `myip-backups`, `proxy-service-backups`), не `convertor-dump`.
2. Создать бакет + дать доступ юзеру S3. ⚠ **Блокер:** MCP `minio` умеет
   аттачить только встроенные политики (`readwrite`/`readonly`), а нужна
   кастомная (Get/List/Put без Delete, как у `pf-dump`) — значит либо
   встроенная `readwrite` (можно удалять — хуже для бэкапов), либо политика
   создаётся вне MCP.
3. Включить версионирование бакета (push всегда новым ключом, ничего не
   перезаписываем).
4. Таргеты `make db-dump-push` / `db-dump-pull` — отгрузка **из контейнера**
   (docker-only), не host-скриптом с качаемым `mc`.
5. Расписание (Symfony Scheduler / cron-контейнер) + retention.

**Note:** блоб `mc` (30 MB) остаётся в истории git — `git rm` его оттуда не
убирает. Чистка истории — отдельное решение (не делать без явного согласия).

**Related cards:**
- `[[docs-payments-integration]]` — тоже заморожённая интеграция

**Status:** grooming.
