### `make harbor-login` не может авторизоваться в Harbor — переменные не заданы

**Criticality:** Medium

**TAGS:**
- tech-debt
- docker
- harbor
- ci

**Description:**
Таргет `harbor-login` в корневом `Makefile` (строка ~90):
```makefile
.PHONY: harbor-login
harbor-login: ## Login to Docker registry
	docker login $(DOCKER_REGISTRY) -u $(DOCKER_USER) -p $(DOCKER_PASS)
```
использует три переменные — `DOCKER_REGISTRY`, `DOCKER_USER`, `DOCKER_PASS`. Ни одна из
них не задана ни в трекаемом `.env`, ни в `.env.local`, ни в `.env.local_example`
(проверено `grep -n -E "^DOCKER_REGISTRY|^DOCKER_USER|^DOCKER_PASS"` по всем трём файлам —
ноль совпадений). При запуске `make harbor-login` все три подставятся пустыми, и
`docker login` либо упадёт с ошибкой аргументов, либо попытается залогиниться на пустой
host.

**Impact:** сегодня это незаметно, потому что `push-gateway`/`push-ai-base`
(`workers/Makefile`) успешно пушат в `harbor.xakki.ru` благодаря уже закешированному
докер-credential'у в `~/.docker/config.json` этой машины (запись есть — значение
намеренно не публикуется здесь). На чистой машине или в CI-раннере без предварительного
ручного `docker login` публикация образов (`push-gateway`, `push-ai-base`) сломается —
`make harbor-login` не подготовит авторизацию, и `docker push` откажет с `unauthorized`.

**Recommendation:**
- Завести `DOCKER_REGISTRY=harbor.xakki.ru` в трекаемый `.env` (не секрет — просто адрес
  registry).
- Завести `DOCKER_USER`/`DOCKER_PASS` в `.env.local` (секреты) с пустыми плейсхолдерами
  в `.env.local_example`, по правилу проекта "секреты — только в `.env.local`"
  (`CLAUDE.md` → «Secrets / env»).
- После этого проверить `make harbor-login` от чистого docker-credential-store (`docker
  logout harbor.xakki.ru` перед тестом), чтобы убедиться, что таргет реально авторизует, а
  не просто молча проезжает на уже закешированном credential'е.

**Контекст:** обнаружено 2026-07-23 при написании скилла `image-build-deploy`
(топология сборки/деплоя образов convertor).

**Status:** grooming.
