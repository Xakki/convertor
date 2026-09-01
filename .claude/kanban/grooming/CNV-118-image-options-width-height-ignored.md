### Image options (`width`/`height`) валидируются, но не применяются к результату

**Criticality:** High

**TAGS:**
- feature
- image
- browser
- animation
- conversion-options
- worker
- data-flow

**Description:**
При загрузке изображения с параметрами ресайза (`options[width]` и `options[height]`) параметры валидируются в backend API, нормализуются и возвращаются в ответе (202), но для части исторически проверенного сценария скачанный результат сохранял исходный размер. Карточка владеет единой resize-семантикой для всех явно поддержанных output-маршрутов, включая image-worker и browser/animated rendering; применение остаётся ответственностью соответствующего worker.

**Problem:**
Историческая живая проверка 2026-08-16 (авторизованный запрос, план pro) зафиксировала symptom, но сама по себе не доказывает текущее поведение после CNV-85:

1. `POST /api/v1/convert` с `-F "file=@sample.svg" -F "to_format=png" -F "options[width]=400" -F "options[height]=240"`
   → 202, `completed`, скачанный PNG оставался **200x120** (исходный размер).
2. `POST /api/v1/convert` с `-F "file=@ocr.jpg"` (600x200) и `-F "to_format=png" -F "options[width]=300"`
   → 202, результат оставался **600x200** (исходный размер).

Эти два результата — историческое наблюдение, а не текущая end-to-end-проверка. Старая трассировка из этой проверки также устарела: CNV-85 удалил `ConversionController::validateImageOptions()` и перенёс проверку в `App\Service\Conversion\Settings\ConversionOptionsValidator`; старый пример `{"error":"Unsupported image option"}` заменён текущим envelope с машинным кодом, например `{"error":"unknown_option","message":"..."}`.

Текущий статический trace после CNV-85 показывает другой путь: валидатор отдаёт worker уже нормализованные options, контракт сообщения описывает `options`, а `workers/image/worker.py` применяет `_apply_image_options()` в обычной raster-ветке и в SVG-ветках, кроме отдельной `svg→ico`-ветки. `_apply_image_options()` сохраняет аспект при одной заданной стороне, но использует `thumbnail()`, поэтому текущий код не увеличивает изображение сверх исходного размера. Это чтение исходников не заменяет локальную end-to-end-проверку через очередь и S3 и не устанавливает полный список поддерживаемых пар.

**Impact:**
Medium (feature contract и фактический результат могут расходиться):
- Пользователь может получить исходные размеры, несмотря на принятые и нормализованные параметры.
- Молчаливое расхождение API-настроек и S3-результата затрудняет диагностику.
- Для векторного источника изменение размера означает размер raster-рендера; для специальных маршрутов семантика может отличаться.
- Для animated/browser output resize должен иметь ту же согласованную семантику, но не может размывать границы browser execution и его изоляции.

**Recommendation:**
Сохранить принятие и server-side validation options и довести/проверить одну общую resize-семантику на явно согласованном supported-pair inventory. Владелец семантики — CNV-118; application-specific реализация принадлежит соответствующему worker: image-worker применяет её к image output, а browser worker — к browser/animated rendering output только после browser prerequisites. Для поддержанных пар целевая семантика: bounding box с сохранением aspect ratio; upscaling разрешён; при указании только `width` или только `height` недостающая сторона вычисляется из исходного aspect ratio. Проверить полный путь normalized options → выделенный stream/worker → S3 и фактические размеры скачанного объекта.

Browser execution не является синонимом output category: browser-задачи маршрутизируются отдельным `executionKind=browser` и собственным `conv.browser` stream, а image/video остаются категориями для quota/retention. CNV-118 не переносит browser routing в image worker, не объединяет sandbox, network, quota или retention policy и не разрешает выполнение до browser foundations.

