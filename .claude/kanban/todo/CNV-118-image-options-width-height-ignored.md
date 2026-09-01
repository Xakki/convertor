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
Сохранить принятие и server-side validation options и довести/проверить одну общую resize-семантику для **каждой текущей строки каталога у которой `category`/`execution` равен `image` и есть image profile assignment**; это является migration contract, а не выбором части inventory. В scope входят и document-target image routes (например, `bmp→pdf`, `jpeg/jpg/png/tiff→docx/md/pdf/txt`), если такая строка имеет image profile assignment. Владелец семантики — CNV-118; application-specific реализация принадлежит соответствующему worker. Для browser inventory утверждён staged scope: local animated `SVG→GIF`; self-contained HTML→`PNG`/`JPEG` screenshots; permitted URL→`PNG`/`JPEG` screenshots. URL→`WebM` recording deferred to CNV-91, which must define a separate recording dimension/resize contract. Для включённых пар целевая семантика: bounding box с сохранением aspect ratio; upscaling разрешён; при указании только `width` или только `height` недостающая сторона вычисляется из исходного aspect ratio ближайшим целым числом, но не менее 1 px. Ограничение bounding box применяется после округления, поэтому округлённые dimensions не превышают заданные maxima. Image-worker и browser worker должны выдавать одинаковые dimensions для одинаковых исходных dimensions, normalized options и supported-pair semantics.

Browser execution не является синонимом output category: browser-задачи маршрутизируются отдельным `executionKind=browser` и собственным `conv.browser` stream, а image/video остаются категориями для quota/retention. CNV-118 не переносит browser routing в image worker, не объединяет sandbox, network, quota или retention policy и не разрешает выполнение до browser foundations.

**Dependencies:**
- Image-worker application может проверяться после имеющихся image routing/message prerequisites.
- Browser/animated application for the staged inventory starts only after CNV-130, then CNV-113 (isolated browser sandbox), then CNV-114 (policy-enforcing egress); these cards retain their own executionKind, stream, sandbox, quota and retention boundaries.
- URL screenshots additionally require URL proxy/redirect/DNS/private-IP enforcement from CNV-114. HTML screenshots must remain self-contained with no external network or filesystem subresources.
- CNV-118 does not replace CNV-130/113/114 or create a bypass browser path; browser worker remains unavailable until the ordered gates pass. URL→WebM dimensions remain owned by CNV-91's recording contract.

**Acceptance Criteria:**
- Migration tests охватывают каждую текущую строку каталога у которой `category`/`execution` равен `image` и есть image profile assignment, включая raster и векторные inputs и все применимые image-worker output routes, в том числе document targets (`bmp→pdf`, `jpeg/jpg/png/tiff→docx/md/pdf/txt`); отсутствие image category, image profile assignment, поддержки маршрута или самой строки в каталоге означает исключение, а не молчаливое расширение scope.
- Для каждой включённой текущей image-пары локальный end-to-end test создаёт job через API/очередь, дожидается `completed`, скачивает результат из S3 и проверяет фактические `width`/`height` объекта; тесты до миграции фиксируют совместимость контракта normalized options и worker routing.
- При заданных `width` и `height` результат вписывается в bounding box с сохранением aspect ratio; при одной заданной стороне вычисляется вторая сторона nearest-integer с minimum 1 px; upscaling разрешён, а post-rounding dimensions не превышают maxima.
- Compatibility/migration tests проверяют, что обе заданные стороны, каждая одиночная сторона и отсутствие resize options доходят до выбранного worker; отсутствие options сохраняет текущий размер, а заданный bounding box применяется и для исходника меньше целевого размера. API-only 202, статус job или чтение исходников не считаются проверкой результата.
- OCR проверяется как flag-вариант соответствующей текущей image-пары, а не как новый pair; document-target routes, назначенные каталогу с `category`/`execution=image` и image profile assignment, входят в migration suite, а действительно non-image-category, unassigned или unsupported routes исключаются. `SVG→ICO` и иные специальные routes сохраняют собственные policies и проверяются в migration suite только при наличии текущего image category и image profile assignment; без такого назначения остаются явным исключением до отдельного решения.
- Browser acceptance covers exactly local animated `SVG→GIF`, self-contained HTML→`PNG`/`JPEG`, and permitted URL→`PNG`/`JPEG`; URL→`WebM` is explicitly excluded until CNV-91 defines the separate recording dimension/resize contract.
- Each staged browser pair preserves request-scoped persisted animated routing distinct from static `SVG→GIF`, uses `executionKind=browser` and `conv.browser`, has no image-worker fallback, and preserves browser sandbox/egress isolation and independent quota/retention semantics. HTML has no external network/fs subresources; URL access enforces proxy/redirect/DNS/private-IP policy.
- Browser acceptance executes only after CNV-130 → CNV-113 → CNV-114. CNV-118 does not weaken these gates or choose any alternate order.
- Очередной контракт и фактическое сообщение сохраняют normalized `options`; cross-worker fixtures должны давать одинаковые dimensions для одинакового normalized input, а локальный end-to-end путь использует реальный S3/MinIO result object.

