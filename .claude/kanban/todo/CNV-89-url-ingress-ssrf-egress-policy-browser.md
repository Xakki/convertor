### URL-входной контракт browser-конвертации в backend

**Criticality:** High

**TAGS:**
- feature
- backend
- browser
- security
- url

**Description:**
Backend-специалист добавляет тип входа `url` в API browser-конвертации и единый
валидируемый request contract для удалённой страницы. Работа ограничена приёмом,
нормализацией, авторизацией и безопасным представлением URL в задаче.

**Problem:**
Текущий API не различает файл, текст, self-contained HTML и удалённый URL. Без
контракта нельзя однозначно применить плановые ограничения, передать URL proxy и
вернуть пользователю безопасную ошибку до запуска browser worker.

**Impact:**
Несогласованный URL input приведёт к обходу policy, разным правилам API и worker-а,
а также к утечке чувствительных частей URL в историю и сообщения об ошибках.

**Recommendation:**
Расширить `POST /api/v1/convert` discriminator-ом `file|text|html|url`. Для `url`
принимать только абсолютные `http`/`https` URL без credentials и fragment,
канонизировать значение, ограничить размер и длину, применить plan/role gate и
сохранить в browser-задаче минимально необходимое redacted представление. Не
реализовывать proxy, DNS/IP-проверки соединений или Chromium navigation.

**Acceptance Criteria:**
- API однозначно валидирует все четыре input mode и создаёт URL-задачу только для
  browser execution kind; `file:`, `data:`, credentials и fragment отклоняются.
- URL capture доступен только basic/pro; guest/free получают предсказуемый отказ до
  постановки задачи, а self-contained HTML не получает URL privileges.
- Ответы и audit/history не раскрывают credentials, query secrets или полный URL там,
  где достаточно redacted значения; backend-тесты покрывают allow/deny варианты.
- Целевые backend-проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: backend-специалист; граница работы — API и request DTO, не egress runtime.
- Проверка SSRF на каждом DNS hop, redirect и соединении принадлежит CNV-114; этот
  контракт передаёт ему только нормализованный разрешённый URL.
- CNV-114 зависит от завершения CNV-89; CNV-90 и CNV-91 используют URL contract
  CNV-89 и не дублируют его в browser worker-е.
