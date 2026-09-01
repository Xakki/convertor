import hashlib
import json
from pathlib import Path

import pytest

from workers.api.config import load_catalog
from workers.api.worker import capabilities


CONFIG = """
version: 2
providers:
  - id: gonka
    base_url: https://api.gonka.ai
    schema: gonka/v1
    credentials: {bearer_env: GONKA_API_KEY}
    operations:
      chat:
        endpoint: /v1/chat/completions
        pairs:
          - {from: txt, to: json_ai, response: raw}
          - {from: txt, to: txt_ai, response: content}
        models:
          normal:
            model: MiniMaxAI/MiniMax-M2.7
            label: Normal
            tiers: [normal, hard]
            params: {temperature: 0.2, reasoning_effort: medium}
      image_generation:
        endpoint: /v1/images/generations
        request: {prompt: prompt, size: size}
        response: {kind: base64, field: "data[0].b64_json"}
        pairs: [{from: txt, to: txt_ai, response: content}]
        models: {}
routing:
  defaults: {chat: gonka/normal}
""".strip()


GONKA_MANIFEST = {
    "models": [
        {"id": "MiniMaxAI/MiniMax-M2.7"},
        {"id": "deepseek-ai/DeepSeek-V4-Flash-0731"},
        {"id": "moonshotai/Kimi-K2.6"},
    ],
    "verified_pairs": [
        {"from": "txt", "to": "txt_ai", "response": "content"},
        {"from": "txt", "to": "json_ai", "response": "raw"},
    ],
}


def test_v2_catalog_resolves_provider_tier_and_fully_qualified_model(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG)
    _write_provider_schema(tmp_path / "schema", "gonka", compatibility=GONKA_MANIFEST)
    catalog = load_catalog(path, schema_root=tmp_path / "schema")
    assert catalog.resolve_selector("gonka/normal").model_id == "MiniMaxAI/MiniMax-M2.7"
    assert catalog.resolve_selector("gonka/hard").key == "gonka/normal"
    assert catalog.request_params("gonka/normal") == {"temperature": 0.2, "reasoning_effort": "medium"}


