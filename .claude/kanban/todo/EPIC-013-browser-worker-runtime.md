### Runtime browser worker

**Criticality:** High

**TAGS:**
- tech-debt
- worker-browser

**Description:**
Последовательный поток атомарных карточек одной специализации: `worker-browser`.

**Problem:**
Смешение разных зон в одной карточке не позволяет поручить работу одному профильному агенту.

**Impact:**
Последовательное выполнение эпиков блокируется скрытыми межзонными зависимостями.

**Recommendation:**
Выполнять указанные карточки по порядку; каждая имеет явные prerequisites в Decisions.

**Acceptance Criteria:**
- Выполнены AC всех дочерних карточек.
- Не остаётся cross-zone implementation scope внутри дочерней карточки.

**Decisions:**
- Эпик выполняется последовательно после предыдущих EPIC; подзадачи принадлежат только `worker-browser`-специалисту.

**Subtasks:**
- CNV-82
- CNV-90
- CNV-91

**Integration checklist:**
- Выполнить профильные тесты и проверить отсутствие обратных зависимостей.
