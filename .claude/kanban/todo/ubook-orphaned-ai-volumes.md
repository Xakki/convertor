### uBook: тома `worker-ai-models`/`worker-ai-data` подписаны старым именем compose-проекта

**Criticality:** Low

**TAGS:**
- tech-debt
- docker
- remote-workers
- ubook

**Description:**
На удалённом хосте uBook именованные тома `worker-ai-models` и `worker-ai-data` (кэш ML-моделей
AI-воркера) до сих пор помечены лейблом старого имени compose-проекта — `convertor-remote-xbook`
(до переименования xbook→ubook, см. также правку `.claude/skills/ubook-remote-workers/SKILL.md`,
где `COMPOSE_PROJECT_NAME` был задокументирован неверно как `convertor-remote-xbook` вместо
фактического `convertor-remote-ubook`). Текущий `COMPOSE_PROJECT_NAME` на хосте —
`convertor-remote-ubook`, из-за чего docker считает эти два тома orphan-ресурсами: не
принадлежащими текущему проекту, хотя реально используются им же (bind по имени тома, не по
лейблу). На каждый `make up`/`docker compose up` на uBook docker печатает предупреждение о
несоответствии лейблов.

**Impact:** только шум в выводе `make up` — сами тома исправно монтируются и данные не теряются,
конвертация не ломается. Риск — при будущей чистке orphan-ресурсов (`docker volume prune` или
аналог) их можно случайно удалить, не осознав, что это боевой кэш моделей, а не мусор.

**Acceptance Criteria:**
- [ ] Старые тома с лейблом `convertor-remote-xbook` удалены / заменены
- [ ] Новые тома созданы под `convertor-remote-ubook` (корректные лейблы)
- [ ] ML-модели перекачаны; `make up` на uBook без orphan-предупреждений по этим томам
- [ ] Кратко зафиксировано в ops-заметке / skill ubook (время/трафик redownload)

**Decisions:**
- Действие: recreate + redownload (не migrate содержимого старых томов).
- Ops-карточка: выполнить на uBook, модели скачаются заново.

**Work notes:**
Groomed 2026-08-01: recreate+redownload (not migrate); ops card.

**Контекст:** обнаружено 2026-07-30 при верификации Harbor pull-деплоя воркеров на uBook.

**Status:** todo.
