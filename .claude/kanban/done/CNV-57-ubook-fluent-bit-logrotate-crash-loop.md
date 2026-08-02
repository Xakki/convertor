### uBook fluent-bit+logrotate crash-loop (config missing)

**Criticality:** Medium

**TAGS:**
- bug-fix

**Description:**
Обнаружено при деплое CNV-54 (Harbor pull + `workers-recreate` на uBook, 2026-08-02).
Шесть воркеров healthy; сайдкары `convertor-remote-ubook-fluent-bit` и
`convertor-remote-ubook-logrotate` в бесконечном `Restarting (1)`.

**Problem:**
- fluent-bit: `[error] could not open configuration file, aborting` (Fluent Bit v5.0.9).
- logrotate: `install: omitting directory '/etc/logrotate.app.conf'` (bind/путь — каталог вместо файла).
Воркеры логируют через docker logging-driver → без живого sidecar логи с uBook в Graylog
не доезжают (или падают в драйвер-ошибки).

**Root cause (2026-08-03):**
Docker Compose резолвит `./fluent-bit` и `./logrotate/logrotate.conf` из
`docker/fluent-log/docker-fluent.yml` относительно **корня проекта** (где
`docker-compose.yml`), а не каталога сабмодуля. На uBook bind указывал на пустые
root-owned каталоги `fluent-bit/` и `logrotate/logrotate.conf/` (Docker создал их
2026-08-02 при отсутствии источника).

**Fix:**
- Submodule FluentLog `528f4b0`: env `FLUENT_BIT_CONF_DIR` / `FLUENT_LOGROTATE_CONF`.
- Convertor `e67598a`: Makefile экспортирует абсолютные пути в `docker/fluent-log/…`.
- uBook ops: патч + `make --eval='recreate-fluent: ; $(DC_FLUENT) up -d --force-recreate fluent-bit logrotate' recreate-fluent`.

**Acceptance Criteria:**
- [x] `make ps` на uBook: fluent-bit и logrotate `Up`, не Restarting
- [x] В логах fluent нет `could not open configuration file`
- [ ] Smoke: запись из worker-контейнера появляется в Graylog (не проверялось)

**Decisions:**
- Не v5 регрессия — сломанный bind mount.
- `restart` недостаточен; нужен `--force-recreate` для новых volume paths.

**Residual:**
- Push FluentLog + convertor; uBook `git pull && git submodule update`.
- Удалить root-owned мусор `fluent-bit/`, `logrotate/` в корне uBook (`sudo rm -rf`).
- `EXT_FLUENT_PORT=0.0.0.0` на uBook — отдельно от CNV-17 (rebind на loopback).
