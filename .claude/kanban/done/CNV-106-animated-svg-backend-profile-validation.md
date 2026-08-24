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

## Execution Log (backend, 2026-08-25)

### Task A — closed gap: `isAi=true` + `executionKind` на одном ряду

`ConversionRegistry::streamFor()` проверяет `isAi()` СТРОГО РАНЬШЕ `executionKind`
(class docblock), а `loadCatalogMatrix()` валидировал `executionKind` изолированно,
никогда не сверяя с `isAi`. Ряд с обоими флагами грузился без единой ошибки и
молча уезжал в `'ai'`, override маршрутизации отбрасывался беззвучно. Добавлен
громкий `\RuntimeException` в `loadCatalogMatrix()` при `isAi===true &&
executionKind!==null`, той же политикой, что и остальные malformed-catalog
проверки (`ConversionRegistry.php`). Тест
`ConversionRegistryCatalogLoadingTest::testAiRowWithExecutionKindThrows`.
**Can-fail:** закоротил guard (`if (false && ...)`) → `Failed asserting that
exception of type "RuntimeException" is thrown.` Восстановил → 14/14 зелёные,
`diff` с бэкапом файла пуст.

### Task B — дискриминатор: request-scoped флаг `animated`, по образцу `ocr`

**Инвестигация (см. советник до правок).** Static `svg→gif` уже опубликован
(CNV-95, `image.raster`) — та же from/to пара. `conversion_pairs.json` хранит
РОВНО один маршрут на пару (`$matrix[$from][$to]`, ассоциативный ключ), поэтому
«анимировано или нет» СТРУКТУРНО не может быть выражено ни отдельной строкой
каталога пар, ни через `executionKind` (CNV-88): если бы svg→gif нёс
`executionKind: browser`, ВЕСЬ трафик статичного svg→gif (уже боевого) тоже
уехал бы в browser — это была бы регрессия CNV-95, а не новая фича. Отклонённые
опции:
1. **`executionKind` на строку пары** — отклонено выше (ломает CNV-95).
2. **Новый target-формат** (напр. `svg→agif`) — отклонено: карточка явно
   говорит «та же from/to пара», плюс `conversion_pairs.json` генерируемый,
   новый формат не появится, пока capability-блоб его не объявит (вне зоны).
3. **Sniffing содержимого SVG** (парсинг `<animate>`/CSS `@keyframes` для
   авто-определения) — вне ocr-прецедента (тот флаг чисто client-declared,
   контента не читает для решения о флаге) → не строил, см. STOP-правило карточки.
4. **Request-scoped флаг `animated`, зеркалящий `ocr`** — выбрано. `ocr` уже
   одновременно: (а) matcher в `assignments` каталога настроек, (б) параметр
   `ConversionRegistry::streamFor()`, (в) выбирается БЭКом при постановке в
   очередь (CLAUDE.md). `animated` построен 1:1 по той же форме.

**Реализация (следует ocr-прецеденту максимально близко):**
- `ConversionRegistry`: `ANIMATED_SOURCES=['svg']`/`ANIMATED_TARGETS=['gif']`
  (тот же hardcoded-allowlist стиль, что `OCR_SOURCES`/`OCR_TARGETS`, а НЕ
  через catalog `executionKind` — см. докблок), `isAnimatedConversionSupported()`,
  `streamFor(..., bool $animated = false)` — новая ветка ПОСЛЕ `ocr`, ДО
  `isAi()`, возвращает `WorkerType::Browser->value` или громко бросает
  `\InvalidArgumentException` для пар вне allowlist.
- `ConversionSettingsCatalog`: новый bool-matcher `animated` в `assignments`
  (парсится/валидируется той же функцией, что `ocr`), `resolveProfileId()`/
  `resolveProfile()` получили `bool $animated = false`.
- `ConversionOptionsValidator`: `resolveProfile()`/`validate()` получили тот
  же параметр, пробрасывают в каталог.
