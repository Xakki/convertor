from __future__ import annotations

import hashlib
import json
import shutil
from pathlib import Path

import httpx
import pytest

import workers.api.worker as api_worker
from workers.api.config import Catalog, ModelConfig, OperationConfig, load_catalog
from workers.api.worker import ChatRequestError, build_handle_job, build_http_sender, capabilities, probe_models, read_openai_models, validate_models, validate_startup_models
from workers.common.ws_client import ProgressReporter
from workers.tools.gen_worker_capabilities import generate_catalog


CATALOG = """
version: 1
providers:
  - id: aip-g4f
    kind: g4f
    base_url: https://aip.xakki.ru
    schema: aip-g4f/v0.5
    credentials: {bearer_env: G4F_API_KEY}
    operations:
      chat:
        output_type: ai
        endpoint: /v1/chat/completions
        models:
          fast: {model: gpt-4o, label: Fast, fallback: [backup]}
          backup: {model: gpt-4.1, label: Backup}
        startup_check: {mode: chat_completion, timeout_sec: 5}
routing: {defaults: {chat: fast}}
""".strip()


def test_same_upstream_model_id_keeps_provider_context_for_probe_and_request() -> None:
    models = {
        "first/model": ModelConfig("first/model", "shared-model", "First", provider_id="first", base_url="https://first.example", bearer_token="one"),
        "second/model": ModelConfig("second/model", "shared-model", "Second", provider_id="second", base_url="https://second.example", bearer_token="two"),
    }
    catalog = Catalog("first", "https://first.example", "one", "/v1/chat/completions", "first/v1", 15, "ai", "first/model", models)
    requests: list[httpx.Request] = []

    async def respond(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"choices": [{"message": {"content": "pong"}}]})

    async def exercise() -> None:
        async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
            send = build_http_sender(catalog, client)
            validated = await probe_models(catalog, send)
            assert list(validated) == ["first/model", "second/model"]
            await send(models["second/model"], [{"role": "user", "content": "ping"}])

    import asyncio
    asyncio.run(exercise())
    assert [request.url.host for request in requests] == ["first.example", "second.example", "second.example"]


@pytest.mark.asyncio
async def test_job_path_uses_selected_provider_for_duplicate_upstream_model_id(tmp_path: Path) -> None:
    first_token = "first-test-token"
    second_token = "second-test-token"
    models = {
        "first/fast": ModelConfig(
            "first/fast", "shared-model", "First", tiers=("fast",),
            provider_id="first", base_url="https://first.example", bearer_token=first_token,
        ),
        "second/fast": ModelConfig(
            "second/fast", "shared-model", "Second", tiers=("fast",),
            provider_id="second", base_url="https://second.example", bearer_token=second_token,
        ),
    }
    catalog = Catalog(
        "first", "https://first.example", first_token, "/v1/chat/completions",
        "first/v1", 15, "ai", "first/fast", models,
        providers={"first": {}, "second": {}},
    )
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    requests: list[httpx.Request] = []

    def respond(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"choices": [{"message": {"content": "answer"}}]})

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        result = await build_handle_job(catalog, models, build_http_sender(catalog, client))(
            {"_localInput": str(input_path), "targetFormat": "txt_ai", "options": {"model": "second/fast"}},
            ProgressReporter(),
        )

    assert result.ok
    assert len(requests) == 1
    assert requests[0].url == "https://second.example/v1/chat/completions"
    assert requests[0].headers["Authorization"] == f"Bearer {second_token}"
    assert json.loads(requests[0].content)["model"] == "shared-model"
    assert first_token not in requests[0].headers["Authorization"]


def catalog(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG, encoding="utf-8")
    return load_catalog(path)


def test_catalog_accepts_canonical_aip_origin(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)

    assert loaded.base_url == "https://aip.xakki.ru"


