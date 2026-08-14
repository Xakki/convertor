### Анимированный SVG в анимированный GIF

**Criticality:** Medium

**TAGS:**
- feature
- images
- svg
- animation

**Description:**
Добавить отдельный поток конвертации поддерживаемого анимированного SVG в
многокадровый GIF. Статичный SVG → GIF остаётся в CNV-75 и не меняет свою
семантику.

**Problem:**
Текущий image-worker рендерит SVG через один вызов `CairoSVG.PNGSurface.convert`,
после чего Pillow сохраняет единственное изображение. Он не имеет временной шкалы
и не создаёт анимированный GIF. Простое добавление `gif` в SVG targets привело бы к
тихому однокадровому fallback вместо анимации.

**Impact:**
Пользователь не сможет получить анимацию из SVG. Непроверенное добавление формата
создаст неверные ожидания, потенциально неограниченную нагрузку по кадрам и
небезопасный контракт произвольных параметров renderer'а.

**Recommendation:**
Сначала выбрать, добавить в образ image-worker и проверить timeline-capable SVG
renderer. Затем реализовать строгий whitelist настроек, обновить API, catalog,
worker capabilities и UI. Не принимать raw аргументы renderer'а/FFmpeg. Внешние
URL/file resources должны оставаться запрещёнными.

**Acceptance Criteria:**
- Выбранный и поставляемый с image-worker renderer воспроизводит явно
  поддержанные типы SVG-анимаций по временной шкале; `svg → gif` публикуется в
  catalog/capabilities/UI только после этого.
- Fixture поддерживаемого анимированного SVG даёт GIF с `n_frames >= 2`,
  ожидаемыми размером, порядком кадров, duration и loop.
- Неподдержанные SMIL, CSS, JS-driven SVG и типы анимаций, которые не умеет
  выбранный renderer, завершаются понятной ошибкой без fallback в один кадр.
- Внешние URL/file resources не загружаются; ошибка не раскрывает путь,
  SVG-содержимое или traceback.
- API валидирует утверждённый whitelist и его границы; UI показывает только
  реально поддержанные параметры и сохраняет их по target format.
- Добавлены worker, API, catalog/capability drift и UI options tests; применимые
  pytest, `make test` и `make build` зелёные.

**Open questions:** *(only for `grooming/` cards — fold each resolution into **Decisions:** below, then remove this section before moving to `todo/`)*
- Какой renderer поддерживает нужный тип SVG-анимации в контейнере image-worker
  и допускается по лицензии/размеру образа? До выбора нельзя обещать SMIL, CSS
  или JS-анимации.
- Какой минимальный whitelist подтверждён возможностями renderer'а: width/height,
  FPS, максимальная длительность или число кадров, loop count, прозрачность/фон,
  а также palette/dither при необходимости?
- Какие безопасные лимиты duration, FPS, количества кадров и размера результата
  нужны для free/paid пользователей?

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Создана отдельная grooming-карточка, а не расширение CNV-75: CNV-75 фиксирует
  статичный однокадровый GIF и legacy/icon targets.
- До выбора renderer'а единственные подтверждённые настройки статичной ветки —
  width/height; FPS, duration, loop, palette/dither и прозрачность ещё не имеют
  реализации или whitelist в репозитории.
- Завершённая CNV-74-01 остаётся ориентиром безопасного SVG-input pipeline, но не
  предоставляет временной SVG-rendering.
