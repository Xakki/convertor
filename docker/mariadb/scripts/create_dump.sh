#!/bin/bash
# Runs INSIDE the mariadb container (working_dir=/backup, host-mounted from ./backup
# at the repo root). Вызывать через `make db-dump`. Кладёт локальный gz; отгрузки в
# S3 нет — обвязка удалена как нерабочая, см. карточку db-backup-s3-pipeline.
set -o nounset
set -o errexit
set -o pipefail

mariadb-dump -p${MARIADB_ROOT_PASSWORD} --single-transaction ${MARIADB_DATABASE} | gzip -v7 > ${BACKUP_FILE:-dump.sql.gz}

