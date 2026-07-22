### Seed-миграция: матрица в БД, чтобы она никогда не была пустой

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Второй шаг Phase 2 эпика `[[registry-00-self-registration]]`. Готовит почву для удаления
хардкода (`[[registry-05-drop-hardcode]]`): Doctrine-миграция заливает текущий снапшот
`ConversionRegistry::workerCapabilities()` (`app-symfony/src/Service/Conversion/ConversionRegistry.php:374`)
в таблицу `worker_capabilities`, по одной строке на worker-type — так БД никогда не оказывается
пустой даже до того, как хоть один воркер успел зарегистрироваться (D1: fallback-хардкод
убирается, вместо него — seed).

**Problem:**
После удаления `workerCapabilities()`-fallback (шаг [[registry-05-drop-hardcode]]) пустая/только
что поднятая БД оставила бы `buildRoutingPairs()` без единой пары — `/formats` и submit
отваливаются до первого успешного register любого воркера (окно простоя при деплое/рестарте
БД).

**Impact:**
Без seed — регрессия доступности API на старте системы; с seed — деградация плавная (снапшот
устаревает до первого live register, но не исчезает).

**Recommendation:**
- Новая Doctrine-миграция, читающая текущий хардкод-снапшот (по состоянию на момент написания
  миграции — статичные данные внутри самой миграции, не runtime-чтение кода) и заливающая по
  одной строке `WorkerCapability` на worker-type с зарезервированным seed-значением `instance_id`
  (напр. `"__seed__"`), используя схему `(worker_type, instance_id)` из `[[registry-02-schema-multi-instance]]`.
  Идемпотентность: повторный прогон миграции (или повторное применение на уже засеянной БД) не
  дублирует строки — INSERT IGNORE / проверка существования по составному ключу перед вставкой.
- Явно НЕ включать в seed Stage-7 «coming-soon» пары (xls/xlsx/ods/csv→pdf, ppt/pptx/odp→pdf,
  dwg/dxf→pdf/svg/png, pdf→jpg) — они существуют только в хардкод-fallback и по решению
  [USER DECISION 2026-07-01, зафиксировано в эпике] должны исчезнуть, а не мигрировать в БД.
  `workers/libreoffice/worker.py:86-98` их сознательно не декларирует — seed должен быть
  согласован с реальными Python CAPABILITIES, а не с полным хардкод-списком PHP.
- Живая регистрация воркера (`POST /worker/register`) ПЕРЕЗАПИСЫВАЕТ seed-строку того же
  `worker_type` (при первом реальном register `instance_id` заменяет/дополняет `__seed__`-строку
  реального инстанса) — это и есть механизм устаревания снапшота: seed живёт только до первого
  живого register своего типа, дальше матрица отражает реальность. Зафиксировать явно, что
  seed-строка НЕ удаляется автоматически при появлении реального инстанса другого `instance_id`
  того же типа (несколько инстансов сосуществуют по схеме registry-02) — вопрос вывода
  устаревшего `__seed__` из выдачи решается TTL-механизмом [[registry-06-liveness-push]], не здесь.

**Acceptance Criteria:**
- Миграция применяется на чистой БД без ошибок; таблица `worker_capabilities` после миграции
  содержит по строке на каждый worker-type из текущего хардкода, БЕЗ Stage-7 пар.
- Повторное применение (или запуск на уже засеянной БД) не создаёт дублей.
- Реальный register того же worker-type успешно апсертит поверх seed-строки (не конфликтует
  по UNIQUE-схеме из `[[registry-02-schema-multi-instance]]`).
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- Груминг 2026-07-22: seed заменяет отклонённый вариант «эмулировать хардкод в рантайме» (D1) —
  простой снапшот в миграции, устаревающий через живой register, а не вечнозелёный код-путь.

**Зависит от:** `[[registry-02-schema-multi-instance]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** todo
