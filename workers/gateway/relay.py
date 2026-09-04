"""Internal-relay клиент gateway → Symfony (§5, s1-04).

Gateway НЕ пишет в S3/БД сам: inline-результат и fail он ретранслирует во
внутренний Symfony-эндпоинт `/api/v1/internal/worker/{result,fail}` (auth —
bearer `GATEWAY_INTERNAL_TOKEN`), а Symfony персистит через
`ConversionResultPersister`. `XACK` gateway делает ТОЛЬКО после успешного (2xx)
ответа relay для inline/fail; сетевые ошибки / не-2xx → НЕ ack (запись остаётся
pending, воркер/reclaim переотправят). Большой результат через relay НЕ идёт —
воркер сам POST'ит файл, gateway ack'ает на доверии (см. ws_server).
"""

from __future__ import annotations

import logging

import httpx

logger = logging.getLogger(__name__)

RESULT_PATH = "/api/v1/internal/worker/result"
FAIL_PATH = "/api/v1/internal/worker/fail"
# DLQ-финализация (conv-dead-no-consumer): gateway → Symfony по conversionId
# напрямую (без jobId — DLQ-записи его не несут), см. dlq_consumer.py.
DLQ_FAIL_PATH = "/api/v1/internal/worker/dlq-fail"
# Liveness-батч (registry-06): агрегированные ping'и по (workerType, instanceId),
# см. workers/gateway/liveness.py. UPDATE-ONLY на стороне PHP — сюда НЕ идёт
# ack/кредит-логика result/fail, короче таймаут (телеметрия, не персист задачи).
LIVENESS_PATH = "/api/v1/internal/worker/liveness"
HOST_TELEMETRY_PATH = "/api/v1/internal/host-telemetry"
# Accepted-but-never-claimed expiry (CNV-71-03): expiry-sweep (см.
# workers/gateway/expiry.py) → Symfony по conversionId (та же форма вызова,
# что post_dlq_fail — expired-запись тоже не несёт jobId к моменту detection).
EXPIRE_PATH = "/api/v1/internal/worker/expire"
# Таймаут одного relay-запроса. Persist (S3+БД) обычно быстрый; при зависании
# лучше не ack'ать и дать записи остаться pending, чем висеть на сокете.
RELAY_TIMEOUT_S = 30.0
# Короче RELAY_TIMEOUT_S: liveness — чистая телеметрия на своём отдельном
# периодическом тике (workers/gateway/liveness.py::run_liveness_push_loop), не
# на пути ack/кредита job'ов — зависший PHP не должен держать HTTP-соединение
# дольше, чем разумно для маленького JSON-батча.
LIVENESS_TIMEOUT_S = 10.0


