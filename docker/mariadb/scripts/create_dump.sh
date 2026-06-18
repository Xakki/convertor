#!/bin/bash
# Runs INSIDE the mariadb container (working_dir=/backup, host-mounted from
# docker/mariadb/backup). Produces the gz only — no S3 creds/tools here.
# The HOST upload step (s3dump.sh push) ships it to the pf-dump bucket.
set -o nounset
set -o errexit
set -o pipefail

mariadb-dump -p${MARIADB_ROOT_PASSWORD} --single-transaction ${MARIADB_DATABASE} | gzip -v7 > ${BACKUP_FILE:-dump.sql.gz}

