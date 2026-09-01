from __future__ import annotations

import asyncio
import binascii
import base64
import json
import logging
import os
import signal
import time
from collections.abc import Awaitable, Callable
from pathlib import Path
from typing import Any

import httpx

from workers.api.config import Catalog, ModelConfig, load_catalog
from workers.common.ws_client import ProgressReporter, ResultSignal, WsClient, WsClientConfig

logger = logging.getLogger(__name__)
class ModelContext(str):
    """String-compatible upstream ID carrying its resolved provider model."""

    def __new__(cls, model: ModelConfig) -> ModelContext:
        value = super().__new__(cls, model.model_id)
        value.model = model
        return value

    model: ModelConfig


ChatSender = Callable[[ModelConfig | ModelContext | str, list[dict[str, str]]], Awaitable[dict[str, Any]]]
ModelsReader = Callable[[ModelConfig], Awaitable[set[str]]]

CAPABILITIES = {
    "routing_keys": ["api"],
    "isAi": True,
    "executionKind": "api",
    "matrix": {"txt": ["json_ai", "txt_ai"]},
    "matrix_categories": {"txt": "document"},
    "settings": {
        "model": {
            "default": "fast",
            "choices": [
                {"value": "fast", "label": "GPT-4o"},
                {"value": "balanced", "label": "GPT-4.1"},
            ],
        },
    },
}


class ChatRequestError(RuntimeError):
    def __init__(
        self,
        message: str,
        *,
        permanent: bool,
        fallback_allowed: bool | None = None,
        http_status: int | None = None,
        classification: str | None = None,
    ) -> None:
        super().__init__(message)
        self.permanent = permanent
        self.fallback_allowed = not permanent if fallback_allowed is None else fallback_allowed
        self.http_status = http_status
        self.classification = classification


def _startup_diagnostic(model: ModelConfig, operation: str, exc: Exception) -> dict[str, Any]:
    status = getattr(exc, "http_status", None)
    if not isinstance(status, int) or not 100 <= status <= 599:
        status = None
    classification = getattr(exc, "classification", None)
    if classification is None and status is not None:
        classification = "transient" if status in {408, 425, 429} or status >= 500 else "client_error"
    if not isinstance(classification, str) or classification not in {"transient", "client_error", "invalid_response", "exception", "not_advertised"}:
        classification = "exception"
    diagnostic: dict[str, Any] = {
        "provider_id": model.provider_id,
        "model_key": model.key,
        "operation": operation,
        "exception_class": type(exc).__name__,
        "classification": classification,
    }
    if status is not None:
        diagnostic["http_status"] = status
    return diagnostic


def capabilities(catalog: Catalog, models: dict[str, ModelConfig]) -> dict[str, Any]:
    validated_models = [model for model in catalog.models.values() if model.key in models]
    live_default = next(
        (
            model.key
            for model in catalog.fallback_chain(catalog.default_model_key)
            if model.key in models
        ),
        None,
    )
    if live_default is None:
        live_default = validated_models[0].key
    matrix = catalog.public_pairs()
    if not matrix and catalog.legacy_v1:
        matrix = {"txt": ["json_ai", "txt_ai"]}
    return {
        "routing_keys": ["api"],
        "isAi": True,
        "executionKind": "api",
        "matrix": matrix,
        "matrix_categories": {"txt": "document"},
        "settings": {
            "model": {
                "default": live_default,
                "choices": [
                    {"value": model.key, "label": model.label}
                    for model in validated_models
                ],
            },
        },
    }


async def probe_models(catalog: Catalog, send: ChatSender) -> dict[str, ModelConfig]:
    validated: dict[str, ModelConfig] = {}
    for model in catalog.models.values():
        try:
            await send(ModelContext(model), [{"role": "user", "content": "ping"}])
            validated[model.key] = model
        except Exception as exc:
            logger.error("startup capability unavailable", extra=_startup_diagnostic(model, "chat", exc))
    return validated


async def validate_models(catalog: Catalog, read_models: ModelsReader) -> dict[str, ModelConfig]:
    """Validate configured IDs using the authenticated, non-billable models API."""
    validated: dict[str, ModelConfig] = {}
    by_provider: dict[str, list[ModelConfig]] = {}
    for model in catalog.models.values():
        by_provider.setdefault(model.provider_id, []).append(model)
    for provider_models in by_provider.values():
        try:
            available = await read_models(provider_models[0])
        except Exception as exc:
            for model in provider_models:
                logger.error("startup capability unavailable", extra=_startup_diagnostic(model, "models", exc))
            continue
        for model in provider_models:
            if model.model_id in available:
                validated[model.key] = model
                logger.info(
                    "startup capability validated",
                    extra={
                        "provider_id": model.provider_id,
                        "model_key": model.key,
                        "operation": "models",
                        "classification": "success",
                    },
                )
            else:
                logger.error(
                    "configured upstream model is not advertised",
                    extra={**_startup_diagnostic(model, "models", ValueError("model not advertised")), "classification": "not_advertised"},
                )
    return validated