- **Ни ConversionController, ни ConversionRequestDTO, ни ConversionManager, ни
  Entity `Conversion` НЕ тронуты** — они не читают/не пробрасывают `animated`
  вовсе. Подтверждено чтением: `ConversionController::convert()` вызывает
  `validate(..., $ocr && $hasFile)` (5 позиционных, без 6-го аргумента);
  `ConversionManager::routingKey()` вызывает `streamFor($from, $to,
  $conversion->isOcr())` — Entity `Conversion` не имеет `isAnimated()`. Значит
  ни один живой HTTP-запрос сегодня физически не может передать `animated=true`
  ни в валидатор, ни в роутинг.

### Публикация НЕ на `executionKind`-строке, а через закрытый флаг — почему нет риска 503/hang

CNV-88 уже построил структурный барьер (`executionKind` не эмитится генератором),
плюс `ConversionManager` гейтит по `worker_capabilities` (`existsForWorkerType`)
— живой browser-запрос сегодня получил бы 503, не hang. Но это НЕ довод строить
publish сейчас: явное решение тимлида «не публиковать» соблюдено буквально —
ни один живой путь не может задать `animated=true` вовсе (не полагаемся на
503-гейт как единственную защиту).

### Профиль `image.svg.animated`

| Поле | Тип | Границы | minPlan (поле) | minPlan (опции) | default |
|---|---|---|---|---|---|
| width | number | 1..1280 px | basic | — | 640 |
| height | number | 1..1280 px | basic | — | 640 |
| fps | select | 12/15/24 | guest | 12→guest, 15→free, 24→basic | "12" |
| loop | select | once/infinite | guest | обе guest | null |
| background | text+pattern `(?i:transparent|white)\|#[0-9A-Fa-f]{6}` | maxLength 11 | guest | — | null |

**Обоснование minPlan по стоимости:** width/height/fps — единственные
CPU/memory-costly параметры (browser-рендеринг), поэтому basic/guest+select-tier
(не default-caution) — прямое требование карточки/CLAUDE.md. loop/background —
метаданные GIF без разницы в стоимости рендера → `guest` для обоих, норма.
**Дефолты:** width/height/fps МАТЕРИАЛИЗУЮТСЯ (640/640/"12") — иначе guest-кап
был бы enforced только против явной попытки прислать значение, а omission ушёл
бы в дефолт (будущего) browser-воркера, который может быть больше 640/выше
12fps — кап стал бы фиктивным. loop/background оставлены без default (как
`background` у `image.bmp`, CNV-95: worker сам возьмёт разумный дефолт, нет
cost-обоснования материализовывать).
**`background` — text+pattern, не color/select.** `transparent|white|#RRGGBB` —
смешанный enum+произвольный hex, не выражается ни чистым `color` (только
`#RRGGBB`), ни закрытым `select` (hex не enum). Существующая грамматика уже
поддерживает `text`+`pattern` (см. `document.pdf.pageRange`) — переиспользован
без PHP-правки.
**duration/frame-count (5/10/30s, 150/720 frames) — НЕ поля.** В зоне backend-PHP
нет компонента, читающего SVG-контент; card's Decisions сами говорят «duration
определяется SVG и ограничивается сервером» — это относится к browser-воркеру
(CNV-82/113), не к Symfony. Явно назван как «без can-fail evidence» ниже.

### Публикационный switch — ДВА предусловия, не одно (исправлено ревью)

Первая версия этой секции называла switch «одним изменением» — неверно,
поправлено. `ConversionManager::dispatch()` резолвит транспорт через
`routingKey(Conversion $conversion)` → `streamFor($from, $to,
$conversion->isOcr())` (3 аргумента) — **из персистентной Entity**, не из
запроса; `$animated` там не участвует, потому что персистить его сегодня
негде. Если владелец CNV-82/107/113 заведёт `animated` только в
DTO/Controller/Validator/create-time-гейт (как сделан `ocr` в query-time), но
не тронет `Conversion` entity + `routingKey()` — create-time гейт увидит
`browser` (значит, capability есть) и вернёт 202, а фактический `dispatch()`
всё равно посчитает маршрут по Entity без `isAnimated`, получит `image` и
молча отправит анимированную задачу в `conv_image` — ровно тот класс дефекта
(тихий silent misroute), который чинит весь EPIC-004. Поэтому switch — это:

