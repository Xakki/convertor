### `make test-down` не убирает `xakki-convertor-test-worker-ai` — контейнер живёт днями и держит тест-сеть

**Criticality:** Low

**TAGS:**
- infra
- tests
- docker

**Description:**
На тест-стенде (`xakki-convertor-test`) обнаружен контейнер
`xakki-convertor-test-worker-ai`, поднятый ещё 2026-08-02 (профиль `ai`), который
`make test-down` не останавливает и не удаляет.

**Problem:**
`.env.test` задаёт `COMPOSE_PROFILES=server,test` (профиль `ai` — только явно,
через `test-e2e`/`smoke-run`: `$(MAKE) COMPOSE_PROFILES=server,test,ai up migrate`).
Если `ai`-профиль был поднят один раз (e2e/smoke), обычный `make test-up`/
`make test-down` его больше "не видит" (compose матчит сервисы по активным
профилям текущего вызова) — контейнер остаётся жить бессрочно и не снимается
штатным down.

Обнаружено при верификации boot'а `ws-gateway` для CNV-71-03 (2026-08-06):
после `make TEST=1 gateway-recreate` пересозданный gateway тут же
переподключил этот 4-дневный `worker-ai` (`"worker ready" workerId:
xakki-convertor-test-worker-ai`), а `make test-down` в конце верификации
не смог убрать `xakki-convertor-test-network` (`Resource is still in use`)
именно из-за него.

**Impact:**
Низкий: не ломает тесты (`test-gateway`/`test-e2e` по-прежнему проходят), но
- тест-стенд не поднимается/не гасится идемпотентно с флагом чистоты,
  который декларирует `test-down` (## "Снести тест-стенд вместе с томами");
- стенд может тихо копить контейнеры между сессиями;
- в диагностике вида "проверить boot ws-gateway на чистом стенде" — шум
  (4-дневный воркер регистрируется как будто всё свежее).

**Recommendation:**
В `test-down` и `down-v` всегда вызывать cleanup с
`COMPOSE_PROFILES=server,test,ai`. Это охватывает каждый профиль, который
может поднять тестовый сценарий, не добавляя удаление по имени контейнера.

**Acceptance Criteria:**
- `make TEST=1 test-up` → (любой прогон, включая `test-e2e`/`smoke-run`) →
  `make test-down` оставляет `docker ps -a --filter name=xakki-convertor-test`
  пустым и сеть `xakki-convertor-test-network` удалённой.

**Decisions:**
- (2026-08-06) Найдено побочно при верификации CNV-71-03 (gateway boot +
  expiry-loop). Не трогали контейнер руками (`docker rm`) — вне scope той
  задачи, оставлено на решение команды здесь.
- 2026-08-14: выбран единый cleanup через `COMPOSE_PROFILES=server,test,ai`
  в `test-down`/`down-v`; отдельное удаление по имени/лейблу и точечный down
  из e2e/smoke не нужны.

**Execution Log:**
- До исправления RED: после `make test-down` оставались healthy `worker-ai` и сеть `xakki-convertor-test-network`.
- Исправление очистки stale-state прошло; post-fix `test-up` прошёл.
- `smoke-run` поднял healthy `worker-ai`, но завершился с exit 2 из-за несвязанного mismatch схемы seed `daily_conversions`; для CNV-72 это неблокирующее, отложено на review пользователя.
- Post-fix `test-down` прошёл и удалил все тестовые контейнеры, сети и volumes; финальные проверки контейнеров и сети прошли: пусто/not-found.
- Независимый свежий Terra review: PASS, замечаний нет; CNV-72 готова.
- 2026-08-15: Финальный comprehensive Terra review — HIGH: рекурсивный вызов
  `test-down` передавал `COMPOSE_PROFILES=server,test,ai` как shell environment
  prefix, поэтому `.env.test` мог переопределить его. Исправлено на make
  command-line variable с приоритетом выше makefile: `$(MAKE_TEST)
  COMPOSE_PROFILES=server,test,ai down-v`.
- 2026-08-15: Post-correction Luna evidence: smoke — PASS, 6/6 сценариев;
  `worker-ai` healthy. `make test-down` — exit 0, удалены `worker-ai` и все
  тестовые ресурсы; проверка остаточных контейнеров — пусто,
  `xakki-convertor-test-network` — not-found. `make docker-check` — PASS.
  Static Makefile implementation review — PASS; единственный FAIL был по
  устаревшим логам, исправлено этой записью. Focused documentation re-review
  Terra остаётся pending.
- 2026-08-15: Focused documentation re-review Terra — PASS; после исправления
  приоритета command-line переменной CNV-72 снова ready.
- 2026-08-15: Пользователь одобрил финализацию CNV-72 и перенос в `done` в
  составе локального squash merge EPIC-002 без push.

**Status:** done
