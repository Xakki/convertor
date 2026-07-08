### S1-04 — Маршрутизация result/fail + перенос владения ack в gateway

**Критичность:** High

**TAGS:**
- transport
- gateway
- backend

**Описание:**
Реализовать гибрид результата по размеру + перенос владения `XACK` из PHP в gateway (§5/§7 spec). Порог **256 KB** (config `WS_RESULT_INLINE_MAX`, дефолт `262144`):
- **Малый (≤256 KB):** воркер → `{type:"result", jobId, inline:<data>}` по WS. Gateway ретранслирует inline в Symfony **внутренний relay** `POST /api/v1/internal/worker/result` (JSON, токен `GATEWAY_INTERNAL_TOKEN`) → Symfony сохраняет S3+БД через `ConversionResultPersister`. Затем gateway `XACK`.
- **Большой:** воркер сам делает `POST /jobs/{id}/result` (multipart, файрвол `worker_api`, payload НЕ идёт через gateway), затем шлёт по WS `{type:"result", jobId, resultKey}`. Gateway `XACK` **на доверии** к WS-сообщению.
- **Fail:** воркер → `{type:"fail", jobId, error}`. Gateway вызывает internal relay `POST /api/v1/internal/worker/fail` (пометить failed + вернуть квоту), затем `XACK`. Публичного `POST /jobs/{id}/fail` **больше нет**.

**Инвариант (§5):** `XACK` делает ТОЛЬКО gateway и ТОЛЬКО после подтверждённого persist. Inline = gateway *знает* (успешный ответ relay); large/fail = ack **на доверии** к WS. Обе безопасны при at-least-once + идемпотентности по `conversionId` (детерминированный ключ `results/{Y}/{m-d}/{id}.{ext}`). При ack gateway также `DEL worker:job:{jobId}`, освобождает кредит и делает следующий `XREADGROUP`.

**Заявить громко (§5):** `WorkerController::result()` (~L151) и `fail()` (~L184) сейчас каждый вызывают `$this->gateway->ack(...)` — **удалить оба** (`fail()` вообще удаляется как публичный эндпоинт). Никто не имеет права заново добавлять `XACK` в Symfony. `WorkerStreamGateway` ужимается до `getJobMeta` (путь чтения Stream уходит в gateway).

**Файлы:**
- Создать: `app-symfony/src/Controller/Api/InternalWorkerController.php` (`POST /api/v1/internal/worker/{result,fail}`).
- Создать: `app-symfony/src/Security/GatewayInternalAuthenticator.php` (проверка `GATEWAY_INTERNAL_TOKEN`).
- Изменить: `app-symfony/config/packages/security.yaml` (отдельный firewall для `/api/v1/internal/worker`).
- Изменить: `app-symfony/src/Controller/Api/WorkerController.php` (удалить `ack()` из `result()`; удалить публичный `fail()`; `result()` — только large-multipart, без XACK).
- Изменить: `app-symfony/src/Service/Worker/WorkerStreamGateway.php` (ужать до `getJobMeta`; убрать `ack()`/`claim()`/чтение Stream).
- Изменить: `workers/gateway/ws_server.py` (роутинг `result`/`fail`, relay-клиент, XACK после persist, DEL меты, освобождение кредита).
- Изменить: `workers/gateway/config.py` (`WS_RESULT_INLINE_MAX`, `GATEWAY_INTERNAL_TOKEN`, base URL Symfony).

**Критерии приёмки:**
- inline (≤256 KB) → relay `POST /internal/worker/result` → persist success → `XACK` (ассерт: ack ТОЛЬКО после успешного ответа persist-мока).
- large (`resultKey`) → gateway `XACK` на доверии (без relay payload'а через gateway).
- fail → relay `POST /internal/worker/fail` (refund квоты) → `XACK`.
- `GatewayInternalAuthenticator`: без/с невалидным `GATEWAY_INTERNAL_TOKEN` → 401/403; публичный `worker_api` токен НЕ проходит на internal-эндпоинты.
- Grep: в Symfony НЕТ вызовов `->ack(` из `WorkerController`; публичный `POST /jobs/{id}/fail` удалён (роут отсутствует).
- После ack: `DEL worker:job:{jobId}`, кредит освобождён, следующий `XREADGROUP`.
- `make phpstan` / `make cs` зелёные; `make test-gateway` зелёный.

**Перенос из ревью s1-03 (учесть здесь):**
- **[ack-идемпотентность]** Consumer стабилен (=`workerId`) и PEL возобновляется через `read_pending` (id `0`). Если воркер переподключается при ещё полу-живом старом сокете, один `jobId` может быть протолкнут дважды (оба обработчика делят PEL). При реализации ack убедиться в **идемпотентности по `conversionId`** (детерминированный ключ результата) — двойной ack/двойной persist безопасен, повторный `job` воркер дедупит по `jobId`. Явно проверить в тестах.
- **[nit-test #2 из s1-03]** Порядок resume проверен только при slots=1 (через исчерпание кредита). Добавить тест на slots≥2: возобновлённый (`read_pending`) job проталкивается ПЕРЕД новым (`>`) — залочить истинный порядок, а не просто «read_new не вызван».
- **[nit-robustness #3 из s1-03]** Обернуть dispatch-цикл (`reclaim_stale`/`read_new`) в try/log: транзиентный `RedisError` сейчас всплывает из `handle()` и роняет соединение без адресного лога. Для S1 не критично (воркер переподключается), но диагностику улучшит.
- **[conv:status]** Живой статус, пишемый gateway (правило CLAUDE.md), в s1-03 НЕ пишется (корректно вне AC s1-03). Формально он в `[[s1-07-progress-conv-status]]`, но проверить, что путь dispatch/result его не ломает и точка записи согласована.

**Зависит от:** `[[s1-03-ws-server-dispatch]]`

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready (2 зоны: PHP `17b1ac8` + gateway `dc92798`; ревью APPROVE WITH NITS — XACK только gateway после persist, firewall-разделение с тестом кросс-реджекта, контракты сошлись, нет двойного refund'а, нет дедлока; nit credit-leak/no-wedge + ack-guard закрыты `f153198`; test-php 77, test-gateway 20, phpstan/cs/docker-check зелёные; carry-forwards → s1-06 cross-midnight, cleanup conv.result → s1-10). Ждёт финального ready→done пользователя.
