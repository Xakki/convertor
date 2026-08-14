### Chromium worker routing sandbox и каталог

**Criticality:** High

**TAGS:**
- tech-debt
- browser
- docker
- queue
- security

**Description:**
Создать отдельный worker type `browser`, stream `conv.browser` и изолированный
Chromium container для web capture jobs.

**Problem:**
Текущие worker types/streams ограничены категориями image/video и не выражают
browser execution kind; Chromium нельзя безопасно помещать в image-worker.

**Impact:**
Browser jobs будут недостижимы либо попадут в неверный stream, а crash/egress
Chromium нарушит SLA и security соседних worker-ов.

**Recommendation:**
Добавить `WorkerType::Browser`, `executionKind=browser`, `conv.browser`, capability/
gateway/metrics/drift contract и `workers/browser` image. Container: non-root,
read-only FS, tmpfs work/profile, init, `cap_drop: ALL`, no-new-privileges,
pids/memory/CPU limits, один context/job и один WS slot. Не использовать `--no-sandbox`.

**Acceptance Criteria:**
- Browser jobs маршрутизируются только в `conv.browser`; другие image/video jobs
  сохраняют текущие streams; drift tests ловят рассинхронизацию enum/gateway/catalog.
- Контейнер non-root, без Docker socket/S3/KeyDB credentials/host mounts/backend network;
  попытки записи вне tmpfs и доступ к предыдущему browser context запрещены.
- Sandbox/resource failure корректно завершает job и убирает child processes.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Screenshot сохраняет category image, recording — video для quota/retention, но route
  определяется `executionKind=browser`.
