### Удалить seed-строки и снять спец-обработку __seed__

**Criticality:** High

**TAGS:**
- tech-debt

**Description:**
Часть эпика CNV-71. Выполнять ТОЛЬКО ПОСЛЕ CNV-71-01, CNV-71-02, CNV-71-03 — иначе при пустой `worker_capabilities` сайт останется с пустым списком форматов и 400 на любую конвертацию.

**Problem:**
Seed-строки `instance_id='__seed__'` (6 строк) сегодня — единственная гарантия «матрица не опустеет» и требуют спец-обработки в 6 местах кода.

**Impact:**
Пока seed-строки существуют, каталог форматов и код обрастают спец-обработкой в 6 местах — постоянный источник рассинхрона и дублирования логики.

**Recommendation:**
Удалить 6 строк `instance_id='__seed__'` (миграцией, а не админ-кнопкой — кнопка их сознательно исключает) и вычистить спец-обработку в перечисленных 6 местах, оставив только то, что осмысленно без seed (например, блокировку регистрации под зарезервированным instanceId можно сохранить):
- участие в матрице (`ConversionRegistry`)
- исключение из GC (`WorkerCapabilityGcService.php:52`)
- исключение из `markSilentDisconnected()` (`WorkerCapabilityRepository.php:268-289`)
- исключение из admin bulk-delete `deleteStaleByStatus()` (`WorkerCapabilityRepository.php:317-335`)
- бейдж в админке (`WorkerStatsProvider.php:51,108,252,362`)
- блокировка регистрации под этим instanceId (`WorkerController.php:50,68-96`)

Проверить, что при полностью пустой `worker_capabilities` сайт по-прежнему показывает все форматы, а создание конвертации даёт понятный отказ, а не 400 «формат не поддерживается».

**Acceptance Criteria:**
- Миграция удаляет 6 строк `instance_id='__seed__'`
- Спец-обработка `__seed__` вычищена во всех 6 перечисленных местах (кроме осмысленных исключений)
- При пустой `worker_capabilities` `/api/v1/formats` и SEO-страницы по-прежнему отдают полный каталог форматов
- При пустой `worker_capabilities` создание конвертации даёт понятный отказ («конвертация временно недоступна»), а не 400 «формат не поддерживается»
- Tests/QA green: `make phpstan`, `make cs-check`, тесты — см. CLAUDE.md

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Порядок выполнения строго после CNV-71-01..03 — подтверждено пользователем 2026-08-04
</content>
