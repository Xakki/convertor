### `processingMs` не пишется на large/multipart и failure путях

**Criticality:** Low/Medium

**TAGS:**
- enhancement
- backend
- workers
- observability

**Description:**
Inline-путь результата теперь измеряет и передаёт `processingMs` (время
обработки), но два других пути его по-прежнему теряют:

- **LARGE / multipart результат.** Воркер `_upload_large` не шлёт тайминг в
  multipart-POST, а `app-symfony/src/Controller/Api/WorkerController.php:162-169`
  хардкодит `processingMs => null`. Нужно, чтобы воркер клал `processing_ms` в
  multipart-POST, а PHP его читал.
- **FAILURE путь.** `ResultSignal.failed(...)` не несёт тайминга, а
  `InternalWorkerController::fail` шлёт `processingMs => null` — упавшие
  конверсии никогда не фиксируют затраченное время.

**Impact:**
Поле «время обработки» заполняется только для мелких успешных inline-результатов.
Крупные (multipart) и упавшие конверсии остаются без метрики — неполная картина
по времени обработки, метрика непригодна для аналитики/алертинга по этим кейсам.

**Recommendation:**
Протянуть elapsed ms через оба пути для полного покрытия поля «время обработки»:
- воркер: слать `processing_ms` в multipart-POST большого результата и в
  `ResultSignal.failed(...)`;
- PHP: читать его в `WorkerController` (large-путь) и в
  `InternalWorkerController::fail` (failure-путь) вместо хардкода `null`.

**Итог реализации (2026-07-17, commit `f048480`):** wire-field = `processingMs`
(camelCase, как весь существующий контракт; `processing_ms` в карточке — Python-attr).
- LARGE-путь заведён end-to-end (`ws_client._upload_large` шлёт `processingMs` в
  multipart, `WorkerController::result` читает) + тест `testResultUploadsAndPersistsProcessingMs`.
- FAILURE-путь: проводка полная (`ResultSignal.failed(processing_ms=)` → WS fail
  frame → `RelayClient.post_fail` → `InternalWorkerController::fail` читает) + тест
  `testFailPersistsProcessingMs`. НО dormant: `ws_server._handle_fail` сегодня не
  зовёт `post_fail` (permanent→DLQ, retryable→pending), `conv.dead` без консьюмера.
  Зависимость передана в [[conv-dead-no-consumer]] (DLQ-payload расширить `processingMs`).
- Проверка: phpstan [OK], cs-check green, PHP 245 tests OK, Python data 98/ai 111/gateway 104.

**Status:** done.
