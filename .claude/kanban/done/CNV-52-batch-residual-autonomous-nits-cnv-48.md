### EPIC: Batch — residual autonomous nits (CNV-48 leftovers + pre-relay DLQ)

**Criticality:** Medium
**Type:** epic

**TAGS:**
- tech-debt
- epic
- observability
- auth
- dlq
- worker-transport

**Description:**
Пакет из 4 отгрумленных автономных карт — residual nits после CNV-48 (CNV-51, CNV-50,
CNV-49) плюс pre-relay result-path DLQ/cap (CNV-37). Цель — закрыть их одним эпиком
на общей ветке без крупных todo-карт, payments и Stage 7.

**Problem:**
После CNV-48 остались мелкие дыры observability/auth/DLQ requeue; CNV-37 (pre-relay
result-path) не вошёл в CNV-48 и всё ещё допускает бесконечный idle-reclaim poison-job.

**Impact:**
Сбои S3 при delete остаются невидимыми; устаревший `tg_login_nonce` после poll fail;
S3 I/O под FOR UPDATE lock в requeue; pre-relay malformed/oversize inline крутится в PEL.

**Recommendation:**
Работать последовательно на ветке `epic/CNV-52`. Implementer = grok; механику
(docs/Makefile/rename/lint-fix) делегирует composer. Если по карте нужна развилка —
коммиты уходят в `defer/<CNV-ID>`, к эпику возвращаемся после остальных. По ходу —
только нужные тесты; на integration gate — полный suite.

**Acceptance Criteria:**
- Все 4 подзадачи в `ready/` (не `done/` до approve эпика).
- Integration checklist зелёный.
- Нет незакрытых defer-веток без явной пометки на карточке эпика.

**Decisions:**
- (2026-08-02) Состав 4 карт + команда (team-lead / grok implementer / composer
  chore / reviewer) согласованы с @user.
- CNV-50=A: гасить `tg_login_nonce` на **403 mismatch** и **410 expired/gone**.
- CNV-49=A: S3 `objectExists` **до** `FOR UPDATE` (fail-fast); без новой
  timeout/retry-политики.
- CNV-37 включён в эпик; подход по рекомендации карточки (permanent → DLQ сразу;
  transient → capped retry → DLQ; shared helper если уже есть общий result-path reject).
- Подзадачи — существующие CNV-карты (не перенумеровываем в CNV-52-NN); линк
  через `**Epic:** [[CNV-52]]` по образцу CNV-47 / CNV-48.

**Subtasks (порядок = порядок исполнения; все последовательные):**
1. `CNV-51` — log S3 failures in deleteObjectQuietly
2. `CNV-50` — clear tg_login_nonce on poll 403+410
3. `CNV-49` — S3 objectExists before FOR UPDATE
4. `CNV-37` — pre-relay result-path DLQ/cap

**Integration checklist** (эпик покидает progress/ только при зелёном):
- [x] Все 4 подзадач в `ready/`.
- [x] Defer-ветки (если были) либо вмержены, либо явно задокументированы.
- [x] `make docker-check`
- [x] `make phpstan` + `make cs-check`
- [x] Полный тест-suite: `make test` (или эквивалент TEST=1 full gate)
- [x] Нет регрессий по затронутым зонам (delete/S3 log, auth poll nonce, DLQ requeue, gateway pre-relay)

**Execution Log (2026-08-02, integration gate):**
- Подзадачи: 4/4 в `ready/` (CNV-51, CNV-50, CNV-49, CNV-37). Defer-веток не заводили.
- `make docker-check` — dev: ok, test: ok.
- `make phpstan` (основной + `phpstan-migrations.neon`) — 0 ошибок (126 + 18 файлов).
- `make cs-check` — 0/222 файлов с нарушениями.
- `make test` (изолированный TEST-стенд):
  - PHPUnit: 512 tests, 2100 assertions, 1 deprecation, 0 failures.
  - pytest (воркеры, по образам): 98+18+34(+1 xfailed)+31+15+111(+2 skipped) — все зелёные, 0 failures.
  - drift-guard (routing + worker-type): 5/5 passed.
- Регрессий не найдено, фиксов не потребовалось.

**Branch:** один `epic/CNV-52` на весь эпик (подзадачи НЕ получают своих веток;
исключение — `defer/<CNV-ID>` при необходимости решения).

**Out of scope:**
- Крупные todo-карты (CNV-5, CNV-41, CNV-44 и др.).
- CNV-38 (отдельная карта).
- CNV-45/46 (пустые grooming).
- Payments, Stage 7, ops (Harbor/uBook и т.п.).
