# syntax=docker/dockerfile:1.7
# db-dump-cron (CNV-10): сайдкар для периодического дампа БД + отгрузки в S3.
# База — тот же образ mariadb (mariadb-dump уже внутри), поверх статически
# копируется бинарник mc (MinIO client) из официального minio/mc — без apt/pip
# в рантайме, без интернета внутри работающего контейнера. Скрипты (create_dump.sh,
# push_dump.sh, dump_cron_loop.sh) НЕ запекаются — монтируются volume'ом из
# docker/mariadb/scripts, как и у самого mariadb-сервиса.
ARG DOCKER_IMAGE_MARIADB

FROM minio/mc:latest AS mc

FROM ${DOCKER_IMAGE_MARIADB}

COPY --from=mc /usr/bin/mc /usr/local/bin/mc

WORKDIR /backup