1. `animated` в `ConversionRequestDTO` + чтение
   `$request->request->getBoolean('animated')` в `ConversionController::convert()`
   + проброс в `optionsValidator->validate(..., $ocr, $animated)` и в
   create-time гейт — паттерн `ocr`, query-time часть.
2. **Отдельно и обязательно вместе с (1):** персистентное поле
   `Conversion::isAnimated` (+ Doctrine-миграция), зеркалящее `isOcr`, и
   правка `routingKey()` — читать `$conversion->isAnimated()` четвёртым
   аргументом `streamFor()`. Без (2) retry (`retryConversion()`, строка 285 —
   уже сегодня зовёт `streamFor(..., false)` без animated) тоже не наследует
   флаг корректно.

Отдельно (уже задокументировано CNV-88, не заново): владелец browser-воркера
должен расширить `ConversionRegistry::reduceCapabilities()`/
`getSupportedFormatsFromBlobs()`, чтобы capability-блоб browser-воркера вообще
существовал — иначе после включения switch'а live-запрос получит 503, а не hang.
Делает владелец CNV-82/CNV-107/CNV-113 — записано и в `$comment` каталога.

### Drift-фикс докблока (found + fixed in same change)

Докблок `ConversionRegistry` (CNV-88) утверждал «animated SVG→GIF может нести
`category: image` + `executionKind: browser`» — фактически НЕВЕРНО (см. выше,
сломало бы CNV-95). Скорректировано в этом же изменении с явной ссылкой на
причину.

### Гейт

- `make phpstan` — OK, 0 ошибок (оба конфига).
- `make cs` — 0 файлов изменено; `make cs-check` — 0 из 291 требуют правок.
- `make TEST=1 test-php` — **1016 тестов / 5856 ассертов, 0 падений** (было
  983/5796 — task-prompt baseline, +33 теста/+60 ассертов: 1 Task A + ~32 CNV-106).
- `make TEST=1 test-drift` — **28 passed** (`conversion_pairs.json` не тронут,
  подтверждено `git diff --stat` пустым).
- `make TEST=1 test-gateway` — **224 passed / 1 skipped** (не изменилось).
- `make TEST=1 test-python` — **431 passed / 1 xfailed / 2 skipped**
  (116+77+51+60+16+111, не изменилось — `workers/` не трогал).

### Can-fail proofs (сломал → RED по нужной причине → восстановил → зелёный;
бэкапы `/tmp/backup/convertor/backup_conversion_settings.cnv106-good.json` +
`backup_ConversionRegistry.cnv106-taska-good.php`, восстановление подтверждено `diff`)

**(a) Task A guard.** См. выше — `Failed asserting that exception of type
"RuntimeException" is thrown.`

**(b) plan cap enforced (guest/free не могут превысить 640px/12fps).** Понизил
`width.minPlan` `basic→guest` в JSON → 2 красных по нужной причине:
`testAnimatedSvgRejectionsFollowClosedGrammar@guest cannot touch width at all`
и `@free cannot exceed 640px width` — «Ожидался отказ с кодом
option_plan_required» (значение прошло вместо отказа). Восстановил → 14/14 зелёные.

**(c) pair NOT published.** Флипнул `animated: true→false` у нового правила
(симулирует «конвенция файла нарушена, животный wildcard съел его») → 3
красных по нужной причине: `testFormatsExposesVersionedDeduplicatedProfiles`
(`'image.raster'` ожидалось, получено `'image.svg.animated'`),
`testAnimatedFieldInThePostBodyIsIgnoredJobStillRoutesToImage` (options внезапно
несут `width/height/fps`), `testAnimatedSvgProfileIsNeverAdvertisedByFormats`
(та же подмена). Восстановил → зелёные.