def test_v2_rejects_unsupported_gonka_image_generation_model_alone(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    image_config = CONFIG.replace(
        "models: {}",
        "models: {image: {model: arbitrary/image, label: Image}}",
    )
    path = tmp_path / "config.yaml"
    path.write_text(image_config)
    _write_provider_schema(tmp_path / "schema", "gonka", compatibility=GONKA_MANIFEST)

    with pytest.raises(ValueError, match="manifest model allowlist"):
        load_catalog(path, schema_root=tmp_path / "schema")


def test_v2_rejects_unsupported_gonka_image_generation_pair_alone(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    image_config = CONFIG.replace(
        "pairs: [{from: txt, to: txt_ai, response: content}]",
        "pairs: [{from: txt, to: png}]",
    )
    path = tmp_path / "config.yaml"
    path.write_text(image_config)
    _write_provider_schema(tmp_path / "schema", "gonka", compatibility=GONKA_MANIFEST)

    with pytest.raises(ValueError, match="manifest pair allowlist"):
        load_catalog(path, schema_root=tmp_path / "schema")


def test_v2_capabilities_only_advertise_configured_pairs(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG)
    _write_provider_schema(tmp_path / "schema", "gonka")
    catalog = load_catalog(path, schema_root=tmp_path / "schema")
    public = capabilities(catalog, catalog.models)
    assert public["matrix"] == {"txt": ["json_ai", "txt_ai"]}
    assert "png" not in public["matrix"].get("txt", [])


def test_v2_rejects_unknown_top_level_key(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG.replace("version: 2", "version: 2\nsecret: nope"))
    with pytest.raises(ValueError, match="unsupported keys"):
        load_catalog(path, schema_root=tmp_path / "schema")


def _write_provider_schema(
    root: Path,
    provider: str,
    schema_ref: str = "v1",
    compatibility: dict | None = None,
) -> None:
    provider_root = root / provider
    provider_root.mkdir(parents=True)
    schema = {"openapi": "3.1.0", "info": {"version": "1"}}
    if compatibility is not None:
        schema["x-compatibility"] = compatibility
    payload = json.dumps(schema).encode()
    snapshot = provider_root / "openapi.json"
    snapshot.write_bytes(payload)
    (provider_root / "manifest.json").write_text(json.dumps({
        "provider": provider, "source": f"https://{provider}.example/openapi.json",
        "serviceVersion": "1", "openapi": "3.1.0",
        "snapshot": "openapi.json", "sha256": hashlib.sha256(payload).hexdigest(),
        "bytes": len(payload),
    }))


def test_v2_loads_all_providers_and_publishes_only_their_pairs(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    monkeypatch.setenv("OTHER_API_KEY", "test-only")
    config = CONFIG.replace(
        "  - id: gonka\n", "  - id: gonka\n", 1
    ).replace(
        "routing:\n  defaults: {chat: gonka/normal}",
        "  - id: other\n    base_url: https://other.example\n    schema: other/v1\n    credentials: {bearer_env: OTHER_API_KEY}\n    operations:\n      chat:\n        endpoint: /v1/chat/completions\n        pairs: [{from: txt, to: other_ai, response: content}]\n        models: {fast: {model: other/fast, label: Other}}\nrouting:\n  defaults: {chat: gonka/normal}",
    )
    path = tmp_path / "config.yaml"
    path.write_text(config)
    _write_provider_schema(tmp_path / "schema", "gonka")
    _write_provider_schema(tmp_path / "schema", "other")
    catalog = load_catalog(path, schema_root=tmp_path / "schema")
    assert set(catalog.providers) == {"gonka", "other"}
    assert {model.key for model in catalog.models.values()} == {"gonka/normal", "other/fast"}
    assert catalog.public_pairs() == {"txt": ["json_ai", "txt_ai", "other_ai"]}


def test_v2_rejects_missing_provider_manifest(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG)
    with pytest.raises(ValueError, match="manifest"):
        load_catalog(path, schema_root=tmp_path / "schema")


def test_v2_does_not_fallback_to_legacy_root_manifest(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    schema_root = tmp_path / "schema"
    _write_provider_schema(schema_root, "legacy")
    (schema_root / "manifest.json").write_bytes((schema_root / "legacy" / "manifest.json").read_bytes())
    (schema_root / "openapi.json").write_bytes((schema_root / "legacy" / "openapi.json").read_bytes())
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG)
    with pytest.raises(ValueError, match="manifest"):
        load_catalog(path, schema_root=schema_root)


def test_v2_rejects_ambiguous_unqualified_tier(tmp_path, monkeypatch):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    monkeypatch.setenv("OTHER_API_KEY", "test-only")
    config = CONFIG.replace(
        "routing:\n  defaults: {chat: gonka/normal}",
        "  - id: other\n    base_url: https://other.example\n    schema: other/v1\n    credentials: {bearer_env: OTHER_API_KEY}\n    operations:\n      chat:\n        endpoint: /v1/chat/completions\n        pairs: [{from: txt, to: other_ai}]\n        models: {fast: {model: other/fast, label: Other, tiers: [normal]}}\nrouting:\n  defaults: {chat: gonka/normal}",
    )
    path = tmp_path / "config.yaml"
    path.write_text(config)
    _write_provider_schema(tmp_path / "schema", "gonka")
    _write_provider_schema(tmp_path / "schema", "other")
    catalog = load_catalog(path, schema_root=tmp_path / "schema")
    with pytest.raises(ValueError, match="ambiguous"):
        catalog.resolve_selector("normal")


@pytest.mark.parametrize("replacement", [
    ("id: gonka", "id: gonka/other"),
    ("normal:\n            model", "normal/extra:\n            model"),
])
def test_v2_rejects_public_key_components_that_could_collide(tmp_path, monkeypatch, replacement):
    monkeypatch.setenv("GONKA_API_KEY", "test-only")
    path = tmp_path / "config.yaml"
    path.write_text(CONFIG.replace(*replacement))
    _write_provider_schema(tmp_path / "schema", "gonka/other" if replacement[0] == "id: gonka" else "gonka")
    with pytest.raises(ValueError, match="safe identifier"):
        load_catalog(path, schema_root=tmp_path / "schema")
