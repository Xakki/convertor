### Генератор каталога не умеет отдавать executionKind (предпосылка browser-воркера)

**Criticality:** High

**TAGS:**
- backend
- browser
- queue

**Description:**
CNV-88 ввела маршрутизацию по `executionKind` и научила `ConversionRegistry`
читать это поле из строки каталога. Но ГЕНЕРАТОР каталога
(`reduceCapabilities()`) это поле не выдаёт, поэтому ни одна реальная пара не
может получить `executionKind=browser`.

**Problem:**
Пока генератор не умеет эмитить `executionKind`, browser-маршрут существует в
коде, но недостижим из реального каталога. Это осознанная граница CNV-88 —
она сознательно не трогала генератор, — но для владельцев browser-воркера это
блокирующая предпосылка.

**Impact:**
CNV-82, CNV-90, CNV-91 и CNV-113 не смогут довести browser-конвертацию до
конца, не расширив генератор.

**Evidence:**
Установлено при реализации CNV-88 (2026-08-24) ПРОВЕРКОЙ, а не чтением:
- вручную добавлен `executionKind` в реальную строку коммиченного каталога →
  `ConversionPairsCatalogDriftTest` упал на лишнем ключе. То есть просто
  дописать поле в каталог нельзя, дрейф-тест это запретит.
- `categoryForStream('browser')` сейчас бросает исключение:
  `FileCategory::from('browser')` такого значения не имеет. Значит понадобится
  карта вида `matrix_categories`, а не прямое приведение.
- Побочно (CNV-88, проверено тестом): `MKSTREAM` создаёт пустой stream
  `conv.browser` и группу `convertor` на первом же цикле подметания gateway.
  Ошибок и шума в логах нет, но ключи в KeyDB появляются.
- Побочно: держатель статического `WORKER_API_TOKEN` может зарегистрироваться
  как `workerType=browser` и получить очередь handoff (`ws_server.py:351`).
  Тот же гейт, что уже описан на стороне PHP, — не новый риск, но при появлении
  реального browser-воркера это стоит пересмотреть.

**Recommendation:**
Расширять генератор должен владелец browser-возможности вместе с CNV-82/113,
а не отдельной карточкой вслепую: нужно решить, откуда worker-blob сообщает
`executionKind`, и одновременно поправить дрейф-тест и маппинг категории.

**Open questions:**
- Какой слой объявляет `executionKind=browser`: worker capability blob или
  отдельное catalog assignment правило, и кто владеет его schema/mapping?

**Acceptance Criteria:**
- Generator сохраняет declared executionKind worker capability в catalog.
- Browser routing использует `executionKind=browser`, сохраняя image/video как
  category для quota/retention.
- Drift tests покрывают executionKind, mapping category и отсутствие
  недостижимых browser pairs.
