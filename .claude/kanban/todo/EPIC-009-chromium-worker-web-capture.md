### Отдельный Chromium worker для web capture

**Criticality:** High

**TAGS:**
- feature
- browser
- api
- security

**Description:**
Отдельный Chromium worker для безопасного screenshot HTML/URL и записи начальной
загрузки URL в WebM, с отдельной очередью, sandbox и тарифными limits.

**Problem:**
Browser runtime нельзя безопасно смешивать с image/video worker: ему нужны другой
egress, SSRF policy, sandbox, CPU/RAM и job lifecycle.

**Impact:**
Нельзя безопасно добавить URL capture, slow-network recording и web formats без
риска SSRF, утечки внутренних ресурсов и истощения очередей.

**Recommendation:**
Выполнить routing/sandbox → SSRF/egress → screenshots → video. Использовать
`conv.browser` и `WorkerType::Browser`; results остаются category image/video для
quota/retention. CNV-85 — prerequisite публичных settings profiles.

**Acceptance Criteria:**
- Выполнены AC CNV-88…CNV-91; jobs browser не попадают в image/video streams.
- Пройдены security, worker, API, catalog drift и e2e tests; полный gate — после
  восстановления EPIC-002.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- URL capture доступен только authenticated basic/pro; self-contained HTML screenshot:
  free — базовый profile, guest — fixed default.
- URL: public Internet через policy-enforcing egress proxy с проверкой каждого
  DNS/redirect hop; WebM без audio — MVP.

**Subtasks:**
- CNV-88 — browser route, container sandbox и catalog
- CNV-89 — URL ingress, SSRF и egress policy
- CNV-90 — screenshot HTML/URL
- CNV-91 — WebM video загрузки

**Integration checklist:**
- Проверить anonymous/guest/free/basic/pro policy, SSRF vectors и resource caps.
- Выполнить applicable quality gate.
