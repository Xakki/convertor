### Стабильность тестового стенда и quality gate

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
Стабилизировать изолированный тестовый стенд и сделать полный quality gate воспроизводимым одним агентом.

**Problem:**
PHP-набор тестов падает на устаревших fixture/mock, а `make test-down` не удаляет профильный AI-контейнер и оставляет сеть занятой.

**Impact:**
Полный `make test` останавливается раньше остальных suite, а тестовое окружение загрязняется контейнерами между прогонами.

**Recommendation:**
Сначала исправить красный PHP suite, затем обеспечить очистку всех тестовых Compose-профилей и проверить полный цикл поднятия, прогона и teardown.

**Acceptance Criteria:**
- `make TEST=1 test-php` и полный `make test` зелёные.
- После любого тестового сценария `make test-down` удаляет все контейнеры и сеть `xakki-convertor-test`.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Выполнять до feature-эпиков: чистый quality gate нужен для безопасной проверки последующей работы.

**Subtasks:**
- CNV-60 — исправить CleanTestData FK и mock quota в PHP-тестах
- CNV-72 — очищать AI-профиль вместе с тестовым Compose-стендом

**Integration checklist:**
- Выполнить полный цикл `test-up` → тестовые сценарии → `test-down`.
- Выполнить полный quality gate проекта.

**Execution Log:**
- 2026-08-15: EPIC-001 завершён; EPIC-002 может продолжаться. Порядок зависимостей: сначала CNV-60, затем CNV-72.
- Зоны владения: CNV-60 — Symfony/PHP-тесты; CNV-72 — Makefile teardown тестов и Compose-профили.
- Проверки закреплены за Luna: CNV-60 — scoped `make TEST=1 test-php`; CNV-72 — изолированные `test-up`/сценарий/`test-down` и проверка остаточных контейнеров/сети; затем Luna выполняет parent acceptance gates. Нужный независимый свежий review Terra — обязателен для изменений зон владения/межслойного контракта.
- Отложено: физически CNV-72 в `todo`, при заявленном статусе `grooming`; карточку не перемещать. Ручная или разрушительная очистка запрещена. Работы EPIC-003+ не начинать.
- 2026-08-15: CNV-60 завершена и перемещена в `ready`: focused RED — exit 2,
  нарушение FK; focused GREEN — exit 0, 2 теста/22 assertions; полная PHP
  acceptance — exit 0, 727 тестов/3221 assertions, 12 PHPUnit deprecations,
  без failures/errors; свежий независимый review Terra — PASS, блокирующих
  замечаний нет.
- CNV-72 разблокирована, но не начата. Неблокирующие риски CNV-60: удаление S3
  вне DB-транзакции; регрессионный тест не проверяет отображаемые счётчики ledger.
- 2026-08-15: CNV-72 готова. До исправления `make test-down` оставлял healthy
  `worker-ai` и сеть `xakki-convertor-test-network`; stale-state cleanup и
  post-fix `test-up` прошли. `smoke-run` поднял healthy `worker-ai`, но завершился
  exit 2 на несвязанном mismatch схемы seed `daily_conversions`; это неблокирующе
  для CNV-72 и отложено на review пользователя. Post-fix `test-down` удалил все
  тестовые контейнеры, сети и volumes; финальные проверки контейнеров/сети:
  пусто/not-found. Независимый свежий Terra review — PASS, без замечаний.
- 2026-08-15: Исправленный lifecycle CNV-60: parent RED на чистом стеке выявил
  3 несамодостаточных AI auth/quota-теста, ранее скрытых stale `worker-ai`; focused
  RED — 3/3 получили 503. Test-only уникальные fixtures `WorkerCapability` и
  lifecycle «клиент до fixture» исправлены; focused GREEN — 3 теста/15 assertions;
  полный PHP GREEN — 727 тестов/3221 assertions, 12 неизменённых deprecations.
  Первый независимый re-review — FAIL только по документации, исправлено; focused
  re-review — PASS. CNV-60 снова ready.
- Неблокирующие риски CNV-60 сохраняются: удаление S3 вне DB-транзакции;
  регрессионный тест не проверяет отображаемые счётчики ledger. Оставшиеся parent
  gates: полный `make test`, teardown/проверки остаточных ресурсов
  и финальные git-проверки.
- 2026-08-15: Parent smoke RED: `make TEST=1 smoke-run` завершился с exit 2;
  все 6 сценариев (document/image/audio/video/data/AI) остановились до конвертации
  на `pymysql` 1054 (`daily_conversions` в INSERT users). `worker-ai` healthy.
  Расследование актуальности seed-схемы ожидается; исправления и проверки не выполнялись.
