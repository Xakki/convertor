### Анимированный SVG → GIF: browser-worker execution

**Criticality:** Medium

**TAGS:**
- feature
- svg
- animation
- browser-worker

**Description:**
Реализовать выполнение поддерживаемого локального анимированного SVG → многокадровый GIF в isolated browser-worker. Карточка не изменяет backend profile validation или frontend controls.

**Problem:**
image-worker не имеет временной шкалы, а Chromium без выделенного `executionKind=browser`, sandbox и offline policy создаёт риск неверного однокадрового fallback и доступа к сети/файлам.

**Impact:**
Анимация SVG не конвертируется корректно либо нарушает изоляцию browser job.

**Recommendation:**
После CNV-88 запускать локальный SVG mode только в `conv.browser`: fresh context на job, без URL/file subresources, с ресурсными лимитами CNV-88. Рендерить только явно поддержанные SVG animations и создавать GIF с frame timing; неподдержанный input завершать понятной error без static fallback.

**Acceptance Criteria:**
- Job с `executionKind=browser` маршрутизируется в `conv.browser` и использует sandbox CNV-88.
- Локальный SVG mode не запрашивает сеть или filesystem subresources.
- Supported animated SVG fixture создаёт GIF с `n_frames >= 2`, проверяемыми frame order, duration и loop.
- Неподдержанные SMIL/CSS/JS animation type завершаются безопасной понятной ошибкой без однокадрового GIF и без утечки SVG/path/traceback.
- Worker-тесты покрывают isolation, успешный GIF и отказ; `pytest`, `make test`, `make build` зелёные.

**Decisions:**
- Карточка зависит от CNV-85, CNV-88 и CNV-106; browser frontend запускается отдельной CNV-107 после CNV-92.
- Feature остаётся offline: URL capture и recording не входят в scope.
- User options ограничены profile CNV-106: width/height, profile-limited FPS, loop `once|infinite`, background `transparent|white|#RRGGBB`; duration определяется SVG и ограничивается сервером.