**(d) raw/unknown renderer key rejected.** Добавил фиктивное поле `duration` в
профиль JSON (симулирует «поле просочилось») →
`testAnimatedSvgRejectionsFollowClosedGrammar@duration is not a field` красный:
«Ожидался отказ с кодом unknown_option» (принято вместо отказа). Восстановил
→ 14/14 зелёные.

Все 4 restore подтверждены `diff` с бэкапом = пусто + полный
`make TEST=1 test-php` = 1016/5856, 0 падений.

### Без can-fail evidence (явно, как требует задание)

- **Duration/frame-count caps (5s/10s/30s, 150/720 frames)** — специфицированы
  в карточке, но НЕ реализуемы в зоне backend-PHP (нет компонента, читающего
  SVG-контент); принадлежат browser-воркеру CNV-82/113. Ни поля, ни теста нет —
  это НЕ забытая часть, а сознательно вынесенный вне-зоны контракт, задача его
  создателю: сервер должен клэмпить длительность к максимуму плана при
  фактическом рендере.
- **Публикационный switch целиком** (проводка `animated` в DTO/Controller/
  Manager) — не реализован (по требованию карточки «не публиковать»), поэтому
  нет теста на его РАБОТУ через реальный HTTP — только тесты на его ОТСУТСТВИЕ
  (can-fail (c) и функциональный `testAnimatedFieldInThePostBodyIsIgnoredJobStillRoutesToImage`).

### Нужно подтверждение тимлида / handoff

1. **CNV-82/CNV-107/CNV-113**: публикационный switch — **три** предусловия
   (query-time DTO/Controller/Validator/create-time-гейт; персистентный
   `Conversion::isAnimated` + миграция + `routingKey()`; расширение
   `reduceCapabilities()` для browser capability-блоба) — см. секцию выше,
   ни одно не покрыто этой карточкой (третье уже отмечено CNV-88).
2. **AC-формулировка карточки vs факт реализации.** AC карточки говорит
   «публикует как `executionKind=browser`» — по факту маршрутизация идёт
   через новый request-scoped флаг `$animated` (см. «Task B — дискриминатор»
   выше), а НЕ через `executionKind` на ряду каталога: `executionKind`
   per-ПАРЕ и переписал бы маршрут обоих вариантов svg→gif разом (сломал бы
   уже опубликованный статичный svg→gif, CNV-95). Прошу тимлида явно
   подтвердить, что это расхождение с буквой AC принято намеренно (причина
   задокументирована, но именно это — первое, что оспорит ревьюер).
3. **`background`: паттерн был `(?i:transparent|white)|#RRGGBB`, исправлено на
   `transparent|white|#RRGGBB`** (без регистронезависимости) — `text`-поле не
   канонизирует значение (в отличие от `color`, который аплкейсит hex), так
   что `(?i:)` пропускал бы `Transparent`/`TRANSPARENT`/`transparent` как три
   разных строки на выходе к воркеру. Остаточное: hex-регистр (`#aabbcc` vs
   `#AABBCC`) по-прежнему не канонизируется — `text`-тип не трансформирует
   значение; это контрактная строка для CNV-82/113 (воркер должен
   регистронезависимо фолдить сам), не баг этой карточки.
4. **Дефолт `width=640, height=640`** — квадратный холст по умолчанию для
   guest/free независимо от реального aspect ratio SVG. Fit-inside vs stretch
   — решение рендерера (browser-воркера), не сделано здесь; фиксирую как
   открытый вопрос для CNV-82/113, не решаю сам.
5. Drift-фикс докблока `ConversionRegistry` (CNV-88 ошибочно предполагал
   `executionKind` для animated SVG→GIF) — исправлено в этом же коммите,
   фиксирую для видимости команды.
6. Side finding: `ConversionManager`'s `workerCapabilities`-гейт (503, не hang)
   для browser — уже задокументирован CNV-88 как контингентный, не структурный;
   не менял, только подтвердил чтением, что publish-switch не полагается на
   него как единственную защиту.
