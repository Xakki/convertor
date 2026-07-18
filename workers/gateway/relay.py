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
# Таймаут одного relay-запроса. Persist (S3+БД) обычно быстрый; при зависании
# лучше не ack'ать и дать записи остаться pending, чем висеть на сокете.
RELAY_TIMEOUT_S = 30.0


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
    ) -> bool:
        """inline-результат → Symfony. `data` = base64 ДОСЛОВНО из WS-поля `inline`.

        Форма тела фиксирована (контракт с PHP-зоной): все четыре ключа всегда
        присутствуют, `mime`/`processingMs` = null при отсутствии — предсказуемый
        shape для парсинга на стороне Symfony.
        """
        payload = {
            "jobId": job_id,
            "data": data_b64,
            "mime": mime,
            "processingMs": processing_ms,
        }
        return await self._post(RESULT_PATH, payload, job_id)

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

    async def aclose(self) -> None:
        """Закрыть собственный httpx-клиент (инжектированный не трогаем)."""
        if self._owns_client and self._client is not None:
            await self._client.aclose()
            self._client = None