class RelayClient:
    """Асинхронный HTTP-клиент к internal-relay Symfony (httpx).

    `client` можно инжектить (в тестах — `httpx.AsyncClient(transport=MockTransport)`);
    если не передан — создаётся собственный и закрывается в `aclose()`.
    """

    def __init__(
        self, base_url: str, token: str, client: httpx.AsyncClient | None = None
    ) -> None:
        self._base = base_url.rstrip("/")
        self._token = token
        self._client = client
        self._owns_client = client is None

    def _get_client(self) -> httpx.AsyncClient:
        if self._client is None:
            self._client = httpx.AsyncClient(timeout=RELAY_TIMEOUT_S)
        return self._client

    def _headers(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self._token}"}

    async def post_result(
        self,
        job_id: str,
        data_b64: str,
        mime: str | None,
        processing_ms: int | None,
    ) -> tuple[bool, int | None]:
        """inline-результат → Symfony. `data` = base64 ДОСЛОВНО из WS-поля `inline`.

        Форма тела фиксирована (контракт с PHP-зоной): все четыре ключа всегда
        присутствуют, `mime`/`processingMs` = null при отсутствии — предсказуемый
        shape для парсинга на стороне Symfony.

        Возвращает `(ok, status)`: `ok=True` ⇢ 2xx; иначе `ok=False`, `status` —
        HTTP-код ответа или `None` при сетевой ошибке (вызывающий различает 4xx vs
        5xx/сеть для DLQ vs capped retry на result-path).
        """
        payload = {
            "jobId": job_id,
            "data": data_b64,
            "mime": mime,
            "processingMs": processing_ms,
        }
        return await self._post_with_status(RESULT_PATH, payload, job_id)

    async def post_fail(
        self, job_id: str, error: str, processing_ms: int | None = None
    ) -> bool:
        """fail → Symfony (пометить failed + вернуть квоту). Публичного fail нет.

        `processingMs` = null при отсутствии — тот же null-shape контракт, что и
        `post_result` (mime/processingMs)."""
        payload = {"jobId": job_id, "error": error, "processingMs": processing_ms}
        return await self._post(FAIL_PATH, payload, job_id)

    async def post_dlq_fail(
        self,
        conversion_id: int,
        reason: str,
        processing_ms: int | None = None,
        attempt: int | None = None,
    ) -> bool:
        """DLQ-финализация → Symfony (conv-dead-no-consumer, kanban-контракт).

        В отличие от `post_fail` — по `conversionId`, БЕЗ `jobId` (DLQ-записи
        `conv.dead` его не несут, см. `KeyDbGateway.add_to_dlq`). Symfony
        персистит идемпотентно через `ConversionResultPersister` (skip, если
        конверсия уже в терминальном статусе). `processingMs` = null при
        отсутствии — тот же null-shape контракт, что и `post_fail`/`post_result`.

        `attempt` (requeue-attempt-generation-marker, кросс-зонный follow-up) —
        int|null. PHP сравнивает его с текущим `Conversion.attempt` и игнорирует
        (no-op) устаревший дубль finalize от прошлой попытки после operator-
        requeue (stale-guard). `None` (не 0!) — сигнал "guard пропустить,
        финализировать как обычно": шлём его для legacy DLQ-записей `conv.dead`
        без ключа `attempt` (написанных до этого изменения, дренируемых при
        первом деплое — см. `dlq_consumer._coerce_attempt`), чтобы их дренаж не
        падал и не блокировался несуществующим маркером попытки.

        Возвращает `True` на 2xx (ack-worthy) — БАЗОВЫЙ контракт. Дополнительно
        (не в исходном контракте, добавлено при обзоре: `dlq_consumer.py` теперь
        ретраит unacked-записи через `reclaim_dlq_idle`, поэтому не-retryable
        коды нужно явно отличать от транзиентных): ТОЛЬКО `{400, 404}` — те же
        коды, что реально отдаёт `InternalWorkerController::dlqFail` для
        неустранимой ошибки самого запроса (`400` — некорректный/нулевой
        `conversionId`, `404` — Conversion удалена/не найдена) — трактуются как
        `True` (ack, не ретраить: повтор того же запроса даст тот же ответ
        навсегда). НЕ включает произвольный диапазон 4xx: `401`/`403` (auth-
        мисконфиг `GATEWAY_INTERNAL_TOKEN`, см. firewall `internal_api`) и
        `408`/`429` — транзиентные/восстановимые, ack бы СБРОСИЛ запись из
        `conv.dead` без финализации (тихая потеря данных хуже исходного бага
        "conv.dead без потребителя"). Любой другой код (иные 4xx, все 5xx,
        сетевая ошибка) — `False` (retryable, оставить unacked).
        """
        payload = {
            "conversionId": conversion_id,
            "reason": reason,
            "processingMs": processing_ms,
            "attempt": attempt,
        }
        ok, status = await self._post_with_status(
            DLQ_FAIL_PATH, payload, f"conv:{conversion_id}"
        )
        if ok:
            return True
        if status in (400, 404):
            logger.error(
                "dlq-fail relay unprocessable-entry status — treating as terminal (ack, not retrying)",
                extra={"conversionId": conversion_id, "status": status},
            )
            return True
        return False

    async def post_expire(self, conversion_id: int, reason: str) -> tuple[bool, int | None]:
        """expire → Symfony (CNV-71-03: принятая, но никем не взятая за
        `WORKER_CLAIM_TIMEOUT_MINUTES` задача).

        Terminal-4xx whitelist (тот же паттерн, что `post_dlq_fail`, но УЖЕ:
        ТОЛЬКО `404` — "Conversion not found", строка удалена раньше, чем
        задачу вообще кто-то забрал → отмечать нечего, повтор того же запроса
        даст тот же 404 навсегда, оставлять backlog-запись висеть в стриме
        бессмысленно (review-находка CNV-71-03: без этого sweep ретраил бы её
        каждый тик вечно — постоянный шум в логах). `400` СОЗНАТЕЛЬНО НЕ в
        whitelist'е (в отличие от `post_dlq_fail`) — здесь `400` означал бы
        баг самого gateway (невалидный payload запроса), не факт об удалённой
        Conversion, и не должен тихо ack'аться. Возвращает `(True, 404)` в
        этом случае — WARNING логируется ЗДЕСЬ (не в `expiry.py`), вызывающий
        просто видит `ok=True` и удаляет backlog-запись обычной веткой.

        Любой другой не-2xx (иные 4xx, 5xx, сеть) — `(False, status)`, как и
        раньше: backlog-запись остаётся нетронутой, следующий тик повторит.
        Возвращает `(ok, status)` как `post_result`/`_post_with_status` —
        `status` — HTTP-код или `None` при сетевой ошибке (для логов
        вызывающего).
        """
        payload = {"conversionId": conversion_id, "reason": reason}
        ok, status = await self._post_with_status(
            EXPIRE_PATH, payload, f"conv:{conversion_id}"
        )
        if ok:
            return True, status
        if status == 404:
            logger.warning(
                "expire relay 404 — conversion not found, treating as terminal (not retrying)",
                extra={"conversionId": conversion_id, "status": status},
            )
            return True, status
        return False, status

    async def _post(self, path: str, payload: dict, job_id: str) -> bool:
        """POST JSON; True ⇢ 2xx (можно ack'ать), False ⇢ сеть/не-2xx (НЕ ack)."""
        ok, _status = await self._post_with_status(path, payload, job_id)
        return ok

    async def _post_with_status(
        self, path: str, payload: dict, job_id: str
    ) -> tuple[bool, int | None]:
        """POST JSON; `(True, status)` ⇢ 2xx, `(False, status|None)` иначе.

        `status=None` — сетевая ошибка (нет ответа вообще), не HTTP-статус.
        Отдельный от `_post` метод — нужен вызывающим, которым важен КОД
        ошибки (не только факт неуспеха), напр. `post_dlq_fail` (4xx vs 5xx).
        """
        url = self._base + path
        try:
            resp = await self._get_client().post(
                url, json=payload, headers=self._headers()
            )
        except httpx.HTTPError as exc:
            logger.warning(
                "relay request failed — not acking",
                extra={"path": path, "jobId": job_id, "error": str(exc)},
            )
            return False, None

        if 200 <= resp.status_code < 300:
            return True, resp.status_code

        logger.warning(
            "relay non-2xx — not acking",
            extra={"path": path, "jobId": job_id, "status": resp.status_code},
        )
        return False, resp.status_code

    async def post_host_telemetry(self, snapshot: dict) -> bool:
        """Relay a validated collector snapshot to Symfony."""
        return await self._post(HOST_TELEMETRY_PATH, snapshot, "host-telemetry")

    async def post_liveness(
        self, instances: list[dict], meta: dict | None = None
    ) -> tuple[bool, dict | None]:
        """Push a liveness batch (`workers/gateway/liveness.py`). Returns
        `(ok, parsed_body)`: `ok=True` only on 2xx WITH a parseable JSON object
        body (so `unknown` can be read); every other outcome — network error,
        timeout, non-2xx, or a 2xx with an unparsable/non-object body — is
        `(False, None)`, treated as "push failed, retry next cycle" by the
        caller. NEVER raises: this is telemetry, a wedged/garbage-returning PHP
        must not propagate into the liveness push loop (registry-06 resilience
        requirement) — contrast with `_post_with_status` (used by
        result/fail/dlq-fail), which only cares about the status code, not the
        body shape.

        `meta` (registry-09) — additive top-level envelope keys merged next to
        `instances`: `snapshot`/`authoritative`/`gatewayId`. They tell PHP
        whether this batch is a FULL alive-set snapshot it may reconcile
        against, or a plain delta. Kept OUT of the per-instance records
        deliberately: an older PHP build ignores unknown top-level keys and
        keeps applying the deltas exactly as before (backward compatible), and
        `instances` never grows a per-row copy of a connection-wide fact.
        """
        url = self._base + LIVENESS_PATH
        payload: dict = {"instances": instances}
        if meta:
            payload.update(meta)
        try:
            resp = await self._get_client().post(
                url, json=payload, headers=self._headers(),
                timeout=LIVENESS_TIMEOUT_S,
            )
        except httpx.HTTPError as exc:
            logger.warning(
                "liveness relay request failed — will retry next cycle",
                extra={"error": str(exc), "batchSize": len(instances)},
            )
            return False, None

        if not (200 <= resp.status_code < 300):
            logger.warning(
                "liveness relay non-2xx — will retry next cycle",
                extra={"status": resp.status_code, "batchSize": len(instances)},
            )
            return False, None

        try:
            body = resp.json()
        except ValueError as exc:
            logger.warning(
                "liveness relay 2xx but body unparsable — will retry next cycle",
                extra={"error": str(exc)},
            )
            return False, None
        if not isinstance(body, dict):
            logger.warning(
                "liveness relay 2xx but body is not a JSON object — will retry next cycle",
                extra={"bodyType": type(body).__name__},
            )
            return False, None
        return True, body

    async def aclose(self) -> None:
        """Закрыть собственный httpx-клиент (инжектированный не трогаем)."""
        if self._owns_client and self._client is not None:
            await self._client.aclose()
            self._client = None
