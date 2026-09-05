### Профили на каждый воркер в docker-compose.yml (выборочный запуск)

**Criticality:** High

**TAGS:**
- infra
- docker
- remote-workers

**Description:**
Перенести матрицу «один профиль на воркер» из `deploy/docker-compose.yml` в
трекаемый корневой `docker-compose.yml`, чтобы хост мог поднимать и обновлять
ПОДМНОЖЕСТВО воркеров штатным образом.

**Problem:**
Сейчас ни один из 5 не-AI воркеров не имеет профиля в трекаемом
`docker-compose.yml`. Из этого следуют две проблемы.

1. **Нет выборочного обновления.** `make pull` тянет все сервисы без фильтра,
   `make workers-recreate` перечисляет все 6 имён жёстко. Обновить один воркер
   штатным способом нельзя.
2. **`make workers-recreate` обходит исключение воркеров на хосте.** Он называет
   сервисы ЯВНО, а Compose при явном указании включает профиль сервиса
   независимо от `COMPOSE_PROFILES`.

**Impact:**
Подтверждено на saVpn 2026-08-24: `make workers-recreate` начал тянуть
многогигабайтные образы libreoffice/ffmpeg/ai на хост с 892 МБ RAM, где эти
воркеры намеренно исключены. Поймано до создания контейнеров; на диске осталось
~800 МБ недокачанных слоёв. На хосте работает боевой VPN — при доведении до
конца это означало бы вытеснение памяти у чужого прод-сервиса.

**Evidence:**
Обходной путь уже применён на saVpn: нетрекаемый `COMPOSE_FILE`-override,
загоняющий 3 исключённых воркера в фиктивный профиль, защищённый от `git clean`
через host-local `.git/info/exclude`. Это workaround: файл нетрекаемый, значит
невоспроизводим из репозитория, и его потеря приводит к тому, что следующий
`make up` молча поднимет все 5 воркеров.

**Recommendation:**
Матрица уже существует в `deploy/docker-compose.yml` — перенос, а не
проектирование с нуля. Делать ОТДЕЛЬНО от EPIC-004: затрагивает запуск на всех
хостах, включая главный сервер, и требует своей проверки.

**Decisions:**
- 2026-08-26: использовать отдельный Compose profile для каждого воркера;
  главный сервер перечисляет требуемый полный набор в корневом `.env`.
- 2026-08-26: добавить штатный параметризованный target
  `make workers-recreate SERVICE=<name>` вместо pattern-target.
- 2026-08-26: удалить локальный saVpn override только после проверенного rollout
  штатных профилей на этом хосте.

**Acceptance Criteria:**
- Каждый worker имеет отдельный profile в корневом `docker-compose.yml`; главный
  сервер задаёт полный набор в корневом `.env`.
- `make workers-recreate SERVICE=<name>` принимает только известный service и
  пересоздаёт только его, не включая disabled profiles.
- Проверенный dry-run и rollout на saVpn подтверждают, что исключённые воркеры
  не pull/recreate; затем нетрекаемый override удалён вручную на этом хосте.
- `make docker-check`, профильные Make-контракты и kanban-lint проходят.

**Handoff evidence (2026-09-05):**
- Реализация зафиксирована в `fe9b459` на ветке `task/CNV-124`; изменены только `.env`, `.env.local_worker_example`, `docker-compose.yml`, `docs/workers-remote-deploy.md`, `workers/Makefile` и два профильных теста.
- PASS: `make test-makefile-worker-pull` (6 passed); `make test-compose-worker-profiles` (1 passed, 20 deselected); `make test-worker-build-release-contract` (1 passed, 20 deselected); `make docker-check` (dev/test ok); `make TEST=1 test-python-host-telemetry` (103 passed, 1 skipped).
- `make config-check` завершился успешно. Отдельные targets `config-diff` и `host-telemetry-contract` в репозитории отсутствуют; эквивалентный host telemetry contract выполнен через `test-worker-build-release-contract`.
- `make host-telemetry-validate` заблокирован локальным prerequisite `host probe must be on root filesystem`; rollout saVpn не выполнялся по границе полномочий этой передачи.
- Полный `make TEST=1 test-drift` выявил два предсуществующих CNV-137 version-contract failure (`APP_VER=0.1.2` ожидается тестами, текущий baseline — `0.2.0`); в CNV-124 не исправлялись.
- `.env` и `.env.local_worker_example` содержат только non-secret profile configuration; secret values не изменялись и не раскрывались.
- Передача ограничена независимым implementation review: merge, push, release/build и saVpn rollout не выполнялись.
- 2026-09-05 repair: generic remote/uBook commands are now explicitly separated from
  `saVpn`; documented `saVpn` pull/recreate commands pass the exact
  `worker-data worker-image` allowlist. Regression test executes both documented
  invocations and asserts no other worker is selected. Targeted test, config-check,
  docker-check and card lint pass; full test-drift still has the two pre-existing
  CNV-140 version-contract failures.
- 2026-09-05: explicit user authorization records CNV-124 completion after the
  implementation source was merged and pushed (`fe9b459` is an ancestor of
  `main`, and `main` matches `origin/main` at `068554c`). The sanctioned `saVpn`
  live rollout was not executed because sanctioned access was unavailable. This
  is a lifecycle completion authorization, not a claim that the live rollout,
  release or build was performed. The `saVpn` access boundary and the two
  pre-existing CNV-140 version-contract failures remain known limitations.