async def validate_startup_models(catalog: Catalog, send: ChatSender, client: httpx.AsyncClient) -> dict[str, ModelConfig]:
    """Run each provider's declared startup check without probing models unnecessarily."""
    validated: dict[str, ModelConfig] = {}
    for provider_id in {model.provider_id for model in catalog.models.values()}:
        models = [model for model in catalog.models.values() if model.provider_id == provider_id]
        operation = catalog.operations.get(f"{provider_id}/chat") or catalog.operations.get("chat")
        subset = Catalog(provider_id, models[0].base_url, models[0].bearer_token, models[0].endpoint, catalog.schema, models[0].timeout_sec, catalog.output_type, models[0].key, {m.key: m for m in models})
        if operation is not None and operation.startup_mode == "models":
            validated.update(await validate_models(subset, lambda model: read_openai_models(client, model)))
        else:
            validated.update(await probe_models(subset, send))
    return validated


async def read_openai_models(client: httpx.AsyncClient, model: ModelConfig) -> set[str]:
    response = await client.get(
        model.base_url + "/v1/models",
        headers={"Authorization": f"Bearer {model.bearer_token}"},
        timeout=model.timeout_sec,
    )
    if response.status_code >= 400:
        classification = "transient" if response.status_code in {408, 425, 429} or response.status_code >= 500 else "client_error"
        raise ChatRequestError(
            f"models provider returned HTTP {response.status_code}",
            permanent=False,
            http_status=response.status_code,
            classification=classification,
        )
    try:
        rows = response.json()["data"]
        return {row["id"] for row in rows if isinstance(row, dict) and isinstance(row.get("id"), str)}
    except (ValueError, KeyError, TypeError):
        raise ChatRequestError("models provider returned an invalid response", permanent=True, classification="invalid_response")


def build_http_sender(catalog: Catalog, client: httpx.AsyncClient) -> ChatSender:
    async def send(model_ref: ModelConfig | ModelContext | str, messages: list[dict[str, str]]) -> dict[str, Any]:
        if isinstance(model_ref, ModelConfig):
            model = model_ref
        elif isinstance(model_ref, ModelContext):
            model = model_ref.model
        else:
            matches = [candidate for candidate in catalog.models.values() if candidate.model_id == model_ref]
            if len(matches) != 1:
                raise ChatRequestError("chat model id is not uniquely configured", permanent=True)
            model = matches[0]
        assert isinstance(model, ModelConfig)
        model_id = model.model_id
        try:
            response = await client.post(
                model.base_url + model.endpoint,
                headers={"Authorization": f"Bearer {model.bearer_token}"},
                json={"model": model_id, "messages": messages, **model.params},
                timeout=model.timeout_sec,
            )
        except httpx.TransportError as exc:
            raise ChatRequestError(
                "chat provider transport failure",
                permanent=False,
                fallback_allowed=True,
            ) from exc

        if response.status_code >= 400:
            transient = response.status_code in {408, 425, 429} or response.status_code >= 500
            model_unavailable = response.status_code == 404
            raise ChatRequestError(
                f"chat provider returned HTTP {response.status_code}",
                permanent=not transient,
                fallback_allowed=transient or model_unavailable,
                http_status=response.status_code,
                classification="transient" if transient else "client_error",
            )
        try:
            payload = response.json()
            content = payload["choices"][0]["message"]["content"]
        except (ValueError, KeyError, IndexError, TypeError) as exc:
            raise ChatRequestError("chat provider returned an invalid response", permanent=True) from exc
        if not isinstance(content, str):
            raise ChatRequestError("chat provider returned non-text content", permanent=True)
        return payload

    return send