**Dependencies:**
- Image-worker application может проверяться после имеющихся image routing/message prerequisites.
- Browser/animated application следует выполнять только после CNV-130, затем CNV-113 (isolated browser sandbox) и CNV-114 (policy-enforcing egress); эти карточки сохраняют собственные executionKind, stream, sandbox, quota и retention boundaries.
- CNV-118 не заменяет CNV-130/113/114 и не создаёт обходной browser path; browser worker остаётся недоступным до завершения этих prerequisites.

**Acceptance Criteria:**
- Для каждой пары, явно включённой в согласованный supported-pair inventory, локальный end-to-end test создаёт job через API/очередь, дожидается `completed`, скачивает результат из S3 и проверяет фактические `width`/`height` объекта.
- При заданных `width` и `height` результат вписывается в bounding box с сохранением aspect ratio; целевые размеры не искажают изображение, а upscaling допускается согласно утверждённой политике.
- При заданной только одной стороне в локальном S3 end-to-end test проверяется вычисленная из исходного aspect ratio недостающая сторона (с документированным правилом округления).
- Тесты покрывают raster input и каждый векторный/специальный маршрут только после явного включения соответствующей пары в supported-pair inventory; browser/animated output проверяется тем же semantic contract, но в отдельном browser worker после CNV-130 → CNV-113 → CNV-114. Непокрытые пары не считаются решёнными этой карточкой.
- Browser acceptance подтверждает `executionKind=browser` и `conv.browser`, отсутствие image-worker fallback, сохранение browser sandbox/egress isolation и независимых quota/retention semantics; CNV-118 не принимает решение об ослаблении этих gates.
- Очередной контракт и фактическое сообщение сохраняют normalized `options`; тест проверяет, что параметры доходят до выбранного worker, а не только принимаются API.
- Верификация использует локальный end-to-end путь с реальным S3/MinIO result object и не ограничивается 202, статусом или чтением исходников.

**Open questions:** *(only for `grooming/` cards)*
- Какой точный inventory supported pairs и browser/animated output pairs входит в эту общую семантику и кто его утверждает? В частности, входят ли `SVG→ICO`, OCR-маршруты, `PDF`-выходы и конкретные browser-маршруты, или они требуют отдельных решений/карточек?
- Нужно ли менять текущее поведение без upscaling (сейчас статический trace указывает на `thumbnail()`), или достаточно зафиксировать его как отдельный deferred вопрос для owner после локальной S3-проверки?
- Какое точное правило округления вычисленной стороны использовать при указании только `width` или только `height`?

**Decisions:**
- 2026-09-01: принято не удалять image `width`/`height` из API. Для поддержанных пар единая целевая политика — bounding box с сохранением aspect ratio и разрешённым upscaling; при одной заданной стороне недостающая сторона вычисляется из исходного aspect ratio. Решение задаёт общую resize-семантику, но не утверждает полный supported-pair inventory.
- 2026-09-01: пользователь одобрил расширение CNV-118 до cross-worker/full-stack scope: общая resize-семантика также применяется к browser/animated rendering outputs. Фактическая browser application остаётся отдельной worker-ответственностью и gated последовательностью CNV-130 → CNV-113 → CNV-114; browser routing не переносится в image worker.
- 2026-09-01: для browser сохраняются `executionKind=browser`, отдельный `conv.browser` stream, browser sandbox, egress policy и независимые quota/retention boundaries; CNV-118 не ослабляет и не дублирует эти security foundations.
- 2026-09-01: исторический live trace от 2026-08-16 и CNV-85-era ошибка не считаются доказательством текущего runtime-поведения. Актуальная проверка должна быть локальным end-to-end тестом с фактическим S3 output object dimensions.
- 2026-09-01: карточка остаётся в `grooming`, поскольку сохраняются открытые вопросы о supported inventory, фактических browser/animated pairs, текущем no-upscale поведении и округлении; перенос в `todo/` преждевременен.

**Контекст:** обнаружено в ходе проверки image conversion 2026-08-16; устаревшая трассировка отмечена 2026-08-24; решение по общей cross-worker resize-семантике и browser gates записано 2026-09-01.

**Status:** grooming
