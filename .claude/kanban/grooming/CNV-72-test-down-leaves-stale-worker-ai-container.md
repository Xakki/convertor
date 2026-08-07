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
Один из вариантов (не выбран, решает команда):
1. `test-down`/`down-v` всегда матчить ВСЕ профили (`COMPOSE_PROFILES=server,test,ai`
   при вызове `down`), а не только `server,test` из `.env.test`.
2. Отдельный `test-down` шаг `docker rm -f` по имени/лейблу compose-проекта
   независимо от активных профилей.
3. `test-e2e`/`smoke-run` сами гасят `ai`-профиль в конце (down --no-deps на нём).

**Acceptance Criteria:**
- `make TEST=1 test-up` → (любой прогон, включая `test-e2e`/`smoke-run`) →
  `make test-down` оставляет `docker ps -a --filter name=xakki-convertor-test`
  пустым и сеть `xakki-convertor-test-network` удалённой.

**Decisions:**
- (2026-08-06) Найдено побочно при верификации CNV-71-03 (gateway boot +
  expiry-loop). Не трогали контейнер руками (`docker rm`) — вне scope той
  задачи, оставлено на решение команды здесь.

**Status:** grooming
