### Маршрутизация browser-задач и входной контракт backend

**Criticality:** High

**TAGS:**
- feature
- backend
- browser
- queue

**Description:**
Backend-специалист вводит отдельный вид исполнения `browser` и маршрутизацию
browser-задач в stream `conv.browser`. Карта возможностей, gateway и метрики
должны создавать и принимать browser-задачу независимо от выходной категории файла.

**Problem:**
Текущая маршрутизация выбирает worker по категориям `image` и `video`. Скриншот и
запись сохраняют эти категории для квот и хранения, поэтому без отдельного признака
исполнения browser-задача недостижима либо ошибочно попадает в существующий worker.

**Impact:**
Смешение browser-задач с image/video worker-ами делает очередь непредсказуемой,
нарушает изоляцию исполнения и исключает наблюдаемую маршрутизацию browser-нагрузки.

**Recommendation:**
Добавить `WorkerType::Browser`, `executionKind=browser`, stream `conv.browser` и
согласованные записи в каталоге возможностей, gateway, метриках и проверках дрейфа.
Сохранить `image` для screenshot и `video` для recording только как категории
результата. Не изменять контейнер, сетевую политику, Chromium runtime и frontend.

**Acceptance Criteria:**
- Backend создаёт browser-задачи с `executionKind=browser` и направляет их только в
  `conv.browser`; обычные image/video-задачи сохраняют существующие streams.
- Catalog, enum, gateway и метрики содержат одинаковый browser contract; тесты дрейфа
  выявляют расхождение между ними.
- Backend-тесты покрывают допустимую browser-маршрутизацию и отказ при неизвестном
  execution kind; целевые проверки backend проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: backend-специалист; граница работы — только routing/catalog/input contract.
- `executionKind=browser`, а не `FileCategory`, является единственным признаком
  маршрутизации; category остаётся источником quota/retention.
- CNV-113 зависит от завершения CNV-88 и использует созданный browser route; CNV-90 и
  CNV-91 зависят от CNV-88 как от общего backend prerequisite.
