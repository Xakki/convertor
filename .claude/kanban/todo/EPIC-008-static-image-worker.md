### Статичные SVG targets image-worker

**Criticality:** High

**TAGS:**
- tech-debt
- worker-image

**Description:**
Последовательный поток атомарных карточек одной специализации: `worker-image`.

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
- Эпик выполняется последовательно после предыдущих EPIC; подзадачи принадлежат только `worker-image`-специалисту.
- CNV-75 completed and archived; removed from active subtask list.

**Subtasks:**

**Integration checklist:**
- Выполнить профильные тесты и проверить отсутствие обратных зависимостей.
