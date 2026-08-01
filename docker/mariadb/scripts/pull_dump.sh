#!/bin/sh
# Скачивает дамп из S3-бакета ${S3_DUMP_BUCKET} в ./backup/dump.sql.gz. По
# умолчанию берёт САМЫЙ СВЕЖИЙ объект под префиксом DUMP_PREFIX (ключи
# лексикографически сортируемы благодаря таймстампу, см. push_dump.sh) —
# override конкретным ключом через DUMP_KEY.
#
# Запускается в одноразовом контейнере minio/mc (см. `make db-dump-pull`).
# POSIX sh — образ minio/mc alpine-based, bash в нём нет.
#
# Обязательные env: S3_ENDPOINT, S3_KEY, S3_SECRET, S3_DUMP_BUCKET.
# Опциональные: DUMP_PREFIX (по умолчанию "convertor"), DUMP_KEY (полный ключ
# объекта, в т.ч. с префиксом — если задан, листинг не выполняется),
# DUMP_FILE (путь назначения, по умолчанию /backup/dump.sql.gz).
set -eu

: "${S3_ENDPOINT:?S3_ENDPOINT не задан}"
: "${S3_KEY:?S3_KEY не задан}"
: "${S3_SECRET:?S3_SECRET не задан}"
: "${S3_DUMP_BUCKET:?S3_DUMP_BUCKET не задан}"

DUMP_FILE="${DUMP_FILE:-/backup/dump.sql.gz}"
DUMP_PREFIX="${DUMP_PREFIX:-convertor}"

scheme="${S3_ENDPOINT%%://*}"
hostpart="${S3_ENDPOINT#*://}"
export MC_HOST_dump="${scheme}://${S3_KEY}:${S3_SECRET}@${hostpart}"

if [ -n "${DUMP_KEY:-}" ]; then
    key="$DUMP_KEY"
else
    # Образ minio/mc — минимальный (без awk/sed/grep), поэтому поле "key" из
    # `mc ls --json` тянем чистыми POSIX-подстановками, а "самый свежий" ключ
    # ищем через sort|tail (лексикографический порядок таймстампа в имени).
    listing="$(mc ls --json --recursive "dump/${S3_DUMP_BUCKET}/${DUMP_PREFIX}/" 2>/dev/null || true)"
    keys=""
    if [ -n "$listing" ]; then
        while IFS= read -r line; do
            case "$line" in
                *'"key":"'*)
                    rest="${line#*\"key\":\"}"
                    k="${rest%%\"*}"
                    keys="${keys}${k}
"
                    ;;
            esac
        done <<EOF
$listing
EOF
    fi
    latest="$(printf '%s' "$keys" | sort | tail -n1)"
    if [ -z "$latest" ]; then
        echo "pull_dump: в s3://${S3_DUMP_BUCKET}/${DUMP_PREFIX}/ дампов не найдено (или бакет ещё не создан)" >&2
        exit 1
    fi
    key="${DUMP_PREFIX}/${latest}"
fi

echo "pull_dump: s3://${S3_DUMP_BUCKET}/${key} -> $DUMP_FILE"
mc cp "dump/${S3_DUMP_BUCKET}/${key}" "$DUMP_FILE"
echo "pull_dump: OK $DUMP_FILE"
