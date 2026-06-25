"""Settings metadata + overlay persistence for the dev-server.

The effective config the dev-server runs on is:
    load_config()  (env defaults)   ⟕  overlay JSON (DEVSERVER_CONFIG_PATH)

The overlay maps ENV keys → values and survives restart (mounted volume). This
module is the single source for the editable-setting metadata (key → Config field,
type, group, apply-mode, enum options) consumed by GET/PUT /api/settings and used
to re-derive the effective `Config`.

Secrets (WORKER_API_TOKEN, LLM_MODEL_PATH) and infra keys (API_BASE_URL,
WORKER_TYPE, WORK_DIR) are intentionally absent — never exposed or editable.
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any

from workers.ai.config import Config, load_config

DEFAULT_CONFIG_PATH = "/data/devserver_settings.json"


def overlay_path() -> Path:
    """Resolve the overlay path at call time (env-overridable; testable)."""
    return Path(os.getenv("DEVSERVER_CONFIG_PATH", DEFAULT_CONFIG_PATH))


@dataclass(frozen=True)
class Setting:
    key: str           # ENV name (also the wire key)
    field: str         # corresponding Config dataclass field
    type: str          # bool | int | float | str | enum
    group: str
    apply: str         # hot | restart
    label: str | None = None
    options: tuple[str, ...] | None = None


# Authoritative grouping / apply-mode — mirrors the API contract list.
SETTINGS: tuple[Setting, ...] = (
    Setting("PULL_ENABLED", "pull_enabled", "bool", "pull", "hot", "Enable pull processing"),
    Setting("POLL_INTERVAL", "poll_interval", "int", "pull", "hot"),
    Setting("LLM_MAX_TOKENS", "llm_max_tokens", "int", "llm", "hot"),
    Setting("LLM_TEMPERATURE", "llm_temperature", "float", "llm", "hot"),
    Setting("LLM_SYSTEM_PROMPT", "llm_system_prompt", "str", "llm", "hot"),
    Setting("WHISPER_MODEL", "whisper_model", "enum", "stt", "restart",
            options=("tiny", "base", "small", "medium", "large")),
    Setting("WHISPER_DEVICE", "whisper_device", "enum", "stt", "restart",
            options=("cpu", "cuda", "mps")),
    Setting("WHISPER_COMPUTE_TYPE", "whisper_compute_type", "enum", "stt", "restart",
            options=("int8", "int16", "float16", "float32")),
    Setting("STREAM_WINDOW_SEC", "stream_window_sec", "int", "stt_stream", "restart"),
    Setting("STREAM_OVERLAP_SEC", "stream_overlap_sec", "int", "stt_stream", "restart"),
    Setting("TTS_ENGINE", "tts_engine", "enum", "tts", "restart",
            options=("espeak", "pyttsx3")),
    Setting("EMBEDDING_MODEL", "embedding_model", "str", "embedding", "restart"),
    Setting("EMBEDDING_DEVICE", "embedding_device", "enum", "embedding", "restart",
            options=("cpu", "cuda", "mps")),
    Setting("LLM_BACKEND", "llm_backend", "enum", "llm", "restart",
            options=("ollama", "llamacpp")),
    Setting("OLLAMA_URL", "ollama_url", "str", "llm", "restart"),
    Setting("OLLAMA_MODEL", "ollama_model", "str", "llm", "restart"),
)
SETTINGS_BY_KEY: dict[str, Setting] = {s.key: s for s in SETTINGS}


# --- overlay I/O -----------------------------------------------------------

def read_overlay() -> dict[str, Any]:
    """Load the overlay JSON; missing/corrupt file → empty overlay (env defaults)."""
    path = overlay_path()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return {}
    except (json.JSONDecodeError, OSError):
        return {}
    return data if isinstance(data, dict) else {}


def write_overlay(overlay: dict[str, Any]) -> None:
    path = overlay_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(overlay, ensure_ascii=False, indent=2), encoding="utf-8")


# --- value coercion / validation -------------------------------------------

def coerce_value(spec: Setting, value: Any) -> Any:
    """Coerce a wire value to the setting's type. Raises ValueError on a bad value."""
    if spec.type == "bool":
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)):
            return bool(value)
        if isinstance(value, str):
            return value.strip().lower() in ("1", "true", "yes", "on")
        raise ValueError(f"{spec.key} must be a boolean")
    if spec.type == "int":
        try:
            return int(value)
        except (TypeError, ValueError):
            raise ValueError(f"{spec.key} must be an integer")
    if spec.type == "float":
        try:
            return float(value)
        except (TypeError, ValueError):
            raise ValueError(f"{spec.key} must be a number")
    # str | enum
    coerced = str(value)
    if spec.type == "enum" and spec.options is not None and coerced not in spec.options:
        raise ValueError(f"{spec.key} must be one of {list(spec.options)}")
    return coerced


def effective_config(overlay: dict[str, Any] | None = None) -> Config:
    """env defaults (load_config) merged with the overlay → effective Config.

    Bad/unknown overlay entries are ignored (the overlay can outlive a code change).
    """
    base = load_config()
    if overlay is None:
        overlay = read_overlay()
    updates: dict[str, Any] = {}
    for key, value in overlay.items():
        spec = SETTINGS_BY_KEY.get(key)
        if spec is None:
            continue
        try:
            updates[spec.field] = coerce_value(spec, value)
        except ValueError:
            continue
    return replace(base, **updates) if updates else base


def settings_list(cfg: Config) -> list[dict[str, Any]]:
    """Render the metadata list with values from the effective config (GET shape)."""
    out: list[dict[str, Any]] = []
    for s in SETTINGS:
        item: dict[str, Any] = {
            "key": s.key,
            "value": getattr(cfg, s.field),
            "type": s.type,
            "group": s.group,
            "apply": s.apply,
        }
        if s.label:
            item["label"] = s.label
        if s.options is not None:
            item["options"] = list(s.options)
        out.append(item)
    return out
