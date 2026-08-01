### `make harbor-login` не может авторизоваться в Harbor — переменные не заданы

**Criticality:** Medium

**TAGS:**
- tech-debt
- docker
- harbor
- ci

**Epic:** [[CNV-47]] — подзадача 6.

**Description:**
Таргет `harbor-login` в корневом `Makefile` (строка ~90):
```makefile
.PHONY: harbor-login
harbor-login: ## Login to Docker registry
	docker login $(DOCKER_REGISTRY) -u $(DOCKER_USER) -p $(DOCKER_PASS)
```
использует три переменные — `DOCKER_REGISTRY`, `DOCKER_USER`, `DOCKER_PASS`.
Без них `make harbor-login` подставляет пустые значения, и `docker login`
падает или логинится на пустой host.

**Impact:** сегодня это незаметно, потому что `push-gateway`/`push-ai-base`
(`workers/Makefile`) успешно пушат в `harbor.xakki.ru` благодаря уже закешированному
докер-credential'у в `~/.docker/config.json` этой машины. На чистой машине или в CI
без предварительного ручного `docker login` публикация образов сломается.
`release-workers` унаследовал ту же зависимость от кешированного credential'а.

**Recommendation:**
- `DOCKER_REGISTRY=harbor.xakki.ru` в трекаемый `.env` (не секрет).
- `DOCKER_USER` / `DOCKER_PASS` — только в `.env.local` (секреты); в трекаемых
  `.env` / `.env.local_example` — пустые плейсхолдеры по правилу проекта.
- AC-проверка: `docker logout` → `make harbor-login` реально авторизует.

**Acceptance Criteria:**
- `DOCKER_REGISTRY` задан в трекаемом `.env`.
- Секреты `DOCKER_USER` / `DOCKER_PASS` — только в `.env.local` (плейсхолдеры
  в example допустимы; не требовать «missing from example» как дефект).
- После `docker logout $(DOCKER_REGISTRY)` → `make harbor-login` успешно
  логинится (не за счёт старого credential cache).
- `make release-workers` / push-таргеты могут опереться на этот login.

**Decisions:**
- (2026-08-01) `DOCKER_REGISTRY` в tracked `.env`; секреты только в
  `.env.local`. AC = clean logout → `harbor-login`. Убрать устаревший
  критерий «missing from example».

**Контекст:** обнаружено 2026-07-23 при написании скилла `image-build-deploy`
(топология сборки/деплоя образов convertor).

**Status:** ready

## Execution Log
- 2026-08-01 — wired DOCKER_REGISTRY in .env; DOCKER_USER/PASS placeholders in .env.local_example; empty placeholders in .env; hardened make harbor-login; updated image-build-deploy skill; smoke logout→login OK. Commit 9f638fb.
