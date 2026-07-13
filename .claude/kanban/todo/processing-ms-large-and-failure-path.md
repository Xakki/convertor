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

**Status:** grooming.