def extract_image_bytes(payload: Any, operation: Any) -> tuple[bytes, str, str]:
    """Decode only the response transport explicitly declared by the catalog."""
    kind = operation.response.get("kind")
    field = operation.response.get("field")
    value: Any = payload
    if field:
        for part in field.replace("[", ".").replace("]", "").split("."):
            if not part:
                continue
            value = value[int(part)] if isinstance(value, list) else value[part]
    if kind == "bytes" and isinstance(value, (bytes, bytearray)):
        return bytes(value), "image/png", "png"
    if kind == "base64" and isinstance(value, str):
        try:
            return base64.b64decode(value, validate=True), "image/png", "png"
        except (ValueError, binascii.Error) as exc:
            raise ChatRequestError("image provider returned invalid base64", permanent=True) from exc
    raise ChatRequestError("image response transport is not configured or invalid", permanent=True)


def build_image_request(prompt: str, options: dict[str, Any], operation: Any) -> dict[str, Any]:
    """Build an image request from declared field mappings; reject hidden fields."""
    request: dict[str, Any] = {}
    prompt_field = operation.request.get("prompt", "prompt")
    request[prompt_field] = prompt
    for option, value in options.items():
        target = operation.request.get(option)
        if target is None:
            raise ChatRequestError(f"image option {option} is not configured", permanent=True)
        request[target] = value
    return request


def build_handle_job(catalog: Catalog, validated: dict[str, ModelConfig], send: ChatSender):
    async def handle_job(job: dict, progress: ProgressReporter) -> ResultSignal:
        started = time.monotonic()
        raw_options = job.get("options")
        if raw_options is not None and not isinstance(raw_options, dict):
            return ResultSignal.failed(error="options must be an object", permanent=True)
        options: dict[str, Any] = raw_options or {}
        selected_value = options["model"] if "model" in options else catalog.default_model_key
        if not isinstance(selected_value, str) or not selected_value:
            return ResultSignal.failed(error="model key must be a non-empty string", permanent=True)
        try:
            chain = catalog.fallback_chain(catalog.resolve_selector(selected_value).key)
        except ValueError as exc:
            return ResultSignal.failed(error=str(exc), permanent=True)
        chain = [model for model in chain if model.key in validated]
        if not chain:
            return ResultSignal.failed(error="no validated model in fallback chain", permanent=False)

        input_path = Path(str(job.get("_localInput", "")))
        try:
            prompt = input_path.read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            return ResultSignal.failed(error="input must be readable UTF-8 text", permanent=True)

        last_error = "chat request failed"
        last_permanent = False
        for model in chain:
            try:
                progress.report(25, "chat")
                payload = await send(ModelContext(model), [{"role": "user", "content": prompt}])
                if isinstance(payload, str):
                    payload = {"choices": [{"message": {"content": payload}}]}
                content = payload["choices"][0]["message"]["content"]
                target = str(job.get("targetFormat", "json_ai"))
                if target == "json_ai":
                    result_data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
                    mime, ext = "application/json", "json_ai"
                elif target == "txt_ai":
                    result_data = content.encode("utf-8")
                    mime, ext = "text/plain", "txt_ai"
                else:
                    return ResultSignal.failed(error="unsupported API target format", permanent=True)
                return ResultSignal.completed(
                    data=result_data, mime=mime, ext=ext,
                    processing_ms=int((time.monotonic() - started) * 1000),
                )
            except ChatRequestError as exc:
                last_error = str(exc)
                last_permanent = exc.permanent
                if not exc.fallback_allowed:
                    return ResultSignal.failed(
                        error=last_error,
                        permanent=exc.permanent,
                        processing_ms=int((time.monotonic() - started) * 1000),
                    )
            except Exception as exc:
                logger.error("unexpected chat request failure", extra={"reason": type(exc).__name__})
                return ResultSignal.failed(
                    error="unexpected chat request failure",
                    permanent=True,
                    processing_ms=int((time.monotonic() - started) * 1000),
                )
        return ResultSignal.failed(
            error=last_error,
            permanent=last_permanent,
            processing_ms=int((time.monotonic() - started) * 1000),
        )

    return handle_job


async def run_worker() -> None:
    catalog = load_catalog(Path(os.getenv("WORKER_API_CONFIG", "/app/worker-api.yaml")))
    async with httpx.AsyncClient() as client:
        send = build_http_sender(catalog, client)
        validated = await validate_startup_models(catalog, send, client)
        if not validated:
            logger.error("worker-api refuses registration: no chat model passed startup probe")
            return
        ws_config = WsClientConfig.from_env()
        ws_config.validate()
        ws_client = WsClient(
            ws_config,
            build_handle_job(catalog, validated, send),
            capabilities=capabilities(catalog, validated),
        )
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(sig, ws_client.stop)
        await ws_client.run()


def run() -> None:
    asyncio.run(run_worker())
