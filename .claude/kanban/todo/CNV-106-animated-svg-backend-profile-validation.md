### Анимированный SVG: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- svg
- animation
- browser-worker
- backend
- conversion-options

**Description:**
Определить backend profile и validation локального animated SVG → GIF для browser execution kind.

**Problem:**
Без отдельного profile API может опубликовать browser animation как static image target, принять raw renderer arguments или разрешить unsafe settings.

**Impact:**
Job будет неправильно маршрутизирован, а пользователь сможет создать непредсказуемую нагрузку или неверный output.

**Recommendation:**
После CNV-85 и CNV-88 назначить поддерживаемой паре profile с width/height, profile-limited FPS, loop `once|infinite`, background `transparent|white|#RRGGBB`; route задавать `executionKind=browser`. Validировать server-side profile caps и не принимать duration/palette/dither/raw renderer args.

**Acceptance Criteria:**
- Catalog публикует animated SVG → GIF только как `executionKind=browser`, а не image-worker route.
- Backend валидирует width/height, FPS, loop и background; unknown/raw renderer keys отклоняются.
- Server enforces caps: guest fixed 640px/12 FPS/5 s; free до 10 s/150 frames и 12/15 FPS; basic/pro до 1280px, 24 FPS, 30 s/720 frames.
- Normalized job содержит только разрешённые effective values и browser execution kind.
- API/contract tests покрывают plan caps, routing и invalid options.

**Decisions:**
- Зависит от CNV-85 и CNV-88; CNV-82 browser-worker и CNV-107 frontend начинаются после profile.
- Feature остаётся offline: remote URL, file subresources и recording вне scope.
- Duration определяется SVG и ограничивается серверным maximum; palette/dither не входят в MVP.
