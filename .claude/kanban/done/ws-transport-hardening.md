### WS-transport hardening — находки ревью s1-09 (слой s1-08)

**Критичность:** Medium

**TAGS:**
- worker
- transport
- ws-client

**Описание:**
Ревью миграции AI-воркера (s1-09) вскрыло несколько дефектов в общем транспортном слое `workers/common/ws_client.py` (эпик s1-08). Они не блокируют s1-09 (не введены им), но должны быть починены до финального merge эпика. Собраны сюда пачкой.

**Находки:**
1. **inline-фрейм результата не несёт `ext`** (`ws_client.py` `_deliver`, ~L587). Large-путь кодирует расширение в имени файла (`f"{job_id}.{signal.ext or 'bin'}"`, ~L655), а inline-путь отдаёт только `mime`/`processingMs` — без `ext`. Для пар с одинаковым MIME но разным расширением (txt vs md → `text/plain`/`text/markdown`) gateway/persister не имеет канонического `ext`. Проверить, нужен ли серверу `ext` (может выводиться из `targetFormat`); если да — добавить в inline-фрейм. Асимметрия с large-путём в любом случае.
2. **Малформ-guard `_on_job` валидирует только `jobId`** (~L442). Отсутствующие `conversionId`/`sourceFormat`/`targetFormat` заполняются пустыми `.get()`-дефолтами и всплывают как ошибка ТОЛЬКО после полного скачивания входа (до 50–500 MB). Старый `_poll_cycle` отбраковывал малформ-claim ДО скачивания. Валидировать обязательные поля фрейма до `_download_input`.
3. **Утечка частичного output при фейле `convert()`** (`_run_job.finally`, `_cleanup_tmp(signal.path)` ~L552). При провале конвертации `handle_job` возвращает `failed(path=None)`, а `convert()` мог успеть записать частичный файл в `work_dir` под своим именем — `_cleanup_tmp(None)` его не трогает. На ретраях частичные файлы копятся в `work_dir`. Продумать очистку по job-scoped temp-дире.
4. **Потерян диагностик `API_BASE_URL` path-doubling.** Старый `worker._check_api_base_url` предупреждал, что путь-компонент в `API_BASE_URL` удвоит `/api/v1/...`. Тот же баг живёт в URL-сборке `WsClientConfig` (`api_base` = rstrip('/'), затем `f"{api_base}/api/v1/..."`), но без warning → немой 404 при мисконфиге. Вернуть проверку в `WsClientConfig.from_env()`/`validate()`.

**Связано:** `[[ws-inline-max-shared-threshold]]`, `[[s1-08-shared-ws-client]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Decisions (2026-07-07):**
- **(1) ext — код-факт проверен: серверу `ext` от воркера НЕ нужен.** И inline (`InternalWorkerController`), и large (`WorkerController`) строят result-key через `keyBuilder->build(conversionId, meta['targetFormat'])`; download-name = `sourceBase . '.' . conversion.getToFormat()` (`ConversionResultPersister` L96). Расширение — серверное, из `targetFormat` (различает txt/md). ⇒ finding #1 разворачивается: НЕ добавлять `ext` в inline-фрейм; асимметрия inline-vs-large косметическая. Действие: задокументировать, что `ext` серверный; при желании убрать вводящий в заблуждение `ext` из имени файла в large-пути (worker-локальная временная деталь, серверу не отправляется авторитетно). Понижено до doc/cleanup, не баг.
- **(3) partial-output leak — принять per-job temp-subdir.** `WsClient` заводит job-scoped temp-дир (`WORK_DIR/<jobId>/`), вход и выход воркера — внутри; `finally` сносит всё дерево (`rmtree`) вместо `_cleanup_tmp(signal.path)`. Закрывает утечку частичного output при фейле `convert()`.
- **Группировка:** все 4 находки — один слой (`ws_client.py` + мелочь в gateway/persister-доках), бьются одной карточкой (вариант «одной карточкой в todo»). Приоритет внутри: критбаги (#2 malform-validate-до-скачивания, #3 partial-leak) → #4 API_BASE_URL diag → #1 doc/cleanup.

**Execution Log (2026-07-07):**
- #2 (CRIT): `_run_job` валидирует `conversionId`/`sourceFormat`/`targetFormat` (`not frame.get()` ловит и `None`, и `""`) ДО любого I/O → `permanent=True` fail, `_download_input` не вызывается (нет 500 MB впустую). `jobId` валидируется в `_on_job`.
- #3 (CRIT): централизовано в `WsClient` — job-scoped dir `WORK_DIR/<jobId>/` (санитайз `_safe_dir_name`: whitelist-regex `[^A-Za-z0-9_-]→_` + sha1-суффикс от коллизий; traversal невозможен), инжект `_jobDir`, `shutil.rmtree(job_dir, ignore_errors=True)` в `finally` на успех И фейл. Все 5 воркеров (data/ffmpeg/image/libreoffice/ai) пишут output в `out_dir=_jobDir` (fallback `WORK_DIR` для standalone-тестов). `_cleanup_tmp` убран.
- #4: warning в `WsClientConfig.validate()` при path-компоненте в `API_BASE_URL` (немой 404 при удвоении `/api/v1`). Не блокирует старт.
- #1: doc-only — код-факт подтверждён (`ext` серверный, из `targetFormat`); добавлен русский комментарий в `_upload_large`, на wire ничего не добавлено.
- Ревью: APPROVE-WITH-NITS (адверсариал по traversal + per-worker output) → оба нита закрыты (commit 5011391): стал-комментарий libreoffice, `_jobDir` перенесён после `mkdir`.
- Коммиты: 2c4313d (осн.) + 5011391 (нит-фиксы). Тесты: +11 новых (parametrized×3 для #2, 3 для #3 вкл. adversarial-traversal, 2 для #4) + 3 усилены (`glob→iterdir()==[]`).
- Гейты: `make test-gateway` → **93 passed**; `make test-python-ai` → **110 passed, 2 skipped**.

**Status:** ready
