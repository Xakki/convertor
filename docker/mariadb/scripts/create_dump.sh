#!/bin/bash
# Запускается ЛИБО внутри контейнера mariadb (working_dir=/backup, монтируется из
# ./backup в корне репо) через `make db-dump`, ЛИБО в сайдкаре db-dump-cron
# (CNV-10, dump_cron_loop.sh) — тогда MARIADB_HOST=mariadb задаёт TCP-подключение
# вместо локального сокета. Кладёт локальный gz; отгрузка в S3 — push_dump.sh.
set -o nounset
set -o errexit
set -o pipefail

HOST_ARGS=()
if [[ -n "${MARIADB_HOST:-}" ]]; then
    HOST_ARGS=(-h "${MARIADB_HOST}")
fi

mariadb-dump -p${MARIADB_ROOT_PASSWORD} "${HOST_ARGS[@]}" --single-transaction ${MARIADB_DATABASE} | gzip -v7 > ${BACKUP_FILE:-dump.sql.gz}

