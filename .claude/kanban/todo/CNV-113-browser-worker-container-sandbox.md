### Контейнерная sandbox-инфраструктура browser worker

**Criticality:** High

**TAGS:**
- feature
- browser
- docker
- infrastructure
- security

**Description:**
Infrastructure-специалист создаёт развёртываемый изолированный container runtime для
маршрута browser worker, не реализуя очередь, API, screenshot/recording логику или
frontend.

**Problem:**
Chromium требует отдельной границы процессов, файловой системы и ресурсов. Общий
image/video container или запуск с `--no-sandbox` предоставляет browser-коду лишние
секреты, mounts и возможность исчерпать ресурсы соседних worker-ов.

**Impact:**
Crash, запись на filesystem или компрометация Chromium смогут повлиять на backend и
другие worker-ы; неконтролируемые процессы и память ухудшат доступность очереди.

**Recommendation:**
Подготовить `workers/browser` image и compose/runtime configuration: non-root,
read-only FS, bounded tmpfs для work/profile, init, `cap_drop: ALL`,
`no-new-privileges`, pids/memory/CPU limits, отдельная network boundary и без
Docker socket, host mounts, S3/KeyDB credentials либо backend network. Chromium
использует штатный sandbox без production fallback `--no-sandbox`.

**Acceptance Criteria:**
- Browser container запускается non-root с read-only root FS; запись разрешена только
  в ограниченные tmpfs work/profile, а запись вне них завершается отказом.
- В container отсутствуют Docker socket, host mounts, backend network и credentials
  S3/KeyDB; capabilities сброшены, `no-new-privileges`, init и resource limits
  фактически заданы в runtime configuration.
- Smoke/integration checks подтверждают завершение sandbox/resource failure и cleanup
  child processes; целевые инфраструктурные проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: infrastructure-специалист; граница работы — image и runtime isolation.
- Один context/job и один browser slot задаются как container invariant; feature
  runtime не ослабляет его.
- CNV-113 зависит от готовой browser-маршрутизации CNV-88; CNV-90 и CNV-91 зависят
  от CNV-113 и не создают альтернативный sandbox.
