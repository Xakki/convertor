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

**Decisions:**
- Filed from CNV-39 smoke log review (2026-08-02).
- (2026-08-03) Кэш моделей — isolate per-project: `name: "${COMPOSE_PROJECT_NAME}-worker-ai-models"` / `…-worker-ai-data` (не shared external).
- (2026-08-03) One-shot миграция volume (rename/copy) на uBook/saFin — в скоупе этой карточки + docs в Execution Log / docs.

**Execution Log:**
- 2026-08-08: Карточка переведена `todo → progress` через `kanban-move.sh`.
- 2026-08-08: Решение: изменён только корневой `docker-compose.yml`; физические имена `worker-ai-models` и `worker-ai-data` теперь используют `${COMPOSE_PROJECT_NAME}` как остальные корневые named volumes. `deploy/docker-compose.yml` намеренно не менялся.
- 2026-08-08: В `docs/worker-ai-deploy.md` добавлен безопасный одноразовый путь: остановка/запуск Compose только через `make down`/`make up`, копирование legacy-томов только в пустые label-совместимые целевые тома; допустимая альтернатива — пустые тома и ленивая повторная загрузка/пересоздание.
- 2026-08-08: Проверки: `make docker-check` — успешно (`dev: ok`, `test: ok`); `make TEST=1 COMPOSE_PROFILES=server,test,ai config-check` — успешно (exit 0, без вывода; повторно после финальной правки — успешно); shell-синтаксис блока миграции — `bash -n`, успешно (`migration snippet: ok`). Полный `make test` запустил test-стенд и миграции, но завершился exit 2: 2 несвязанных с CNV-56 PHP-фейла в `ConversionTextInputControllerTest` из-за PHPUnit, не генерирующего return value для enum `App\\Enum\\BillingMode` в mock `QuotaService::check()`; исправление вне зоны Compose-томов.
- 2026-08-08: Runtime QA: `TEST=1 COMPOSE_PROFILES=server,test,ai make workers-recreate`, `make ps` и `make config-check` — успешно; test `worker-ai` healthy, монтирует `xakki-convertor-test-worker-ai-models` в путь Hugging Face cache и `xakki-convertor-test-worker-ai-data` в `/data`. Dev-тома не затрагивались; dev по-прежнему использует legacy-тома без префикса.
