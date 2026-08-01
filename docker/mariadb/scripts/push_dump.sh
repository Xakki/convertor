#!/bin/sh
# Отгружает ./backup/dump.sql.gz в S3-бакет ${S3_DUMP_BUCKET} НОВЫМ ключом с
# таймстампом (никогда не перезаписывает существующий объект — версионирование
# бакета остаётся follow-up админ-шагом, см. карточку CNV-10-db-backup-s3-pipeline).
#
# Запускается в одноразовом контейнере minio/mc (см. `make db-dump-push`) ИЛИ в
# сайдкаре db-dump-cron (dump_cron_loop.sh) — оба монтируют ./backup:/backup и
# ./docker/mariadb/scripts:/scripts. POSIX sh — образ minio/mc alpine-based,
# bash в нём нет.
#
# Обязательные env: S3_ENDPOINT, S3_KEY, S3_SECRET, S3_DUMP_BUCKET.
# Опциональные: DUMP_PREFIX (папка внутри бакета, по умолчанию "convertor"),
# DUMP_FILE (путь к дампу, по умолчанию /backup/dump.sql.gz).
#
# Схема ключа: "${DUMP_PREFIX}/convertor-<UTC YYYYMMDD-HHMMSS>.sql.gz" — сортируется
# лексикографически, поэтому pull_dump.sh может брать "последний по алфавиту" как
# самый свежий.
set -eu

: "${S3_ENDPOINT:?S3_ENDPOINT не задан}"
: "${S3_KEY:?S3_KEY не задан}"
: "${S3_SECRET:?S3_SECRET не задан}"
: "${S3_DUMP_BUCKET:?S3_DUMP_BUCKET не задан}"

DUMP_FILE="${DUMP_FILE:-/backup/dump.sql.gz}"
DUMP_PREFIX="${DUMP_PREFIX:-convertor}"

if [ ! -f "$DUMP_FILE" ]; then
    echo "push_dump: файл $DUMP_FILE не найден (сначала make db-dump)" >&2
    exit 1
fi

scheme="${S3_ENDPOINT%%://*}"
hostpart="${S3_ENDPOINT#*://}"
# MC_HOST_<alias> — задаёт alias через env без записи ~/.mc/config.json: контейнер
# одноразовый, HOME может быть недоступен на запись под непривилегированным uid.
export MC_HOST_dump="${scheme}://${S3_KEY}:${S3_SECRET}@${hostpart}"

ts="$(date -u +%Y%m%d-%H%M%S)"
key="${DUMP_PREFIX}/convertor-${ts}.sql.gz"

echo "push_dump: $DUMP_FILE -> s3://${S3_DUMP_BUCKET}/${key}"
mc cp "$DUMP_FILE" "dump/${S3_DUMP_BUCKET}/${key}"
echo "push_dump: OK s3://${S3_DUMP_BUCKET}/${key}"
