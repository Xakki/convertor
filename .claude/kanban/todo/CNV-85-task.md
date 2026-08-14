### Единый каталог и контракт настроек конвертации

**Criticality:** High

**TAGS:**
- tech-debt
- architecture
- api
- frontend
- conversion-options
- validation

**Description:**
До реализации CNV-76, CNV-77 и CNV-78 стандартизировать общий контракт
пользовательских настроек конвертации для UI, API-клиентов, серверной валидации и
job payload. По итогам создать долговечный русский документ с правилами работы и
добавления новых настроек.

**Problem:**
Сейчас settings существуют только для image: UI/localStorage жёстко знают image
formats, API содержит image-only validation, а manager запрещает options другим
категориям. `GET /api/v1/formats` передаёт только матрицу пар, не settings, controls,
границы, defaults или access policy. Следующие задачи иначе продублируют UI-логику,
whitelist и проверки тарифов.

**Impact:**
Ответ API будет раздуваться повторением одинаковых параметров для каждой пары,
frontend и API-клиенты получат несогласованные controls, а guest/plan restrictions
будут реализованы неодинаково или обходиться.

**Recommendation:**
Расширить единый `GET /api/v1/formats` versioned settings catalogue: каждая
conversion pair ссылается на профиль либо не имеет settings, definitions профилей
дедуплицируются и передаются один раз. UI отображает общий набор controls, но API
остаётся авторитетом и повторно валидирует pair, тип, границы, enum и право доступа.
Создать `docs/conversion-settings-contract.md`, обновить `docs/queue-contract.md`
и связать правила с CNV-76/77/78/CNV-82.

**Acceptance Criteria:**
- Один ответ `GET /api/v1/formats` содержит матрицу пар и deduplicated settings
  profiles; пары не повторяют полные определения одинаковых controls.
- Общая грамматика UI поддерживает `range`, `select`, ограниченные `number`/`text`,
  `boolean` и `color`; для каждого поля API задаёт тип, default, границы/step,
  enum/pattern и доступное человеку название. UI ограничивает ввод, сервер всегда
  повторно валидирует.
- API отклоняет неизвестные keys, неверные types, значения вне границ, запрещённые
  enum, settings без профиля и значения, недоступные пользователю.
- API-клиент получает доступные settings и policy hints для guest/free/basic/pro;
  фактическое право повторно проверяется на `POST /convert`.
- Для range-настроек UI сразу отображает effective default из профиля, а job и
  история конверсии сохраняют фактически применённые нормализованные значения;
  пользователь может увидеть их после выполнения.
- Сохраняется image-семантика: width/height 1–10000, quality 1–100 для JPEG/WebP,
  background `#RRGGBB` для JPEG; worker получает только нормализованные options.
- Терминология DTO/message/docs нейтральна к категории; queue contract фиксирует
  общий invariant `options`, не объявляя image-поля универсальными.
- `docs/conversion-settings-contract.md` на русском фиксирует vocabulary, формат
  каталога, control grammar, validation/access invariants, versioning, ownership
  и checklist добавления поля; `docs/queue-contract.md`, CLAUDE.md и связанные
  карточки содержат актуальные ссылки.
- Добавлены contract tests API/OpenAPI, frontend rendering profile, серверной
  validation и job serialization; существующие image tests зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Карточка блокирует CNV-76, CNV-77 и CNV-78; после неё те реализуют domain schema
  и применение worker-ом, а не общую UI/API грамматику.
- CNV-82 координируется с каталогом после выбора renderer'а, но не блокирует
  создание базового контракта.
- Никаких raw FFmpeg, renderer или serializer arguments: только server-side
  whitelisted поля.
- 2026-08-14: quality range — 1–100. Значение `0` означает «использовать
  default профиля», но UI сразу заменяет его на показываемое пользователю effective
  default; в job и истории сохраняется фактически применённое нормализованное
  значение, а не неоднозначный `0`.
- 2026-08-14: `GET /api/v1/formats` персонализирован по cookie/JWT и возвращает
  уже отфильтрованные settings для текущего guest/user/плана; `POST /convert`
  всё равно повторно проверяет право и значения.
- 2026-08-14: guest получает только default без пользовательских settings; free —
  безопасные базовые settings; basic/pro — все не-AI settings. Расширенные limits
  задаются декларативными profiles. Лимит длительности media не заявляется, пока не
  появится отдельная server-side inspection/limit реализация.
- 2026-08-14: profile назначается точной паре `from→to`; API отдаёт стабильные
  field keys, а UI локализует labels и hints.