def test_catalog_validates_worker_only_output_type_and_exposes_no_request_fields(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    assert loaded.output_type == "ai"
    bad = tmp_path / "bad.yaml"
    bad.write_text(CATALOG.replace("output_type: ai", "output_type: text"), encoding="utf-8")
    with pytest.raises(ValueError, match="output_type"):
        load_catalog(bad)


def test_catalog_rejects_noncanonical_bearer_env(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("OTHER_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG.replace("bearer_env: G4F_API_KEY", "bearer_env: OTHER_API_KEY"), encoding="utf-8")

    with pytest.raises(ValueError, match="credentials.bearer_env must be G4F_API_KEY"):
        load_catalog(path)


@pytest.mark.parametrize(
    "base_url",
    [
        "https://attacker.example",
        "https://aip.xakki.ru.attacker.example",
        "https://aip.xakki.ru@attacker.example",
        "https://attacker.example@aip.xakki.ru",
        "https://aip.xakki.ru:8443",
        "http://aip.xakki.ru",
        "https://aip.xakki.ru/v1",
        "https://aip.xakki.ru?target=attacker.example",
        "https://aip.xakki.ru#attacker.example",
    ],
)
def test_catalog_rejects_noncanonical_aip_origin(
    base_url: str,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG.replace("https://aip.xakki.ru", base_url), encoding="utf-8")

    with pytest.raises(ValueError, match="provider.base_url"):
        load_catalog(path)


def test_catalog_resolves_default_and_explicit_same_provider_fallback(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    assert loaded.default_model_key == "fast"
    assert loaded.resolve_model(None).model_id == "gpt-4o"
    assert [model.key for model in loaded.fallback_chain("fast")] == ["fast", "backup"]
    assert loaded.public_models() == [
        {"value": "fast", "label": "Fast"},
        {"value": "backup", "label": "Backup"},
    ]
    with pytest.raises(ValueError, match="not allowed"):
        loaded.resolve_model("arbitrary-model")


def test_catalog_supports_real_model_id_allowlist_with_explicit_default(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(
        CATALOG.replace(
            "models:\n          fast: {model: gpt-4o, label: Fast, fallback: [backup]}\n          backup: {model: gpt-4.1, label: Backup}",
            "models: [openai/gpt-4o, anthropic/claude-sonnet]",
        ).replace("chat: fast", "chat: anthropic/claude-sonnet"),
        encoding="utf-8",
    )

    loaded = load_catalog(path)

    assert loaded.default_model_key == "anthropic/claude-sonnet"
    assert loaded.resolve_model("openai/gpt-4o").model_id == "openai/gpt-4o"
    assert loaded.public_models() == [
        {"value": "openai/gpt-4o", "label": "openai/gpt-4o"},
        {"value": "anthropic/claude-sonnet", "label": "anthropic/claude-sonnet"},
    ]
    assert loaded.models["openai/gpt-4o"].fallback == ()


def test_catalog_list_models_require_explicit_allowed_default(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(
        CATALOG.replace(
            "models:\n          fast: {model: gpt-4o, label: Fast, fallback: [backup]}\n          backup: {model: gpt-4.1, label: Backup}",
            "models: [openai/gpt-4o]",
        ).replace("chat: fast", "chat: missing-model"),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="routing.defaults.chat"):
        load_catalog(path)


def test_catalog_rejects_yaml_schema_ref_not_declared_by_manifest(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG.replace("aip-g4f/v0.5", "aip-g4f/v9.9"), encoding="utf-8")

    with pytest.raises(ValueError, match="schema reference"):
        load_catalog(path)


def test_catalog_rejects_schema_manifest_checksum_mismatch(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    schema_root = _copy_schema_fixture(tmp_path)
    manifest_path = schema_root / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest["sha256"] = "0" * 64
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG, encoding="utf-8")

    with pytest.raises(ValueError, match="checksum"):
        load_catalog(path, schema_root=schema_root)


def test_catalog_rejects_schema_manifest_snapshot_path_traversal(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    schema_root = _copy_schema_fixture(tmp_path)
    manifest_path = schema_root / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest["snapshot"] = "../outside.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG, encoding="utf-8")

    with pytest.raises(ValueError, match="safe relative path"):
        load_catalog(path, schema_root=schema_root)


def _copy_schema_fixture(tmp_path: Path) -> Path:
    source = Path(__file__).resolve().parents[1] / "api/schema"
    target = tmp_path / "schema"
    shutil.copytree(source, target)
    return target


def test_catalog_rejects_inline_secret_and_fallback_cycle(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(CATALOG.replace("bearer_env: G4F_API_KEY", "bearer_env: G4F_API_KEY, bearer: leaked"), encoding="utf-8")
    with pytest.raises(ValueError, match="credentials"):
        load_catalog(path)

    path.write_text(CATALOG.replace("fallback: [backup]", "fallback: [backup]").replace(
        "backup: {model: gpt-4.1, label: Backup}",
        "backup: {model: gpt-4.1, label: Backup, fallback: [fast]}",
    ), encoding="utf-8")
    with pytest.raises(ValueError, match="cycle"):
        load_catalog(path)


def test_capabilities_uses_first_validated_fallback_as_live_default(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    public = capabilities(loaded, {"backup": loaded.models["backup"]})
    assert public["settings"]["model"]["default"] == "backup"
    assert public["settings"]["model"]["choices"] == [{"value": "backup", "label": "Backup"}]


@pytest.mark.asyncio
async def test_startup_registers_surviving_list_model_as_live_default(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("G4F_API_KEY", "secret-for-test")
    path = tmp_path / "worker-api.yaml"
    path.write_text(
        CATALOG.replace(
            "models:\n          fast: {model: gpt-4o, label: Fast, fallback: [backup]}\n          backup: {model: gpt-4.1, label: Backup}",
            "models: [openai/gpt-4o, anthropic/claude-sonnet]",
        ).replace("chat: fast", "chat: anthropic/claude-sonnet"),
        encoding="utf-8",
    )
    loaded = load_catalog(path)
    registered: list[dict] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        if model_id == "anthropic/claude-sonnet":
            raise ChatRequestError("upstream unavailable", permanent=False)
        return "pong"

    class CapturingWsClient:
        def __init__(self, config: object, handle_job: object, *, capabilities: dict) -> None:
            registered.append(capabilities)

        async def run(self) -> None:
            return None

        def stop(self) -> None:
            return None

    monkeypatch.setattr(api_worker, "load_catalog", lambda path: loaded)
    monkeypatch.setattr(api_worker, "build_http_sender", lambda catalog, client: send)
    monkeypatch.setattr(api_worker, "WsClient", CapturingWsClient)
    ws_config = api_worker.WsClientConfig(
        worker_id="worker-api-test",
        worker_type="api",
        gateway_ws_url="ws://gateway.test",
        api_base_url="http://api.test",
        worker_api_token="test-token",
        version="test",
        work_dir=tmp_path,
    )
    monkeypatch.setattr(api_worker.WsClientConfig, "from_env", lambda: ws_config)

    await api_worker.run_worker()

    assert registered == [
        {
            "routing_keys": ["api"],
            "isAi": True,
            "executionKind": "api",
            "matrix": {"txt": ["json_ai", "txt_ai"]},
            "matrix_categories": {"txt": "document"},
            "settings": {
                "model": {
                    "default": "openai/gpt-4o",
                    "choices": [{"value": "openai/gpt-4o", "label": "openai/gpt-4o"}],
                }
            },
        }
    ]


def test_public_capabilities_expose_keys_labels_and_default_only(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    public = capabilities(loaded, loaded.models)
    assert public["executionKind"] == "api"
    assert public["settings"] == {
        "model": {
            "default": "fast",
            "choices": [
                {"value": "fast", "label": "Fast"},
                {"value": "backup", "label": "Backup"},
            ],
        }
    }
    rendered = json.dumps(public)
    assert "aip.xakki.ru" not in rendered
    assert "gpt-4o" not in rendered
    assert "secret-for-test" not in rendered


@pytest.mark.asyncio
async def test_startup_probe_publishes_only_chat_models_that_pass(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    requests: list[dict] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        requests.append({"model": model_id, "messages": messages})
        if model_id == "gpt-4.1":
            raise ChatRequestError("upstream unavailable", permanent=False)
        return "pong"

    validated = await probe_models(loaded, send)
    assert list(validated) == ["fast"]
    assert requests == [
        {"model": "gpt-4o", "messages": [{"role": "user", "content": "ping"}]},
        {"model": "gpt-4.1", "messages": [{"role": "user", "content": "ping"}]},
    ]


@pytest.mark.asyncio
async def test_startup_diagnostics_identify_provider_model_operation_and_safe_failure(caplog: pytest.LogCaptureFixture) -> None:
    models = {
        "aip-g4f/fast": ModelConfig("aip-g4f/fast", "private-upstream-model", "Fast", provider_id="aip-g4f", base_url="https://g4f.example", bearer_token="g4f-secret"),
    }
    loaded = Catalog("aip-g4f", "https://g4f.example", "g4f-secret", "/v1/chat/completions", "aip-g4f/v1", 5, "ai", "aip-g4f/fast", models)

    async def send(model: ModelConfig, messages: list[dict[str, str]]) -> dict:
        raise ChatRequestError("chat provider returned HTTP 503", permanent=False, http_status=503)

    with caplog.at_level("ERROR", logger="workers.api.worker"):
        assert await probe_models(loaded, send) == {}

    record = caplog.records[0]
    assert record.message == "startup capability unavailable"
    assert record.provider_id == "aip-g4f"
    assert record.model_key == "aip-g4f/fast"
    assert record.operation == "chat"
    assert record.exception_class == "ChatRequestError"
    assert record.http_status == 503
    assert record.classification == "transient"
    rendered = caplog.text
    assert "g4f-secret" not in rendered
    assert "private-upstream-model" not in rendered


@pytest.mark.asyncio
async def test_mixed_provider_run_worker_probes_each_provider_and_registers_ws(
    caplog: pytest.LogCaptureFixture,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    g4f = ModelConfig("aip-g4f/fast", "g4f-private", "G4F", provider_id="aip-g4f", base_url="https://g4f.example", bearer_token="g4f-secret")
    gonka = ModelConfig("gonka/normal", "gonka-private", "Gonka", provider_id="gonka", base_url="https://gonka.example", bearer_token="gonka-secret")
    loaded = Catalog(
        "aip-g4f", "https://g4f.example", "g4f-secret", "/v1/chat/completions", "mixed/v1", 5, "ai", g4f.key,
        {g4f.key: g4f, gonka.key: gonka},
        operations={
            "aip-g4f/chat": OperationConfig("chat", "/v1/chat/completions", startup_mode="chat_completion"),
            "gonka/chat": OperationConfig("chat", "/v1/chat/completions", startup_mode="models"),
        },
        providers={"aip-g4f": {}, "gonka": {}},
    )
    requests: list[httpx.Request] = []

    def respond(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        if request.method == "POST":
            return httpx.Response(503)
        return httpx.Response(200, json={"data": [{"id": "gonka-private"}]})

    registered: list[dict] = []

    class CapturingWsClient:
        def __init__(self, config: object, handle_job: object, *, capabilities: dict) -> None:
            registered.append(capabilities)

        async def run(self) -> None:
            return None

        def stop(self) -> None:
            return None

    monkeypatch.setattr(api_worker, "load_catalog", lambda path: loaded)
    monkeypatch.setattr(api_worker, "WsClient", CapturingWsClient)
    real_async_client = httpx.AsyncClient
    monkeypatch.setattr(
        api_worker.httpx,
        "AsyncClient",
        lambda: real_async_client(transport=httpx.MockTransport(respond)),
    )
    ws_config = api_worker.WsClientConfig(
        worker_id="worker-api-mixed-test",
        worker_type="api",
        gateway_ws_url="ws://gateway.test",
        api_base_url="http://api.test",
        worker_api_token="test-token",
        version="test",
        work_dir=Path("/tmp"),
    )
    monkeypatch.setattr(api_worker.WsClientConfig, "from_env", lambda: ws_config)

    with caplog.at_level("INFO", logger="workers.api.worker"):
        await api_worker.run_worker()

    assert sorted((request.method, str(request.url)) for request in requests) == sorted([
        ("POST", "https://g4f.example/v1/chat/completions"),
        ("GET", "https://gonka.example/v1/models"),
    ])
    assert registered and registered[0]["settings"]["model"]["choices"] == [{"value": "gonka/normal", "label": "Gonka"}]
    messages = [(record.message, getattr(record, "provider_id", None), getattr(record, "model_key", None), getattr(record, "operation", None)) for record in caplog.records]
    assert ("startup capability unavailable", "aip-g4f", "aip-g4f/fast", "chat") in messages
    assert ("startup capability validated", "gonka", "gonka/normal", "models") in messages
    assert "g4f-secret" not in caplog.text
    assert "gonka-secret" not in caplog.text
    assert "g4f-private" not in caplog.text
    assert "gonka-private" not in caplog.text


@pytest.mark.asyncio
async def test_models_startup_check_is_authenticated_read_only_and_filters_ids(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    models = list(loaded.models.values())
    requests: list[httpx.Request] = []

    def respond(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"data": [{"id": "gpt-4o"}]})

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        available = await read_openai_models(client, models[0])
        validated = await validate_models(loaded, lambda model: read_openai_models(client, model))

    assert available == {"gpt-4o"}
    assert list(validated) == ["fast"]
    assert len(requests) == 2
    assert all(request.method == "GET" for request in requests)
    assert all(request.url == "https://aip.xakki.ru/v1/models" for request in requests)
    assert requests[0].headers["Authorization"] == "Bearer secret-for-test"


@pytest.mark.asyncio
async def test_authenticated_chat_post_parses_openai_response(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)

    async def respond(request: httpx.Request) -> httpx.Response:
        assert request.headers["Authorization"] == "Bearer secret-for-test"
        assert json.loads(request.content) == {
            "model": "gpt-4o",
            "messages": [{"role": "user", "content": "hello"}],
        }
        return httpx.Response(200, json={"choices": [{"message": {"content": "world"}}]})

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        assert await build_http_sender(loaded, client)("gpt-4o", [{"role": "user", "content": "hello"}]) == {"choices": [{"message": {"content": "world"}}]}


@pytest.mark.asyncio
async def test_real_chat_sender_preserves_503_status_and_classification(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    async with httpx.AsyncClient(transport=httpx.MockTransport(lambda request: httpx.Response(503))) as client:
        with pytest.raises(ChatRequestError) as caught:
            await build_http_sender(loaded, client)("gpt-4o", [{"role": "user", "content": "hello"}])
    assert caught.value.http_status == 503
    assert caught.value.classification == "transient"
    assert "secret-for-test" not in str(caught.value)


@pytest.mark.asyncio
@pytest.mark.parametrize(
    "status,permanent,fallback_allowed",
    [
        (400, True, False),
        (401, True, False),
        (403, True, False),
        (404, True, True),
        (408, False, True),
        (418, True, False),
        (422, True, False),
        (429, False, True),
        (503, False, True),
    ],
)
async def test_chat_post_classifies_http_errors(
    status: int,
    permanent: bool,
    fallback_allowed: bool,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    async with httpx.AsyncClient(transport=httpx.MockTransport(lambda request: httpx.Response(status))) as client:
        with pytest.raises(ChatRequestError) as caught:
            await build_http_sender(loaded, client)("gpt-4o", [{"role": "user", "content": "hello"}])
    assert caught.value.permanent is permanent
    assert caught.value.fallback_allowed is fallback_allowed
    assert "secret-for-test" not in str(caught.value)


@pytest.mark.asyncio
@pytest.mark.parametrize(
    ("target", "expected_data", "mime", "ext"),
    [
        ("json_ai", b'{"id": "provider-1", "choices": [{"message": {"content": "answer"}}]}', "application/json", "json_ai"),
        ("txt_ai", b"answer", "text/plain", "txt_ai"),
    ],
)
async def test_job_serializes_provider_payload_by_api_target(
    target: str,
    expected_data: bytes,
    mime: str,
    ext: str,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")

    async def send(model_id: str, messages: list[dict[str, str]]) -> dict:
        return {"id": "provider-1", "choices": [{"message": {"content": "answer"}}]}

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "targetFormat": target, "options": {}},
        ProgressReporter(),
    )

    assert result.ok
    assert result.data == expected_data
    assert result.mime == mime
    assert result.ext == ext


@pytest.mark.asyncio
async def test_job_falls_back_only_after_transient_error(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        calls.append(model_id)
        if model_id == "gpt-4o":
            raise ChatRequestError("rate limited", permanent=False)
        return "answer"

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "_jobDir": str(tmp_path), "options": {"model": "fast"}},
        ProgressReporter(),
    )
    assert result.ok
    assert calls == ["gpt-4o", "gpt-4.1"]
    assert result.path is None
    assert isinstance(result.data, bytes)
    assert json.loads(result.data) == {"choices": [{"message": {"content": "answer"}}]}


@pytest.mark.asyncio
async def test_job_falls_back_after_upstream_model_not_found(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    def respond(request: httpx.Request) -> httpx.Response:
        model_id = json.loads(request.content)["model"]
        calls.append(model_id)
        if model_id == "gpt-4o":
            return httpx.Response(404)
        return httpx.Response(200, json={"choices": [{"message": {"content": "answer"}}]})

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        result = await build_handle_job(loaded, loaded.models, build_http_sender(loaded, client))(
            {"_localInput": str(input_path), "options": {"model": "fast"}},
            ProgressReporter(),
        )

    assert result.ok
    assert calls == ["gpt-4o", "gpt-4.1"]


@pytest.mark.asyncio
async def test_job_falls_back_after_remote_protocol_error(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    def respond(request: httpx.Request) -> httpx.Response:
        model_id = json.loads(request.content)["model"]
        calls.append(model_id)
        if model_id == "gpt-4o":
            raise httpx.RemoteProtocolError("upstream disconnected", request=request)
        return httpx.Response(200, json={"choices": [{"message": {"content": "answer"}}]})

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        result = await build_handle_job(loaded, loaded.models, build_http_sender(loaded, client))(
            {"_localInput": str(input_path), "options": {"model": "fast"}},
            ProgressReporter(),
        )

    assert result.ok
    assert calls == ["gpt-4o", "gpt-4.1"]


@pytest.mark.asyncio
async def test_job_does_not_fallback_after_too_many_redirects(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    def respond(request: httpx.Request) -> httpx.Response:
        calls.append(json.loads(request.content)["model"])
        raise httpx.TooManyRedirects("redirect limit exceeded", request=request)

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        result = await build_handle_job(loaded, loaded.models, build_http_sender(loaded, client))(
            {"_localInput": str(input_path), "options": {"model": "fast"}},
            ProgressReporter(),
        )

    assert not result.ok
    assert result.permanent
    assert calls == ["gpt-4o"]


@pytest.mark.asyncio
@pytest.mark.parametrize("status", [400, 401, 403, 418, 422])
async def test_job_does_not_fallback_after_nontransient_http_error(
    status: int,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    def respond(request: httpx.Request) -> httpx.Response:
        calls.append(json.loads(request.content)["model"])
        return httpx.Response(status)

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        result = await build_handle_job(loaded, loaded.models, build_http_sender(loaded, client))(
            {"_localInput": str(input_path), "options": {"model": "fast"}},
            ProgressReporter(),
        )

    assert not result.ok
    assert result.permanent
    assert calls == ["gpt-4o"]


@pytest.mark.asyncio
async def test_job_rejects_malformed_options_without_sending(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        pytest.fail("malformed options must not reach the provider")

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "options": ["fast"]},
        ProgressReporter(),
    )

    assert not result.ok
    assert result.permanent
    assert result.error == "options must be an object"


@pytest.mark.asyncio
@pytest.mark.parametrize("model", ["", None, 123])
async def test_job_rejects_malformed_model_key_without_sending(
    model: object,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        pytest.fail("malformed model key must not reach the provider")

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "options": {"model": model}},
        ProgressReporter(),
    )

    assert not result.ok
    assert result.permanent
    assert result.error == "model key must be a non-empty string"


@pytest.mark.asyncio
async def test_job_does_not_fallback_after_unexpected_sender_error(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        calls.append(model_id)
        raise RuntimeError("unexpected")

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "options": {"model": "fast"}},
        ProgressReporter(),
    )

    assert not result.ok
    assert result.permanent
    assert calls == ["gpt-4o"]


@pytest.mark.asyncio
async def test_job_uses_catalog_default_and_returns_json_bytes(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        calls.append(model_id)
        return "answer"

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "options": {}},
        ProgressReporter(),
    )

    assert result.ok
    assert calls == ["gpt-4o"]
    assert result.path is None
    assert isinstance(result.data, bytes)
    assert json.loads(result.data) == {"choices": [{"message": {"content": "answer"}}]}


@pytest.mark.asyncio
async def test_job_does_not_fallback_after_permanent_error(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    input_path = tmp_path / "input.txt"
    input_path.write_text("hello", encoding="utf-8")
    calls: list[str] = []

    async def send(model_id: str, messages: list[dict[str, str]]) -> str:
        calls.append(model_id)
        raise ChatRequestError("unauthorized", permanent=True)

    result = await build_handle_job(loaded, loaded.models, send)(
        {"_localInput": str(input_path), "_jobDir": str(tmp_path), "options": {"model": "fast"}},
        ProgressReporter(),
    )
    assert not result.ok
    assert result.permanent
    assert calls == ["gpt-4o"]


def test_generated_catalog_contains_api_execution_kind_and_public_settings() -> None:
    api = next(blob for blob in generate_catalog() if blob["workerType"] == "api")
    assert api["executionKind"] == "api"
    assert api["settings"] == {
        "model": {
            "default": "fast",
            "choices": [
                {"value": "fast", "label": "GPT-4o"},
                {"value": "balanced", "label": "GPT-4.1"},
            ],
        },
    }
    rendered = json.dumps(api)
    assert "aip.xakki.ru" not in rendered
    assert "G4F_API_KEY" not in rendered


def test_openapi_snapshot_matches_manifest() -> None:
    root = Path(__file__).resolve().parents[2]
    manifest = json.loads((root / "workers/api/schema/manifest.json").read_text(encoding="utf-8"))
    snapshot = root / "workers/api/schema" / manifest["snapshot"]
    payload = snapshot.read_bytes()
    assert len(payload) == manifest["bytes"]
    assert hashlib.sha256(payload).hexdigest() == manifest["sha256"]
    schema = json.loads(payload)
    assert schema["openapi"] == manifest["openapi"]
    assert schema["info"]["version"] == manifest["serviceVersion"]


@pytest.mark.asyncio
async def test_worker_exits_before_ws_client_when_all_startup_probes_fail(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    loaded = catalog(tmp_path, monkeypatch)

    async def no_models(*args: object, **kwargs: object) -> dict:
        return {}

    def unexpected_ws_client(*args: object, **kwargs: object) -> None:
        pytest.fail("WsClient must not be constructed without a validated chat model")

    monkeypatch.setattr(api_worker, "load_catalog", lambda path: loaded)
    monkeypatch.setattr(api_worker, "probe_models", no_models)
    monkeypatch.setattr(api_worker, "WsClient", unexpected_ws_client)

    await api_worker.run_worker()


@pytest.mark.asyncio
async def test_startup_probe_uses_configured_timeout(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    loaded = catalog(tmp_path, monkeypatch)
    seen: list[float] = []

    class RecordingClient:
        async def post(self, *args: object, timeout: float, **kwargs: object) -> object:
            seen.append(timeout)
            raise httpx.TimeoutException("slow", request=httpx.Request("POST", "https://example.test"))

    await api_worker.probe_models(loaded, api_worker.build_http_sender(loaded, RecordingClient()))
    assert seen == [5.0, 5.0]
