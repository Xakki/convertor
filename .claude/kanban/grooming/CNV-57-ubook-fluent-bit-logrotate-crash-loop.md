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

**Impact:**
Конвертация на uBook работает; observability логов remote-воркеров деградирована.
Не блокер CNV-54 runtime (метрики/рейтлимит/exporter на saFin).

**Recommendation:**
1. Сверить `COMPOSE_FILE` / submodule `docker/fluent-log` на uBook с
   `.env.local_worker_example` и CNV-17.
2. Проверить bind-mount конфига fluent (файл vs пустой/каталог) и
   `logrotate.app.conf`.
3. `make fluent-restart` / recreate sidecar после фикса.

**Acceptance Criteria:**
- `make ps` на uBook: fluent-bit и logrotate `healthy`/`Up`, не Restarting
- В логах fluent нет `could not open configuration file`
- Smoke: запись из worker-контейнера появляется в Graylog (или принятом sink)

**Open questions:**
- Регрессия после pull нового fluent-bit `:latest` (v5) vs сломанный bind на хосте?
- Чинить только uBook `.env.local`/volumes или ещё шаблон/док?

**Decisions:**
- (пусто — grooming)
