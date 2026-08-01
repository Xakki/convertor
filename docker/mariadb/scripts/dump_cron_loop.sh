#!/bin/bash
# Сайдкар-планировщик MVP для сервиса db-dump-cron (CNV-10): раз в
# DUMP_CRON_INTERVAL_S секунд дампит БД (create_dump.sh, TCP через
# MARIADB_HOST=mariadb) и отгружает в S3 (push_dump.sh). Первый прогон — сразу
# при старте контейнера.
#
# НЕ настоящий cron-демон: простой sleep-loop — избегает возни с передачей env
# в crontab (cron по умолчанию не видит env контейнера). Ретеншен старых
# объектов в бакете — вне охвата этой карточки, см. кабан CNV-10.
set -o nounset
set -o errexit
set -o pipefail

INTERVAL="${DUMP_CRON_INTERVAL_S:-86400}"

echo "[dump-cron] старт, интервал ${INTERVAL}s"

while true; do
    echo "[dump-cron] $(date -u +%FT%TZ) дамп БД..."
    if bash /scripts/create_dump.sh; then
        chown "${PUID:-0}:${PGID:-0}" /backup/dump.sql.gz || true
        if ! sh /scripts/push_dump.sh; then
            echo "[dump-cron] push_dump.sh упал — повторим в следующем цикле" >&2
        fi
    else
        echo "[dump-cron] create_dump.sh упал — пропускаем push" >&2
    fi
    sleep "${INTERVAL}"
done
