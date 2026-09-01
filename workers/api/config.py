from __future__ import annotations

import hashlib
import json
import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import yaml

_SAFE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
_SAFE_COMPONENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")


def _mapping(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{label} must be a mapping")
    return value


def _only_keys(value: dict[str, Any], allowed: set[str], label: str) -> None:
    unknown = set(value) - allowed
    if unknown:
        raise ValueError(f"{label} contains unsupported keys: {', '.join(sorted(unknown))}")


def _safe_id(value: Any, label: str) -> str:
    if not isinstance(value, str) or not _SAFE_ID.fullmatch(value):
        raise ValueError(f"{label} must be a safe identifier")
    return value


def _safe_component(value: Any, label: str) -> str:
    if not isinstance(value, str) or not _SAFE_COMPONENT.fullmatch(value):
        raise ValueError(f"{label} must be a safe identifier component")
    return value


@dataclass(frozen=True)
class ModelConfig:
    key: str
    model_id: str
    label: str
    fallback: tuple[str, ...] = ()
    tiers: tuple[str, ...] = ()
    params: dict[str, Any] = field(default_factory=dict)
    operations: tuple[str, ...] = ("chat",)
    provider_id: str = ""
    base_url: str = ""
    endpoint: str = "/v1/chat/completions"
    bearer_token: str = ""
    timeout_sec: float = 15.0


@dataclass(frozen=True)
class OperationConfig:
    name: str
    endpoint: str
    pairs: tuple[tuple[str, str, str], ...] = ()
    request: dict[str, str] = field(default_factory=dict)
    response: dict[str, str] = field(default_factory=dict)
    startup_mode: str | None = None
    startup_timeout_sec: float | None = None


@dataclass(frozen=True)
class ManifestCapabilities:
    """Неизменяемый снимок проверенных возможностей провайдера."""

    model_ids: frozenset[str] = frozenset()
    pairs: frozenset[tuple[str, str, str]] = frozenset()
    has_model_allowlist: bool = False
    has_pair_allowlist: bool = False


@dataclass(frozen=True)
class Catalog:
    provider_id: str
    base_url: str
    bearer_token: str
    endpoint: str
    schema: str
    timeout_sec: float
    output_type: str
    default_model_key: str
    models: dict[str, ModelConfig]
    operations: dict[str, OperationConfig] = field(default_factory=dict)
    defaults: dict[str, str] = field(default_factory=dict)
    providers: dict[str, dict[str, Any]] = field(default_factory=dict)
    legacy_v1: bool = False

    def resolve_model(self, key: str | None) -> ModelConfig:
        resolved = key or self.default_model_key
        if resolved not in self.models:
            raise ValueError(f'model key "{resolved}" is not allowed')
        return self.models[resolved]

    def resolve_selector(self, selector: str | None, operation: str = "chat") -> ModelConfig:
        value = selector or self.defaults.get(operation) or self.default_model_key
        if value in self.models:
            return self.models[value]
        if "/" in value:
            provider, tier = value.split("/", 1)
            if provider not in self.providers and provider != self.provider_id:
                raise ValueError(f'provider "{provider}" is not configured')
            candidates = [m for m in self.models.values() if m.provider_id == provider and tier in m.tiers]
            if not candidates:
                raise ValueError(f'tier "{tier}" is not configured for provider "{provider}"')
            if len(candidates) > 1:
                raise ValueError(f'tier "{tier}" is ambiguous for provider "{provider}"')
            return candidates[0]
        candidates = [m for m in self.models.values() if value in m.tiers]
        if len(candidates) == 1:
            return candidates[0]
        if len(candidates) > 1:
            raise ValueError(f'model selector "{value}" is ambiguous across providers')
        raise ValueError(f'model selector "{value}" is not allowed')

    def request_params(self, selector: str | None, operation: str = "chat") -> dict[str, Any]:
        return dict(self.resolve_selector(selector, operation).params)

    def fallback_chain(self, key: str | None) -> list[ModelConfig]:
        chain: list[ModelConfig] = []
        seen: set[str] = set()
        def append(model_key: str) -> None:
            if model_key in seen:
                raise ValueError(f"fallback cycle contains model key {model_key}")
            seen.add(model_key)
            model = self.resolve_model(model_key)
            chain.append(model)
            for fallback_key in model.fallback:
                append(fallback_key)
        append(self.resolve_selector(key).key)
        return chain

    def public_models(self) -> list[dict[str, str]]:
        return [{"value": m.key, "label": m.label} for m in self.models.values()]

    def public_pairs(self) -> dict[str, list[str]]:
        pairs: dict[str, list[str]] = {}
        for operation in self.operations.values():
            if operation.name != "chat":
                continue
            for source, target, _ in operation.pairs:
                pairs.setdefault(source, []).append(target)
        return {source: list(dict.fromkeys(targets)) for source, targets in pairs.items()}


def _validate_schema_ref(schema: Any) -> str:
    if not isinstance(schema, str) or re.fullmatch(r"[a-z0-9][a-z0-9._-]*/v[0-9][A-Za-z0-9._-]*", schema) is None:
        raise ValueError("provider.schema must be a safe versioned schema reference")
    return schema


def _load_provider_schema(schema_root: Path, provider_id: str, schema_ref: str, *, legacy_v1: bool) -> ManifestCapabilities:
    manifest_path = schema_root / provider_id / "manifest.json"
    if legacy_v1:
        manifest_path = schema_root / "manifest.json"
    if not manifest_path.is_file():
        raise ValueError(f"schema manifest is missing for provider {provider_id}")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        required = {"provider", "source", "serviceVersion", "openapi", "snapshot", "sha256", "bytes"}
        if set(manifest) - required - {"provenance"} or not required <= set(manifest):
            raise ValueError("manifest has unsupported or missing keys")
        source = manifest["source"]
        parsed_source = urlparse(source) if isinstance(source, str) else None
        if manifest["provider"] != provider_id or parsed_source is None or parsed_source.scheme != "https" or not parsed_source.netloc or parsed_source.username or parsed_source.password or parsed_source.query or parsed_source.fragment:
            raise ValueError("manifest provider or source is invalid")
        if not isinstance(manifest["serviceVersion"], str) or not isinstance(manifest["openapi"], str):
            raise ValueError("manifest service version or OpenAPI metadata is invalid")
        if schema_ref != f"{provider_id}/v{manifest['serviceVersion']}":
            raise ValueError("provider-bound schema reference does not match manifest")
        provenance = manifest.get("provenance")
        if provenance is not None:
            if not isinstance(provenance, dict) or set(provenance) != {"interface", "contracts"}:
                raise ValueError("manifest provenance is invalid")
            if not isinstance(provenance["interface"], str) or not isinstance(provenance["contracts"], list) or not provenance["contracts"] or not all(isinstance(item, str) for item in provenance["contracts"]):
                raise ValueError("manifest provenance is invalid")
        snapshot = (manifest_path.parent / manifest["snapshot"]).resolve()
        if not snapshot.is_relative_to(manifest_path.parent.resolve()):
            raise ValueError("schema snapshot must be a safe relative path")
        payload = snapshot.read_bytes()
        if not re.fullmatch(r"[0-9a-f]{64}", manifest["sha256"]) or not isinstance(manifest["bytes"], int) or manifest["sha256"] != hashlib.sha256(payload).hexdigest() or manifest["bytes"] != len(payload):
            raise ValueError("schema snapshot checksum or provider mismatch")
        schema = json.loads(payload)
        if not isinstance(schema, dict) or schema.get("openapi") != manifest["openapi"] or schema.get("info", {}).get("version") != manifest["serviceVersion"]:
            raise ValueError("schema snapshot does not match manifest metadata")
        compatibility = schema.get("x-compatibility", {})
        if not isinstance(compatibility, dict):
            raise ValueError("schema compatibility metadata is invalid")
        models = compatibility.get("models")
        if models is None:
            model_ids = frozenset()
            has_model_allowlist = False
        elif not isinstance(models, list) or not models or not all(isinstance(item, dict) and isinstance(item.get("id"), str) and item["id"] for item in models):
            raise ValueError("schema model allowlist is invalid")
        else:
            model_ids = frozenset(item["id"] for item in models)
            has_model_allowlist = True
        pairs = compatibility.get("verified_pairs")
        if pairs is None:
            pair_values = frozenset()
            has_pair_allowlist = False
        elif not isinstance(pairs, list) or not pairs or not all(isinstance(item, dict) and isinstance(item.get("from"), str) and isinstance(item.get("to"), str) and item.get("from") and item.get("to") and item.get("response", "raw") in {"raw", "content"} for item in pairs):
            raise ValueError("schema pair allowlist is invalid")
        else:
            pair_values = frozenset((item["from"], item["to"], item.get("response", "raw")) for item in pairs)
            has_pair_allowlist = True
        return ManifestCapabilities(model_ids, pair_values, has_model_allowlist, has_pair_allowlist)
    except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        raise ValueError(f"schema manifest is missing or invalid: {exc}") from exc


def _parse_models(rows: Any, operation: str, *, provider_id: str = "", base_url: str = "", endpoint: str = "/v1/chat/completions", bearer_token: str = "", timeout_sec: float = 15.0, prefix: bool = False) -> dict[str, ModelConfig]:
    if isinstance(rows, list):
        if not rows or not all(isinstance(x, str) and _SAFE_ID.fullmatch(x) for x in rows):
            raise ValueError(f"{operation}.models must contain safe model identifiers")
        return {f"{provider_id}/{x}" if prefix else x: ModelConfig(f"{provider_id}/{x}" if prefix else x, x, x, operations=(operation,), provider_id=provider_id, base_url=base_url, endpoint=endpoint, bearer_token=bearer_token, timeout_sec=timeout_sec) for x in rows}
    if not isinstance(rows, dict) or not rows:
        raise ValueError(f"{operation}.models must be a non-empty mapping")
    result: dict[str, ModelConfig] = {}
    for key, raw in rows.items():
        key = _safe_component(key, "model key")
        row = _mapping(raw, f"model {key}")
        _only_keys(row, {"model", "label", "fallback", "tiers", "params", "operations"}, f"model {key}")
        model_id = _safe_id(row.get("model"), f"model {key}.model")
        label = row.get("label", key)
        if not isinstance(label, str) or not label:
            raise ValueError(f"model {key}.label must be non-empty")
        fallback = row.get("fallback", [])
        tiers = row.get("tiers", [])
        params = row.get("params", {})
        operations = row.get("operations", [operation])
        if not isinstance(fallback, list) or not all(isinstance(x, str) for x in fallback):
            raise ValueError(f"model {key}.fallback must be a list")
        if not isinstance(tiers, list) or not all(isinstance(x, str) and x for x in tiers):
            raise ValueError(f"model {key}.tiers must be a list")
        if not isinstance(params, dict) or any(not isinstance(k, str) for k in params):
            raise ValueError(f"model {key}.params must be an object")
        if not isinstance(operations, list) or not all(isinstance(x, str) for x in operations):
            raise ValueError(f"model {key}.operations must be a list")
        public_key = f"{provider_id}/{key}" if prefix else key
        result[public_key] = ModelConfig(public_key, model_id, label, tuple(fallback), tuple(tiers), dict(params), tuple(operations), provider_id, base_url, endpoint, bearer_token, timeout_sec)
    return result


def _parse_operation(name: str, raw: Any) -> OperationConfig:
    row = _mapping(raw, name)
    allowed = {"output_type", "endpoint", "models", "startup_check", "pairs", "request", "response"}
    _only_keys(row, allowed, name)
    endpoint = row.get("endpoint")
    if not isinstance(endpoint, str) or not endpoint.startswith("/"):
        raise ValueError(f"{name}.endpoint must be an absolute path")
    pairs: list[tuple[str, str, str]] = []
    for pair in row.get("pairs", []):
        p = _mapping(pair, f"{name}.pairs")
        _only_keys(p, {"from", "to", "response"}, f"{name}.pair")
        source, target = _safe_id(p.get("from"), "pair.from"), _safe_id(p.get("to"), "pair.to")
        response = p.get("response", "raw")
        if response not in {"raw", "content"}:
            raise ValueError("pair.response must be raw or content")
        pairs.append((source, target, response))
    request = row.get("request", {})
    response = row.get("response", {})
    if not isinstance(request, dict) or not isinstance(response, dict):
        raise ValueError(f"{name}.request/response must be objects")
    if name == "image_generation" and response.get("kind") not in {"bytes", "base64", "url"}:
        raise ValueError("image_generation.response.kind must be bytes, base64, or url")
    startup = row.get("startup_check")
    startup_mode = startup_timeout = None
    if startup is not None:
        startup = _mapping(startup, f"{name}.startup_check")
        _only_keys(startup, {"mode", "timeout_sec"}, f"{name}.startup_check")
        startup_mode = startup.get("mode")
        startup_timeout = startup.get("timeout_sec")
        if startup_mode not in {"chat_completion", "models"} or isinstance(startup_timeout, bool) or not isinstance(startup_timeout, (int, float)) or startup_timeout <= 0:
            raise ValueError(f"{name}.startup_check mode/timeout is invalid")
        startup_timeout = float(startup_timeout)
    return OperationConfig(name, endpoint, tuple(pairs), {str(k): str(v) for k, v in request.items()}, {str(k): str(v) for k, v in response.items()}, startup_mode, startup_timeout)


def load_catalog(path: Path, *, schema_root: Path | None = None) -> Catalog:
    try:
        raw = _mapping(yaml.safe_load(path.read_text(encoding="utf-8")), "catalog")
    except yaml.YAMLError as exc:
        raise ValueError("catalog is not valid YAML") from exc
    _only_keys(raw, {"version", "providers", "routing"}, "catalog")
    version = raw.get("version")
    if version not in {1, 2}:
        raise ValueError("catalog version must be 1 or 2")
    provider_rows = raw.get("providers")
    if not isinstance(provider_rows, list) or not provider_rows:
        raise ValueError("catalog requires at least one configured provider")
    if version == 1 and len(provider_rows) != 1:
        raise ValueError("version 1 requires exactly one provider")
    root = schema_root or (Path(__file__).resolve().parent / "schema")
    all_models: dict[str, ModelConfig] = {}
    all_operations: dict[str, OperationConfig] = {}
    provider_meta: dict[str, dict[str, Any]] = {}
    first: tuple[str, str, str, str, str, OperationConfig] | None = None
    for index, provider_raw in enumerate(provider_rows):
        provider = _mapping(provider_raw, f"provider[{index}]")
        _only_keys(provider, {"id", "kind", "base_url", "schema", "credentials", "operations"}, f"provider[{index}]")
        provider_id = _safe_component(provider.get("id"), "provider.id")
        if provider_id in provider_meta:
            raise ValueError(f"duplicate provider id {provider_id}")
        if version == 1 and (provider_id != "aip-g4f" or provider.get("kind") != "g4f"):
            raise ValueError("version 1 only supports the aip-g4f provider")
        schema = _validate_schema_ref(provider.get("schema"))
        if version == 1 and schema != "aip-g4f/v0.5":
            raise ValueError("schema reference is not declared by the manifest")
        manifest_capabilities = _load_provider_schema(root, provider_id, schema, legacy_v1=version == 1)
        base_url = provider.get("base_url")
        parsed = urlparse(str(base_url))
        if (version == 1 and (parsed.scheme != "https" or parsed.hostname != "aip.xakki.ru" or parsed.port not in (None, 443) or parsed.path or parsed.params or parsed.query or parsed.fragment)) or parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError("provider.base_url must be a clean HTTP(S) origin")
        credentials = _mapping(provider.get("credentials"), "credentials")
        _only_keys(credentials, {"bearer_env"}, "credentials")
        bearer_env = credentials.get("bearer_env")
        if version == 1 and bearer_env != "G4F_API_KEY":
            raise ValueError("credentials.bearer_env must be G4F_API_KEY")
        if not isinstance(bearer_env, str) or not re.fullmatch(r"[A-Z][A-Z0-9_]+", bearer_env):
            raise ValueError("credentials.bearer_env must be an environment variable name")
        token = os.getenv(bearer_env)
        if not token:
            raise ValueError(f"credential env {bearer_env} is not set")
        operations_raw = _mapping(provider.get("operations"), "operations")
        operations = {name: _parse_operation(name, value) for name, value in operations_raw.items()}
        if "chat" not in operations:
            raise ValueError("provider.operations.chat is required")
        chat = operations["chat"]
        for operation_name, operation in operations.items():
            raw_operation = _mapping(operations_raw[operation_name], operation_name)
            configured_models = raw_operation.get("models")
            if configured_models is not None and configured_models != {}:
                operation_models = _parse_models(
                    configured_models,
                    operation_name,
                    provider_id=provider_id,
                    base_url=str(base_url).rstrip("/"),
                    endpoint=operation.endpoint,
                    bearer_token=token,
                    timeout_sec=operation.startup_timeout_sec or 15.0,
                    prefix=False,
                )
                if manifest_capabilities.has_model_allowlist and any(
                    model.model_id not in manifest_capabilities.model_ids
                    for model in operation_models.values()
                ):
                    raise ValueError(
                        f"manifest model allowlist rejects configured model for provider {provider_id}"
                    )
            if manifest_capabilities.has_pair_allowlist and any(
                pair not in manifest_capabilities.pairs for pair in operation.pairs
            ):
                raise ValueError(
                    f"manifest pair allowlist rejects configured pair for provider {provider_id}"
                )
        if version == 1 and _mapping(operations_raw["chat"], "chat").get("output_type") != "ai":
            raise ValueError("chat.output_type must be ai")
        prefix = version == 2
        startup_timeout = chat.startup_timeout_sec or 15.0
        local_models = _parse_models(_mapping(operations_raw["chat"], "chat").get("models"), "chat", provider_id=provider_id, base_url=str(base_url).rstrip("/"), endpoint=chat.endpoint, bearer_token=token, timeout_sec=startup_timeout, prefix=prefix)
        for model in local_models.values():
            if prefix:
                fallback = tuple(f"{provider_id}/{key}" for key in model.fallback)
                model = ModelConfig(model.key, model.model_id, model.label, fallback, model.tiers, model.params, model.operations, model.provider_id, model.base_url, model.endpoint, model.bearer_token, model.timeout_sec)
                local_models[model.key] = model
            if any(key not in local_models for key in model.fallback):
                raise ValueError(f"model {model.key} fallback contains an unknown key")
        duplicate_keys = set(all_models).intersection(local_models)
        if duplicate_keys:
            raise ValueError(f"duplicate public model key(s): {', '.join(sorted(duplicate_keys))}")
        all_models.update(local_models)
        all_operations.update({f"{provider_id}/{name}" if prefix else name: operation for name, operation in operations.items()})
        provider_meta[provider_id] = {"base_url": str(base_url).rstrip("/"), "schema": schema}
        if first is None:
            first = (provider_id, str(base_url).rstrip("/"), token, schema, chat.endpoint, chat)
    routing = _mapping(raw.get("routing"), "routing")
    defaults = _mapping(routing.get("defaults"), "routing.defaults")
    default = defaults.get("chat")
    if not isinstance(default, str):
        raise ValueError("routing.defaults.chat must be a selector")
    assert first is not None
    provider_id, base_url, token, schema, endpoint, chat = first
    timeout = chat.startup_timeout_sec or 15.0
    catalog = Catalog(provider_id, base_url, token, endpoint, schema, timeout, "ai", default, all_models, all_operations, {str(k): str(v) for k, v in defaults.items()}, provider_meta, version == 1)
    try:
        catalog.resolve_selector(default)
    except ValueError as exc:
        raise ValueError("routing.defaults.chat must name an allowed model key") from exc
    for key in all_models:
        catalog.fallback_chain(key)
    return catalog