- 2026-08-15: Подтверждён root cause parent smoke RED: test-only seed
  `workers/tests/test_workers_e2e.py::_seed_db` сохранял удалённые
  `daily_conversions`/`daily_ai_conversions` после миграции
  `Version20260802180000`. В существующий `INSERT IGNORE users` внесено только
  выравнивание seed: восемь актуальных daily/monthly tier-счётчиков инициализируются
  нулём, `monthly_reset_at` получает тот же reset timestamp, `is_guest=0` и
  `is_admin=0` задаются явно. Миграции, security-порядок и production-код не
  изменялись.
- 2026-08-15: Parent smoke GREEN: `make TEST=1 smoke-run` завершился с exit 0;
  6/6 сценариев document/image/audio/video/data/AI прошли, `worker-ai` healthy.
  Независимый static code/schema review: code/schema/security дефектов нет; FAIL
  только из-за устаревшего лога, исправлено этой записью. Focused re-review ожидается.
  Неблокирующий риск загрязнённой test DB из-за `INSERT IGNORE` остаётся parked.
- 2026-08-15: `make cs-check` завершился с exit 2 в четырёх PHP-файлах EPIC-002;
  применена только formatting-коррекция выравнивания без изменения поведения.
  Повторный запуск Luna `cs-check` ожидается.
- 2026-08-15: Первая style-коррекция оставила два локальных выравнивания
  присваивания; применены ровно эти две formatting-правки в
  `GuestAuthenticationTest.php` и `ConversionQuotaEnforcementTest.php` без
  изменения значений строк или поведения. Повторный запуск Luna остаётся ожидаемым.
- 2026-08-15: Авторитетный итог перед финальным review: CNV-60 и CNV-72 —
  `ready`. Полный `make test` — PASS: PHPUnit 727 тестов/3221 assertions,
  12 deprecations; workers 111 passed/2 skipped/1 warning; gateway 223
  passed/1 skipped; drift 28 passed. `make TEST=1 smoke-run` — PASS, 6/6.
  Финальный `make test-down` — PASS, удалены `worker-ai` и все ресурсы test;
  остаточные контейнеры — пусто, сеть — not-found. `make phpstan` — PASS,
  0 ошибок; `make docker-check` — PASS. `make cs-check` сначала выявил 4,
  затем 2 файла; канонический `make cs` исправил последние два; финальный
  `make cs-check` — PASS, 0 из 274 (с предупреждением версии PHP). Независимые
  child- и cross-layer-review в итоге PASS после исправления
  documentation-only замечаний. Сохраняются все ранее parked риски; ожидаются
  только comprehensive Terra review и финальная Luna git-inventory.
- 2026-08-15: Финальный comprehensive Terra review вернул HIGH по CNV-72:
  `test-down` передавал `COMPOSE_PROFILES=server,test,ai` shell environment
  prefix перед recursive make, который `.env.test` мог переопределить. Исправлено
  точечно: переменная передаётся после `$(MAKE_TEST)` как make command-line
  variable и до `down-v` — `$(MAKE_TEST) COMPOSE_PROFILES=server,test,ai
  down-v`. CNV-72 возвращена в `progress`.
- 2026-08-15: Post-correction Luna evidence для CNV-72: smoke — PASS, 6/6;
  `worker-ai` healthy. `make test-down` — exit 0, удалены `worker-ai` и все
  test-ресурсы; остаточные контейнеры — пусто,
  `xakki-convertor-test-network` — not-found; `make docker-check` — PASS.
  Static Makefile implementation review — PASS; единственный FAIL был по
  устаревшим логам, исправлено этой записью. Focused documentation re-review
  Terra остаётся pending.
- 2026-08-15: Focused documentation re-review Terra для CNV-72 — PASS;
  CNV-60 и CNV-72 — ready. EPIC-002 остаётся progress: ожидаются свежий
  final comprehensive Terra review исправленного snapshot и финальный Luna
  git inventory; все parked риски сохранены.
- 2026-08-15: Свежий финальный comprehensive Terra review — PASS, actionable
  findings нет; combined contract/dependency/lifecycle review — PASS. Изменённых
  путей EPIC-003+ нет. Parked, non-blocking риски: S3 cleanup вне DB-транзакции;
  нет rendered assertion для ledger-count; `INSERT IGNORE` может сохранить
  загрязнённого seed user. До parent ready остаётся только финальный Luna
  inventory `git status`/`git diff`.
- 2026-08-15: Финальный comprehensive Terra review — PASS; pre-ready Git
  inventory Luna — PASS. Parent готов к user review и перемещён в `ready`.
  Рабочее дерево намеренно остаётся незафиксированным (есть staged и unstaged
  изменения); commit, push, перенос в `done` и действия по EPIC-003+ намеренно
  не выполнялись.
- 2026-08-15: Пользователь одобрил финализацию EPIC-002: commit всех текущих
  изменений, согласованный перенос parent и обеих дочерних карточек в `done`,
  локальный squash merge без push.

**Status:** done
