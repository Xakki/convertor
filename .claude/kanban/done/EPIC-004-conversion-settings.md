### Backend-контракты каталога конвертаций

**Criticality:** High

**TAGS:**
- feature
- api
- frontend
- validation
- conversion-options

**Description:**
Реализовать backend/API profiles, validation, catalog и normalization для всех
последующих conversion domains.

**Problem:**
Настройки сейчас image-only, а три доменные задачи без общего контракта продублируют
UI, server validation, plan policy и job payload.

**Impact:**
Нельзя безопасно и единообразно добавлять параметры в новые conversion categories.

**Recommendation:**
Выполнить CNV-85 первым, затем по одному domain profile: document, audio/video и
CSV/JSON. Все options остаются profile-derived и server-side whitelisted.

**Acceptance Criteria:**
- Выполнены AC всех ОДИННАДЦАТИ дочерних карточек (CNV-85, CNV-97, CNV-98,
  CNV-100, CNV-101, CNV-103, CNV-104, CNV-75, CNV-95, CNV-88, CNV-106); raw renderer/FFmpeg/LibreOffice/
  serializer arguments отсутствуют, plan policy проверяется сервером.
- pytest, `make test` и `make build` зелёные после интеграции.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Общая грамматика и catalog принадлежат CNV-85; доменные карточки добавляют только
  собственные profiles и применение нормализованных options worker-ом.

**Decision digest (epic-lead, 2026-08-23):**
- **EPIC ID:** EPIC-004 — Backend-контракты каталога конвертаций.
- **Approved children (11, расширено пользователем 2026-08-24):** CNV-85 → CNV-97 →
  CNV-98 → CNV-100 → CNV-101 → CNV-103 → CNV-104 → **CNV-75** → CNV-95 →
  **CNV-88** → CNV-106.
- **Расширение объёма (2026-08-24, решение пользователя):** обе оставшиеся карточки
  оказались заблокированы карточками вне эпика, и блокеры реальны, а не бумажные.
  CNV-95 требует пар `svg→gif/bmp/tiff/ico`, которых НЕТ в `conversion_pairs.json`:
  каталог генерируется из возможностей воркеров (`make formats-catalog`,
  защищён drift-тестами), поэтому бэкенд не может опубликовать профиль для
  несуществующих пар. Их создаёт CNV-75 (image-worker, CairoSVG+Pillow).
  CNV-106 требует вида исполнения `browser` и stream `conv.browser` — их вводит
  CNV-88. Обе втянуты в эпик.
- **ВНИМАНИЕ — для SVG порядок зависимости ОБРАТНЫЙ остальному эпику.** Везде было
  «бэкенд-профиль → воркер»; здесь «воркер → бэкенд-профиль», потому что каталог
  пар генерируется из воркеров. CNV-75 обязана идти ПЕРЕД CNV-95.
- **Разрешение противоречия карточки:** Subtasks перечисляли только backend-профили
  (85/95/97/100/103/106), а AC — только worker-карточки (85/76/77/78). Принят полный
  набор: эпик закрывает вертикаль от API-контракта до применения опций воркерами.
  Списки Subtasks и AC ниже приведены в соответствие этому решению.
- **Dependencies:** всё гейтится на CNV-85 (грамматика + catalog + normalization).
  Далее каждая worker-карточка строго за своим backend-профилем: 98←97, 101←100,
  104←103. CNV-95 независима после CNV-85. **CNV-106 требует CNV-88**
  (`chromium-worker-routing-sandbox`, `executionKind=browser`) — CNV-88 в состав
  эпика НЕ входит.
- **Parallel work:** не разрешена. Backend-профили пишут в общий catalog/registry,
  созданный CNV-85 (пересекающаяся зона), поэтому карточки идут строго последовательно
  в указанном порядке.
- **Authorization form:** explicit user approval at hand-off. Ни одна дочерняя
  карточка не уходит в `done/` сама — их гейтит родитель. Push и merge в `main` —
  только после явного «ок» пользователя.
- **Baseline:** default branch `main` @ `51e55de`, рабочее дерево чистое.
  Единственная ветка эпика — `epic/EPIC-004`.
- **Scoped gate (каждая дочерняя карточка):** `make phpstan` + `make cs-check` +
  тесты (`make TEST=1 …` — гранулярные тест-таргеты без `TEST=1` смотрят в dev-стенд).
  Docker — только через Makefile-таргеты.
- **Naming deviation:** дочерние карточки сохраняют исторические ID `CNV-*` вместо
  канонической схемы `EPIC-004-NN`. Переименование — расширение объёма и правка
  счётчиков в `kanban.lock`, поэтому не делается.
- **Parked risks:**
  1. CNV-106 заблокирована CNV-88 (вне состава эпика) — при подходе к карточке
     блокер проверяется; если подтвердится, карточка паркуется и решение возвращается
     пользователю, chromium-инфраструктура в эпик не втягивается.
  2. Дрейф в `ROADMAP.md`: в «Stage 7 — реально НЕ реализовано» svg числится
     нереализованным, но в `app-symfony/config/catalog/conversion_pairs.json` уже есть
     4 живые пары `svg → jpeg/jpg/png/webp` (category `image`). Svg не реализован
     только как ЦЕЛЬ. Формулировка правится в рамках эпика (касается CNV-95/106).
  3. SVG worker-карточки (CNV-75, CNV-82) в состав эпика не входят — backend-профили
     95/106 приземляются без применения воркерами; это осознанно.