**Decisions:**
- 2026-09-01: пользователь одобрил универсальную migration policy для всех текущих catalog rows, у которых `category`/`execution` равен `image` и есть image profile assignment, включая document-target image routes: bounding-box + aspect-ratio-preserving resize с разрешённым upscaling применяется ко всему этому image inventory. Действительно non-image-category, unassigned или unsupported routes не входят; будущие browser pairs подключаются только после security prerequisites и отдельного решения по exact inventory.
- 2026-09-01: принято не удалять image `width`/`height` из API. Для поддержанных пар единая целевая политика — bounding box с сохранением aspect ratio и разрешённым upscaling; при одной заданной стороне недостающая сторона вычисляется из исходного aspect ratio. Решение задаёт общую resize-семантику, но не утверждает полный supported-pair inventory.
- 2026-09-01: пользователь одобрил расширение CNV-118 до cross-worker/full-stack scope: общая resize-семантика также применяется к browser/animated rendering outputs. Фактическая browser application остаётся отдельной worker-ответственностью и gated последовательностью CNV-130 → CNV-113 → CNV-114; browser routing не переносится в image worker.
- 2026-09-01: для browser сохраняются `executionKind=browser`, отдельный `conv.browser` stream, browser sandbox, egress policy и независимые quota/retention boundaries; CNV-118 не ослабляет и не дублирует эти security foundations.
- 2026-09-01: пользователь одобрил для image и browser implementations nearest-integer rounding вычисленной стороны с minimum 1 px; bounding-box limit применяется после округления, поэтому dimensions не превышают заданные maxima. Одинаковые normalized inputs и fixtures должны давать одинаковые dimensions во всех workers.
- 2026-09-01: исторический live trace от 2026-08-16 и CNV-85-era ошибка не считаются доказательством текущего runtime-поведения. Актуальная проверка должна быть локальным end-to-end тестом с фактическим S3 output object dimensions.
- 2026-09-01: карточка остаётся в `grooming`, поскольку exact supported/browser inventory и owner approval для browser/animated pairs ещё открыты; no-upscale вопрос закрыт универсальной migration policy с разрешённым upscaling. Перенос в `todo/` преждевременен.
- 2026-09-02: пользователь утвердил staged browser inventory: local animated `SVG→GIF`; self-contained HTML→`PNG`/`JPEG` screenshots; permitted URL→`PNG`/`JPEG` screenshots. Request-scoped persisted animated routing остаётся отдельным от static `SVG→GIF`; browser routes use `executionKind=browser` and `conv.browser`. URL→`WebM` recording отложен до CNV-91, который должен определить отдельный recording dimension/resize contract.

**Execution Log:**
- 2026-09-01: записана одобренная универсальная policy для всех текущих catalog rows, у которых `category`/`execution` равен `image` и есть image profile assignment, включая document-target image routes и migration/compatibility tests; действительно non-image-category, unassigned или unsupported routes исключены. `SVG→ICO` и OCR special policies, exact browser inventory и browser security gates сохранены; карточка остаётся в `grooming`.
- 2026-09-01: targeted `kanban-lint.sh` — 1 card checked, 0 errors, 0 warnings; full-board `kanban-lint.sh` — 71 cards checked, 0 errors, 0 warnings; `git diff --check` passed. Source/runtime/config/deploy не изменялись.
- 2026-09-02: final browser inventory decision recorded; all grooming questions resolved. Targeted Kanban lint — 1 card checked, 0 errors, 0 warnings; full-board Kanban lint — 71 cards checked, 0 errors, 0 warnings; `git diff --check` passed. Card moved grooming→todo. Source/runtime/config/deploy не изменялись.

**Контекст:** обнаружено в ходе проверки image conversion 2026-08-16; устаревшая трассировка отмечена 2026-08-24; решение по общей cross-worker resize-семантике и browser gates записано 2026-09-01.

**Status:** todo
