### Перевести /formats, SEO-страницы и валидацию пар на каталог

**Criticality:** High

**TAGS:**
- feature

**Description:**
Часть эпика CNV-71. Требует завершённой CNV-71-01 (каталог форматов уже существует в репо).

**Problem:**
`GET /api/v1/formats`, `/convert/{src}-to-{dst}` и построение пар/chain-BFS сегодня зависят от `worker_capabilities`: без строк воркеров в БД сайт останется с пустым списком форматов и 400 на любую конвертацию.

**Impact:**
Пока валидация и `/formats` завязаны на `worker_capabilities`, удаление seed-строк (CNV-71-04) невозможно без риска пустого каталога форматов на сайте.

**Recommendation:**
`GET /api/v1/formats`, `/convert/{src}-to-{dst}` и построение пар/chain-BFS берут данные из каталога (CNV-71-01), а не из `worker_capabilities`. Строки воркеров остаются источником ответа «есть ли такой тип воркера», но больше не определяют, какие форматы существуют. Разобраться, что делать с ручным дублем `CuratedConversionPairs.php` и с таблицей в `ROADMAP.md:182-207` — как минимум пометить каталог единственным источником правды и убрать расхождения. Кэш `conv.worker.matrix` и его инвалидацию пересмотреть: каталог статичен, инвалидация по регистрации воркера ему не нужна.

**Acceptance Criteria:**
- `/api/v1/formats`, SEO-страницы `/convert/{src}-to-{dst}` и валидация пар/chain-BFS читают каталог, а не `worker_capabilities`
- `CuratedConversionPairs.php` и таблица в `ROADMAP.md:182-207` приведены в соответствие каталогу (расхождения устранены)
- Кэш/инвалидация `conv.worker.matrix` пересмотрены под статичный каталог
- Tests/QA green: `make phpstan`, `make cs-check`, тесты — см. CLAUDE.md

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Формат виден в UI без воркера, без пометки недоступности — подтверждено пользователем 2026-08-04
</content>
