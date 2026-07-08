### Backend — удалить остаточное мёртвое поле `subType` (message + gateway + fixtures)

**Критичность:** Low

**TAGS:**
- tech-debt
- backend

**Описание:**
`[[php-ai-virtual-key-submit-resolution]]` удалил `resolveSubType()` и перевёл виртуальные
AI-ключи на плоские пары, но **поле `subType` осталось вестигиальным**: всегда `null`.
Остатки:
- `app-symfony/src/Message/ConversionMessage.php:27` — `public readonly ?string $subType = null`.
- `app-symfony/src/Service/Conversion/ConversionManager.php:178` — `subType: null` (литерал).
- Gateway `workers/.../ws_server.py` (~L346-350) — passthrough `subType` если non-null (сейчас inert,
  т.к. всегда null).
- Fixtures/тесты с `"subType": null`: `tests/Fixtures/messenger_envelope.golden.json`,
  `ConversionManagerOcrTest`, `CleanRedisTransportTest`, `CleanRedisTransportKeyDbTest`.

Родительская карточка (decisions) планировала полностью удалить `subType` («MERGE backend-subtype-cleanup»),
но имплементация остановилась на «всегда null» — функционально виртуальный механизм мёртв, поле —
косметический мусор. Безопасно (ревью подтвердило gateway-passthrough inert).

**Скоуп:**
- Убрать параметр `subType` из `ConversionMessage` (конструктор + любые сериализации/десериализации).
- Убрать `subType: null` из `ConversionManager::dispatch()`.
- Убрать passthrough в gateway `ws_server.py`.
- Регенерить `messenger_envelope.golden.json`; поправить тесты, ассертящие `subType: null`.

**Decisions (груминг 2026-07-08):**
- **Убрать чисто, без грейс-периода** [USER DECISION 2026-07-08]. S1 ещё не задеплоен → в проде нет
  persisted KeyDB-фреймов со старым полем, обратная совместимость не нужна. Полностью удалить: поле в
  `ConversionMessage`, литерал `subType: null` в `ConversionManager::dispatch()`, gateway passthrough в
  `ws_server.py`; регенерить `messenger_envelope.golden.json`; убрать `subType` из тестов
  (`ConversionManagerOcrTest`, `CleanRedisTransportTest`, `CleanRedisTransportKeyDbTest`).

**Контекст:** найдено тимлидом при закрытии `[[php-ai-virtual-key-submit-resolution]]` (2026-07-08).

**Status:** todo — груминг завершён, scope ясен.

---

## Execution Log (2026-07-08, Agent: subtype-removal)

Поле `subType` удалено ЧИСТО, без грейс-периода (S1 не задеплоен → нет persisted-фреймов).

**Изменённые файлы:**
- `app-symfony/src/Message/ConversionMessage.php` — убран конструктор-параметр `?string $subType = null`.
- `app-symfony/src/Service/Conversion/ConversionManager.php` — убран `subType: null` в `dispatch()`.
- `workers/gateway/ws_server.py` — убран passthrough-блок `if job.get("subType")…` (+ правка коммента).
- `app-symfony/tests/Fixtures/messenger_envelope.golden.json` — регенерён (ключ `subType` исчез; byte-for-byte).
- PHP-тесты: `CleanRedisTransportTest`, `CleanRedisTransportKeyDbTest` (inline msg), `ConversionManagerOcrTest` (4 assert'а).
- Python-тесты (payload-дикты + `_EXPECTED_JOB`): `test_envelope_golden`, `test_gateway_ws`, `test_workers_e2e`,
  `test_stream_consumer`, `test_unsupported_formats`, `test_ws_transport_integration`, `test_data_worker`,
  `test_ffmpeg_worker`/`_integration`, `test_image_worker_ocr`/`_stream`/`_ocr_integration`,
  `test_libreoffice_worker`/`_integration`, `test_ai_worker` (2× `subType:"ocr"` — flag-agnostic-пруф, `taskType`/`ocr` оставлены).
- Комменты-упоминания: `workers/ai/convert.py`, `workers/image/worker.py`, `workers/libreoffice/worker.py`.
- Доп. (пинится из DTO-докблока): `docs/queue-contract.md` — убран `subType` из JSON-примера + таблицы полей.

**Не тронуто (историческое/справочное, вне required-grep):** `docs/queue-redesign-design.md`,
`docs/superpowers/specs/2026-07-02-ws-worker-transport-design.md` (дизайн-снапшоты), строка-пример в `CLAUDE.md` — на ревью тимлиду.

**Верификация (все зелёные):**
- `grep -rn subType app-symfony workers` (excl vendor) → **ZERO HITS**.
- `make phpstan` → OK, no errors (40 files).
- `make cs-check` → 0 of 56 files to fix.
- `make test-php` → 101 passed (1 несвязанный PHPUnit notice).
- `make test-drift` → 2 passed.
- `make test-gateway` → 100 passed.
- `make test-python` → все слайсы (data/ffmpeg/image/libreoffice/metrics/ai) passed.
- `make docker-check` → exit 0.
