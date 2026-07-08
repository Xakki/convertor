### Makefile test-таргеты хардкодят pip-зависимости вместо requirements-файла

**Критичность:** Low

**TAGS:**
- workers
- makefile
- tests
- tech-debt

**Описание:**
`workers/Makefile`-таргет `test-gateway` (и, вероятно, соседние test-таргеты) ставит зависимости
ручным списком в ephemeral-контейнере:
`pip install ... pytest pytest-asyncio 'websockets==13.1' 'httpx==0.27.2' 'redis>=5.0'`.
Список дублирует то, что уже лежит в `workers/requirements.txt` (напр. `redis>=5.0`), и легко
рассинхронизируется: ревью d781529 показало, что `redis` в этом списке отсутствовал, из-за чего
gate падал на `import redis` в gateway-коде (`ws_server.py`, `keydb.py`). Каждый новый рантайм-импорт
gateway-кода будет так же тихо ронять test-таргет, пока кто-то вручную не допишет пакет.

**Возможное решение (обсудить):**
- Вынести test-зависимости в отдельный файл (напр. `workers/requirements-test.txt` или reuse
  `workers/requirements.txt` + `requirements-dev.txt`) и в Makefile ставить `pip install -r <file>`
  вместо ручного списка.
- Либо предбейкать зависимости в test-образ (worker-data / отдельный test-stage), чтобы `pip install`
  на каждый прогон вообще исчез.

**Decisions (2026-07-07):**
- **Единый `workers/requirements-test.txt`** на все test-таргеты (один источник; лишние пакеты в отдельных
  таргетах — приемлемая цена против дублирования базы pytest). Собрать в него весь набор, что сейчас
  руками перечислен по таргетам (pytest, pytest-asyncio, `websockets==13.1`, `httpx==0.27.2`, `redis>=5.0`,
  + ai-набор: fastapi, uvicorn[standard], python-multipart, webrtcvad-wheels, `av==17.1.0`, numpy).
- **Пред-бейк в test-образ** (не `pip install -r` на каждый прогон): test-зависимости вшиваются в отдельный
  test-stage образа поверх runtime-слоя воркера; `pip install` из test-таргетов Makefile исчезает совсем.
  Быстрее gate/CI; пересборка stage — только при смене `requirements-test.txt`. Все `docker`-операции —
  через Makefile-таргеты.

**Эпик:** `[[s1-ws-worker-transport]]` (находка ревью d781529)

**Execution Log (2026-07-07):**
- Создан `workers/requirements-test.txt` — union всех test-deps (pytest, pytest-asyncio, `websockets==13.1`, `httpx==0.27.2`, `redis>=5.0`, fastapi, uvicorn[standard], python-multipart, webrtcvad-wheels, `av==17.1.0`, numpy). Пины сохранены.
- Общий `docker/workers/test.Dockerfile` — `FROM ${BASE_IMAGE}` (runtime-образ воркера) + `COPY requirements-test.txt` + `pip install -r ... && rm`. Собирается ТОЛЬКО `build-*-test`-таргетами; в prod `:latest` не попадает.
- Makefile: все `test-python-*` и `test-gateway` переведены на пред-бейкнутые `:test`-образы (prereq `build-*-test`), ad-hoc `pip install` убран из макроса `RUN_PYTEST_TEST` и всех таргетов.
- Ревью (bb5e944): APPROVE-WITH-NITS. Все 3 проверки зелёные — (a) prod-образы без test-deps, (b) union полон vs старые inline-списки, (c) все in-scope таргеты переведены. NIT-A (`rm` артефакта) и NIT-B (комментарий host-side `test-drift`) — оказались уже сделаны в bb5e944 (ревьюер видел усечённый дифф); follow-up не нужен.
- NIT про `test-e2e` (всё ещё inline-pip) — вне scope, свёрнут в `[[test-e2e-stale-worker-images]]`.
- Гейты (пред-бейкнутые образы собраны с нуля): `docker-check` exit 0; `test-gateway` **93 passed**; `test-python-ai` **110 passed, 2 skipped**; `test-python` (data 14 / ffmpeg 31+1xfail / image 29 / libreoffice / metrics 15 / ai 110+2skip) — весь per-worker suite прошёл.

**Status:** ready
