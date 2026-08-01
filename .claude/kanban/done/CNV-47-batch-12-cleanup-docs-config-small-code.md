### EPIC: Batch — 12 простых cleanup-задач (docs/config/small-code)

**Criticality:** Minor
**Type:** epic

**TAGS:**
- tech-debt
- epic

**Description:**
Пакет из 12 уже отгрумленных простых карт из `todo/`: docs-only, Makefile/env
config и мелкий код без открытых вопросов. Цель — закрыть техдолг одним
эпиком на общей ветке, не трогая крупные фичи (платежи, CI, poll-login, Stage 7).

**Problem:**
Мелкие cleanup-карты копятся в `todo/` и блокируют внимание; каждая по отдельности
дешёвая, но вместе даёт заметный hygiene-эффект.

**Impact:**
Дрейф доков/help/конфигов продолжается; мелкие UX/auth/queue баги остаются.

**Recommendation:**
Работать последовательно на ветке `epic/CNV-47`. Implementer = grok; механику
(docs/Makefile/rename) делегирует composer. Если по карте нужна развилка —
коммиты уходят в `defer/<CNV-ID>`, к эпику возвращаемся после остальных.
По ходу — только нужные тесты; на integration gate — полный suite.

**Acceptance Criteria:**
- Все 12 подзадач в `ready/` (не `done/` до approve эпика).
- Integration checklist зелёный.
- Нет незакрытых defer-веток без явной пометки на карточке эпика.

**Decisions:**
- (2026-08-01) Состав 12 карт + команда (team-lead / grok implementer / composer
  chore / reviewer) согласованы с @user.
- Подзадачи — существующие CNV-карты (не перенумеровываем в CNV-47-NN); линк
  через `**Epic:** [[CNV-47]]` по образцу admin-panel.
- Запасной слот при defer: CNV-11 (не в основном списке).

**Subtasks (порядок = порядок исполнения; все последовательные):**
1. `CNV-33` — docs: WS result-frame `completion` → `result{inline}`
2. `CNV-43` — Makefile help: e2e покрытие без ffmpeg
3. `CNV-6` — docs: soft-filter матрицы не вводить
4. `CNV-22` — legal: cookie-consent RU+EN
5. `CNV-18` — docs/UI: shrink каталога форматов
6. `CNV-23` — harbor-login env (`DOCKER_*`)
7. `CNV-16` — `make test-php-unit`
8. `CNV-17` — fluent-bit loopback + docs sidecar
9. `CNV-2` — 403 auth_required без «Telegram»
10. `CNV-13` — drift-тест `APP_ENV=test` (native path)
11. `CNV-36` — GC junk `test:worker` в worker_capabilities
12. `CNV-21` — dedup inflight auth/refresh в хедере

**Integration checklist** (эпик покидает progress/ только при зелёном):
- [x] Все 12 подзадач в `ready/`.
- [x] Defer-ветки (если были) либо вмержены, либо явно задокументированы.
- [x] `make docker-check`
- [x] `make phpstan` + `make cs-check`
- [x] Полный тест-suite: `make test` (или эквивалент TEST=1 full gate)
- [x] Нет регрессий по затронутым зонам (queue docs, auth copy, header refresh, drift)

**Execution Log:**
- Gate: `docker-check` / `phpstan` (0) / `cs-check` — OK.
- `make test`: PHPUnit 495/1992 OK; pytest suites OK; drift 5 OK.
- Миграция `Version20260801120000` применена на test-стенде.

**Residuals:**
- untracked `backup_CNV-23-make-login-not-configured.md`
- seed-examples: смена S3 key path (CNV-18)
- live GC migration: на deploy-хостах нужен `make migrate`
- uBook fluent bind может оставаться `0.0.0.0` до rebind

**Branch:** один `epic/CNV-47` на весь эпик (подзадачи НЕ получают своих веток;
исключение — `defer/<CNV-ID>` при необходимости решения).

**Out of scope:**
- CNV-11 (запас), CNV-20/44 (ops), CNV-9 (browser smoke), крупные фичи.