**Decision update (2026-08-24):** эпик переключён с worker-карточек CNV-76/77/78 на
CNV-98/101/104. Причина: на борде оказалось два поколения карточек для одной и той же
worker-работы (document/media/data application) — старое (CNV-76/77/78, в `todo/`) и
новое (CNV-98/101/104, в `todo/`), при этом новое поколение нумерационно согласовано со
своими backend-карточками (97→98, 100→101, 103→104), а старое — нет. Пользователь выбрал
новое поколение. Перед retire все правила/AC старых карточек свёрнуты в новые (включая
случай CNV-98, чья Recommendation ссылалась на «правила CNV-76» — ссылка заменена
реальными правилами), чтобы ничего не потерялось; CNV-76/77/78 перенесены в `freeze/` как
superseded (не `done/` — работа не выполнена).

**Subtasks:** *(порядок исполнения; см. decision digest)*
- CNV-85 — backend catalog, грамматика опций и normalization
- CNV-97 — document backend profile
- CNV-98 — применение document-опций в LibreOffice-воркере
- CNV-100 — media backend profile
- CNV-101 — применение media-пресетов в FFmpeg-воркере
- CNV-103 — data backend profile + защита загрузчика (minPlan варианта ≥ поля)
- CNV-104 — применение CSV/JSON-настроек в data-воркере
- CNV-75 — статичные SVG-цели в image-воркере (создаёт пары для CNV-95)
- CNV-95 — static SVG backend catalog
- CNV-88 — вид исполнения browser и stream conv.browser
- CNV-106 — animated SVG backend profile (НЕ публикуется, см. ниже)

**Итог сдачи (интеграционный гейт 2026-08-25):**
`make test` и `make build` — оба exit 0. PHP 1016 тестов / 5856 ассертов,
Python 431 passed / 1 xfailed / 2 skipped, drift 28, gateway 224 / 1 skipped.
Каталог: 15 правил, все с явными `category`/`ocr`/`animated`, затенение
невозможно. Восемь реальных пар `svg→*` разведены корректно, `svg→ico`
намеренно без профиля.

**НЕ ВЫПОЛНЕНО (не сглаживать при передаче) — всё в CNV-106:**
1. AC «каталог публикует как `executionKind=browser`» — НЕ выполнен как записан:
   маршрутизация идёт через отдельный флаг `$animated`, а не через `executionKind`
   строки. Причина уважительная: `executionKind` задаётся НА ПАРУ, а пара
   `svg→gif` общая со статикой, и переключение увело бы уже опубликованную
   статическую конвертацию. Требует ack.
2. Лимиты по ДЛИТЕЛЬНОСТИ и ЧИСЛУ КАДРОВ (guest 5 s, free 10 s/150, basic/pro
   30 s/720) НЕ реализованы — в PHP-зоне нет компонента, разбирающего SVG.
   Лимиты по пикселям и FPS сделаны и проверены. Работа для CNV-82/CNV-113.
3. AC «нормализованная задача несёт browser execution kind» недемонстрируем в
   проде: по решению пользователя пара НЕ публикуется, пока нет browser-воркера,
   поэтому этот путь живьём не достигается. Пиновано тестами.

Остальные 10 карточек — AC выполнены полностью, проверено по пунктам.

**Публикация CNV-106 требует ТРЁХ предпосылок, не одной:**
(a) проброс `animated` через DTO/Controller/Validator и гейт на создании;
(b) сохранённое `Conversion::isAnimated` + миграция + правка `routingKey()` —
    без этого создание видит `browser`, а отправка пересчитывает маршрут из
    сущности и получает `image` (тихая рассинхронизация);
(c) расширение `reduceCapabilities()` (карточка CNV-130).

**Перенесено в работу из ревью CNV-100 (2026-08-24):**
- Загрузчик каталога НЕ проверяет, что `minPlan` варианта select не ниже `minPlan`
  самого поля. Инверсия дала бы `field.editable:false` рядом с `option.editable:true`
  в `GET /formats` (отправка всё равно отклоняется валидатором — это не дыра в
  безопасности, а рассогласование выдачи). Сегодня инверсий в каталоге нет.
  Защиту ставит исполнитель CNV-103 ПЕРЕД написанием своего профиля, чтобы
  оставшиеся профильные карточки (CNV-103, CNV-95, CNV-106) писались уже под ней.

**Integration checklist:**
- Проверить document, audio/video и CSV/JSON fixtures, plan policy и отказ
  невалидных preset’ов.
- **Пересобрать и раскатать образ document-воркера.** CNV-98 добавила
  `python-docx` и `lxml` в `docker/workers/requirements-libreoffice.txt`, то есть
  изменила РАНТАЙМ-образ. Хост со старым образом упадёт на `import docx`.
  Затрагивает главный сервер и каждый удалённый хост с `worker-libreoffice`
  (сейчас — uBook). Порядок: сборка/push в Harbor → `make pull` →
  `workers-recreate` на каждом хосте (скилл `remote-workers`). Проверять по
  `worker_capabilities` с ГЛАВНОГО сервера, не по `docker ps` на хосте.
- Выполнить pytest, `make test` и `make build`.
