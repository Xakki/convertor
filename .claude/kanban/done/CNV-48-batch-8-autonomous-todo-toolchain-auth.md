### EPIC: Batch — 8 autonomous todo (toolchain/auth/CI/dashboard)

**Criticality:** Medium
**Type:** epic

**TAGS:**
- tech-debt
- epic
- toolchain
- auth
- ci

**Description:**
Пакет из 8 уже отгрумленных автономных карт из `todo/`: toolchain (PHPStan,
migrate-diff), concurrency/DLQ, guest e2e, Telegram poll-login + e2e, CI
pipeline и кабинет retry/delete. Цель — закрыть их одним эпиком на общей ветке
без крупных billing/Stage-6–7/ops-карт.

**Problem:**
Отгрумленные автономные карты копятся в `todo/` рядом с крупными фичами;
каждая по отдельности исполнимо, но вместе требует единого интеграционного
гейта.

**Impact:**
Toolchain-дыры (PHPStan без migrations/bin, migrate-diff dirty), гонка DLQ
requeue, дыры e2e (guest cookie / auth poll), отсутствие CI, кабинет без
retry/delete — остаются открытыми.

**Recommendation:**
Работать последовательно на ветке `epic/CNV-48`. Implementer = grok; механику
(docs/Makefile/rename/lint-fix) делегирует composer. Если по карте нужна
развилка — коммиты уходят в `defer/<CNV-ID>`, к эпику возвращаемся после
остальных. По ходу — только нужные тесты; на integration gate — полный suite.

**Acceptance Criteria:**
- Все 8 подзадач в `ready/` (не `done/` до approve эпика).
- Integration checklist зелёный.
- Нет незакрытых defer-веток без явной пометки на карточке эпика.

**Decisions:**
- (2026-08-02) Состав 8 карт + команда (team-lead / grok implementer / composer
  chore / reviewer) согласованы с @user.
- Подзадачи — существующие CNV-карты (не перенумеровываем в CNV-48-NN); линк
  через `**Epic:** [[CNV-48]]` по образцу CNV-47 / admin-panel.
- Вне эпика: CNV-20/44 (ops), CNV-9/32/39 (MAYBE/defer), CNV-30/28/40/24/34/5/41
  (решения/секреты/Stage 6–7).

**Subtasks (порядок = порядок исполнения; все последовательные):**
1. `CNV-29` — PHPStan/cs: `bin/` @8 + `migrations/` @5-or-baseline
2. `CNV-11` — DLQ requeue: `SELECT … FOR UPDATE`
3. `CNV-19` — E2E guest 202 + cookie `guest_id`
4. `CNV-25` — Schema drift / `migrate-diff` catch-up
5. `CNV-42` — Telegram login: poll вместо magic-link
6. `CNV-14` — E2E auth poll с мок-ботом (после CNV-42)
7. `CNV-26` — GitHub Actions CI pipeline
8. `CNV-8` — Кабинет: retry / delete конверсии

**Integration checklist** (эпик покидает progress/ только при зелёном):
- [x] Все 8 подзадач в `ready/`.
- [x] Defer-ветки (если были) либо вмержены, либо явно задокументированы.
- [x] `make docker-check`
- [x] `make phpstan` + `make cs-check`
- [x] Полный тест-suite: `make test` (или эквивалент TEST=1 full gate)
- [x] Нет регрессий по затронутым зонам (auth poll, guest cookie, DLQ, CI, dashboard)

**Execution Log (2026-08-02, integration gate):**
- Подзадачи: 8/8 в `ready/` (CNV-29, 11, 19, 25, 42, 14, 26, 8). Defer-веток не заводили.
- `make docker-check` — dev: ok, test: ok.
- `make phpstan` (основной + `phpstan-migrations.neon`) — 0 ошибок (126 + 18 файлов).
- `make cs-check` — 0/222 файлов с нарушениями.
- `make test` (чистый пересоздание тест-стенда: `test-down` → `test-up` → `test-php test-python test-drift`):
  - PHPUnit: 511 tests, 2076 assertions, 1 deprecation, 0 failures.
  - pytest (воркеры, по образам): 98+18+34(+1 xfailed)+31+15+111(+2 skipped)+5 — все зелёные, 0 failures.
  - drift-guard (routing + worker-type): 5/5 passed.
- Регрессий не найдено, фиксов не потребовалось.
- Residuals (не в скоупе гейта, вынесены в grooming): `.claude/kanban/grooming/CNV-49-*`, `.claude/kanban/grooming/CNV-50-*`.
- Оставшийся артефакт: `app-symfony/backup_phpstan.cnv29-probe.neon` (не удалён, ждёт решения пользователя).

**Branch:** один `epic/CNV-48` на весь эпик (подзадачи НЕ получают своих веток;
исключение — `defer/<CNV-ID>` при необходимости решения).

**Out of scope:**
- CNV-20/44 (Harbor/uBook ops), CNV-9 (browser smoke), CNV-32 (gist),
  CNV-39 (smoke-стенд), billing/SMS/rate-limit/Stage 7.
