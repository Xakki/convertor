### EPIC: Batch — 6 todo (deploy / rate-limit / metrics / uBook / dashboard-smoke / smoke)

**Criticality:** Medium
**Type:** epic

**TAGS:**
- tech-debt
- epic
- deploy
- rate-limit
- metrics
- ops
- qa

**Description:**
Пакет из 6 уже отгрумленных карт из `todo/`: публичный деплой remote-воркеров,
rate-limit, метрики/алерты, uBook orphan-тома AI, browser smoke кабинета и
smoke-прогон стека. Цель — закрыть их одним эпиком на общей ветке без Stage 7
(CNV-5/41), billing (CNV-30/28) и freeze CNV-12.

**Problem:**
Отгрумленные prod-polish / ops / QA карты копятся в `todo/` рядом с крупными
гейтами; каждая исполнимо по отдельности, но нужен единый интеграционный гейт.

**Impact:**
Нет публичного `curl|bash` для remote-воркеров; API без KeyDB rate-limit;
метрики/алерты не в Grafana; uBook AI-тома orphan; кабинет без browser smoke;
нет явного smoke-таргета стека.

**Recommendation:**
Работать последовательно на ветке `epic/CNV-54`. Implementer = grok; механику
(docs/Makefile/rename/lint-fix) делегирует composer. Если по карте нужна развилка —
коммиты уходят в `defer/<CNV-ID>`, к эпику возвращаемся после остальных. По ходу —
только нужные тесты; на integration gate — полный suite.

**Acceptance Criteria:**
- Все 6 подзадач в `ready/` (не `done/` до approve эпика).
- Integration checklist зелёный.
- Нет незакрытых defer-веток без явной пометки на карточке эпика.

**Decisions:**
- (2026-08-02) Состав 6 карт + команда (team-lead / grok implementer / composer
  chore / reviewer) согласованы с @user.
- Подзадачи — существующие CNV-карты (не перенумеровываем в CNV-54-NN); линк
  через `**Epic:** [[CNV-54]]` по образцу CNV-47 / CNV-48 / CNV-52.
- Вне эпика: CNV-5, CNV-41 (Stage 7), CNV-30, CNV-28 (квоты/pay-per-use + freeze CNV-12).

**Subtasks (порядок = порядок исполнения; все последовательные):**
1. `CNV-32` — публичный запуск remote-воркеров (`deploy/` + gist install)
2. `CNV-34` — rate limiting per-IP и per-user (KeyDB)
3. `CNV-24` — метрики и алертинг (worker health, errors)
4. `CNV-44` — uBook: orphan-тома `worker-ai-models`/`worker-ai-data`
5. `CNV-9` — кабинет `/dashboard`: browser smoke клиентского JS
6. `CNV-39` — smoke-run стека, логи, узкий verify (полный suite — на gate)

**Integration checklist** (эпик покидает progress/ только при зелёном):
- [x] Все 6 подзадач в `ready/`.
- [x] Defer-ветки (если были) либо вмержены, либо явно задокументированы. (defer-веток не было)
- [x] `make docker-check` (dev + test: ok)
- [x] `make phpstan` + `make cs-check` (128+18 files, 0 errors; cs 0/227)
- [x] Полный тест-suite: `make test` (PHPUnit 522/2158; pytest 313 passed, 2 skipped, 1 xfailed)
- [x] Нет регрессий по затронутым зонам (deploy, rate-limit, metrics, uBook volumes, dashboard, smoke)

**Status:** ready.

## Execution Log
- 2026-08-02: integration gate on `epic/CNV-54` — `make docker-check` dev+test ok; `make phpstan` 128+18 files 0 errors; `make cs-check` 0/227; `make test` PHPUnit 522 tests / 2158 assertions (1 deprecation); pytest 313 passed / 2 skipped / 1 xfailed (data 98, doc 18, image 34+1 xfail, audio 31, video 16, ai 111+2 skip, drift 5).
- 2026-08-02: все 6 подзадач (CNV-32/34/24/44/9/39) уже в `ready/`; defer-веток нет.

**Branch:** один `epic/CNV-54` на весь эпик (подзадачи НЕ получают своих веток;
исключение — `defer/<CNV-ID>` при необходимости решения).

**Out of scope:**
- CNV-5 / CNV-41 (Stage 7).
- CNV-30 / CNV-28 (tier quotas / pay-per-use; freeze CNV-12).
- Payments providers beyond Telegram MVP.
