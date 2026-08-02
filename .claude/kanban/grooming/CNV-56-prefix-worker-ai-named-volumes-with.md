### Prefix worker-ai named volumes with COMPOSE_PROJECT_NAME

**Criticality:** Medium

**TAGS:**
- tech-debt
- docker
- workers

**Description:**
В `docker-compose.yml` тома `worker-ai-models` / `worker-ai-data` заданы
**жёсткими** `name:` без префикса compose-проекта. При `make smoke`
(тест-стенд включает профиль `ai`) compose предупреждает, что тома уже
созданы для `xakki-convertor`, а ожидается `xakki-convertor-test`.

Смежно с [[CNV-44]] (uBook orphan labels), но здесь корень — отсутствие
project-prefix в имени тома, а не только «осиротевшие» labels после rename.

**Problem:**
Dev и test (и remote) делят одни и те же глобальные named volumes → warning
на `up`, риск гонок кэша моделей, путаница ownership/`com.docker.compose.project`.

**Impact:**
Шум в логах smoke/up; потенциальная порча HF-кэша при параллельном
dev+test AI; усложняет cleanup (см. CNV-44).

**Recommendation:**
Переименовать в compose:
`name: "${COMPOSE_PROJECT_NAME}-worker-ai-models"` /
`…-worker-ai-data` (и зеркало в `deploy/docker-compose.yml` при наличии).
Описать one-shot миграцию кэша (copy/rename volume) в docs / Execution Log.
Не ломать uBook без явного recreate (координация с CNV-44).

**Acceptance Criteria:**
- `make TEST=1 COMPOSE_PROFILES=server,test,ai up` без warning
  «volume already exists but was created for project …».
- Dev и test AI-тома изолированы по имени проекта.
- Документирован путь миграции существующего кэша (или `external: true` decision).

**Open questions:**
- Делить кэш моделей между стендами намеренно (external shared) или
  изолировать per-project (рекомендация: isolate)?
- Кто делает volume rename на uBook / saFin — эта карта или CNV-44 follow-up?

**Decisions:**
- Filed from CNV-39 smoke log review (2026-08-02).
