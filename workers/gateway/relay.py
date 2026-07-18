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

    async def _post(self, path: str, payload: dict, job_id: str) -> bool:
        """POST JSON; True ⇢ 2xx (можно ack'ать), False ⇢ сеть/не-2xx (НЕ ack)."""
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
            return False

        if 200 <= resp.status_code < 300:
            return True

        logger.warning(
            "relay non-2xx — not acking",
            extra={"path": path, "jobId": job_id, "status": resp.status_code},
        )
        return False

    async def aclose(self) -> None:
        """Закрыть собственный httpx-клиент (инжектированный не трогаем)."""
        if self._owns_client and self._client is not None:
            await self._client.aclose()
            self._client = None
