### Воркерский проактивный retry/backoff на initial register

**Criticality:** Medium

**TAGS:**
- tech-debt
- registry
- worker
- resilience

**Description:**
`workers/common/ws_client.py::_register()` (строка ~767) при подключении WS один раз запускает
best-effort HTTP POST `/api/v1/worker/register` (`register = asyncio.create_task(self._register())`,
строка ~632). Любое исключение (HTTP-код, DNS, таймаут) молча логируется как WARNING и
проглатывается — ни ретрая, ни backoff на этом же WS-соединении.

**Контекст (сверка с кодом, grooming 2026-07-24):**
Исходная карточка описывала «перманентный orphan capability row» как незакрытый баг. Это уже
**самозалечивается со стороны gateway** в эпике `[[registry-08-worker-observability]]`: gateway
детектит «нет capability-строки для подключённого инстанса» каждые `liveness_push_interval_s` (30с)
в `workers/gateway/liveness.py::_handle_unknown()` и, если держит живой WS к этому инстансу и он вне
кулдауна (`liveness_reregister_cooldown_s` = 300с), шлёт control-frame `{"type":"re-register"}`
(`ws_server.py:213`), который воркер обрабатывает в `_on_reregister()` (`ws_client.py:874`) → повторный
`_register()`. Тот самый инцидент 2026-07-23 теперь залечивается за ≤5 мин **после деплоя registry-08**.

**Остаточная проблема (эта карточка):**
Gateway-side heal реактивен и медленный (до 5 мин из-за 30с-цикла + 300с-кулдауна) и работает только
после выката `registry-08`. Воркерский **проактивный** retry на самом первом `_register()` залечил бы
пропущенную регистрацию за секунды и не зависел бы от gateway-цикла — это defense-in-depth к уже
существующему реактивному механизму.

**Scope (узкий):**
Добавить в `_register()` ограниченный retry с backoff на initial-регистрацию при подключении.
НЕ трогать gateway-side re-register (уже есть), НЕ вводить бесконечную периодическую ре-регистрацию
(её роль выполняет gateway push).

**Acceptance criteria:**
- `_register()` при неуспехе (любое исключение / не-2xx) делает N ретраев с экспоненциальным backoff
  (напр. 3 попытки: ~1с/2с/4с, с jitter), затем сдаётся в WARNING как сейчас (best-effort, non-fatal).
- Ретраи не блокируют `reader`/`pinger` того же соединения (остаётся отдельной asyncio-задачей).
- Если WS-соединение рвётся во время ретраев — задача корректно отменяется (никаких висящих задач /
  двойной регистрации после реконнекта).
- Успешная регистрация с любой попытки логируется как раньше (`worker registered`).
- Идемпотентность сохранена: повторный POST безопасен (upsert по `(worker_type, instance_id)` уже
  идемпотентен — `WorkerCapabilityRepository::upsert`).
- pytest на новую retry-ветку (успех после k неудач; исчерпание попыток → WARNING; отмена при разрыве WS).

**Files:**
- `workers/common/ws_client.py` (`_register()`, ~767; запуск задачи ~632)
- `workers/tests/` — новый тест на retry/backoff.

**Зависит от / связано:**
- `[[registry-08-worker-observability]]` — gateway-side re-register (реактивный heal). Эта карточка —
  проактивное дополнение; можно делать независимо, но ценность полнее после деплоя registry-08.
- Заменяет закрытую `[[worker-registry-fragility]]` в части остаточного resilience-гэпа.

**Status:** done (одобрено пользователем 2026-07-24).

**Execution log (2026-07-24):**
- `_register()` в `workers/common/ws_client.py`: bounded retry — 3 попытки, backoff `base*factor**(n-1)+uniform(0,jitter)` (~1с/2с между попытками, jitter 0.25с), константы `_REGISTER_MAX_ATTEMPTS/_BACKOFF_*`. Успех → прежний `logger.info("worker registered")`, исчерпание → прежний `logger.warning("register failed (non-fatal)")`.
- Отмена при разрыве WS: `except asyncio.CancelledError: raise` перед `except Exception`; задача остаётся в `_teardown()` cancel-list — не блокирует reader/pinger, чистая отмена без двойной регистрации.
- Тесты `workers/tests/test_ws_client.py`: успех после k неудач (счёт POST'ов), исчерпание → WARNING + обработка задачи продолжается, отмена в backoff → CancelledError пробрасывается, второго POST нет.
- Ревью: PASS-WITH-NITS, изменений не требуется.
- **Тесты на сетевой обрыв (обрыв сети):** HTTP-клиент — `httpx.AsyncClient`; ретрай ловит `httpx.ConnectError`/`ConnectTimeout` (сетевые исключения, не HTTP-код). Явные тесты: `test_register_retries_after_network_error_then_succeeds` (2× ConnectError → успех, 3 попытки), `test_register_retries_after_timeout_then_succeeds` (ConnectTimeout → успех, 2 попытки).
- **Полный прогон:** `make phpstan` OK; `make test-php` 494 tests OK; `make test-gateway` 186 passed/1 skip (`pdf2image`, несвязано); `make test-drift` 5 passed; `make test-python` 307 passed (все 6 подсьютов). `make test-e2e`/`test-api-integration` НЕ запускались — пересоздают live-стенд (риск), к retry-изменениям не относятся.
- Коммиты `234191d`, `a03a354`.
