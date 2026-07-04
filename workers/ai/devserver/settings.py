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
    help: str | None = None       # human description: what it controls, valid values, effect
    options: tuple[str, ...] | None = None
    help_url: str | None = None   # optional "where to find compatible models" link


# Authoritative grouping / apply-mode — mirrors the API contract list.
SETTINGS: tuple[Setting, ...] = (
    Setting("PULL_ENABLED", "pull_enabled", "bool", "pull", "hot",
            label="Enable pull processing",
            help="Master switch for the in-process pull loop. On = the dev-server "
                 "claims and converts jobs from the backend queue; off = idle. Applies instantly."),
    Setting("POLL_INTERVAL", "poll_interval", "int", "pull", "hot",
            label="Poll interval (s)",
            help="Seconds the pull loop waits between polls when the queue is empty. "
                 "Lower = more responsive, higher = less load. Integer seconds."),
    Setting("LLM_MAX_TOKENS", "llm_max_tokens", "int", "llm", "hot",
            label="Max tokens",
            help="Maximum tokens the LLM may generate per request. Higher = longer "
                 "answers but slower and more memory."),
    Setting("LLM_TEMPERATURE", "llm_temperature", "float", "llm", "hot",
            label="Temperature",
            help="LLM sampling temperature (0.0–2.0). Lower = deterministic/focused, "
                 "higher = more creative/random."),
    Setting("LLM_SYSTEM_PROMPT", "llm_system_prompt", "str", "llm", "hot",
            label="System prompt",
            help="Optional system prompt prepended to every LLM request to steer "
                 "tone/role. Empty = none."),
    Setting("WHISPER_MODEL", "whisper_model", "enum", "stt", "restart",
            label="Whisper model",
            help="faster-whisper model size for speech-to-text. Larger = more accurate "
                 "but slower and more RAM.",
            options=("tiny", "base", "small", "medium", "large"),
            help_url="https://huggingface.co/Systran"),
    Setting("WHISPER_DEVICE", "whisper_device", "enum", "stt", "restart",
            label="Whisper device",
            help="Compute device for Whisper. cpu = portable; cuda = NVIDIA GPU; mps = Apple Silicon.",
            options=("cpu", "cuda", "mps")),
    Setting("WHISPER_COMPUTE_TYPE", "whisper_compute_type", "enum", "stt", "restart",
            label="Compute type",
            help="Numeric precision for Whisper inference. int8 = fastest/smallest, "
                 "float32 = most accurate. int8 recommended on CPU.",
            options=("int8", "int16", "float16", "float32")),
    Setting("STREAM_WINDOW_SEC", "stream_window_sec", "int", "stt_stream", "restart",
            label="Window (s)",
            help="Устаревший псевдоним; реальный лимит длины сегмента — STREAM_SEGMENT_MAX_SEC."),
    Setting("STREAM_OVERLAP_SEC", "stream_overlap_sec", "int", "stt_stream", "restart",
            label="Overlap (s)",
            help="Секунды PCM-контекста, переносимые с конца предыдущего VAD-сегмента в начало "
                 "следующего; предотвращает обрыв слов на границе сегмента."),
    Setting("VAD_AGGRESSIVENESS", "vad_aggressiveness", "int", "stt_stream", "restart",
            label="VAD aggressiveness",
            help="Агрессивность webrtcvad (0–3): 0 = мягкий (меньше ложных тишин), "
                 "3 = жёсткий (активнее отфильтровывает нережимную речь)."),
    Setting("VAD_SILENCE_FRAMES", "vad_silence_frames", "int", "stt_stream", "restart",
            label="Silence frames",
            help="Сколько подряд тихих 30-мс фреймов считать концом речевого сегмента "
                 "(10 фреймов = 300 мс тишины)."),
    Setting("STREAM_SEGMENT_MAX_SEC", "stream_segment_max_sec", "int", "stt_stream", "restart",
            label="Max segment (s)",
            help="Максимальная длина одного VAD-сегмента в секундах; при достижении лимита "
                 "сегмент принудительно завершается, даже если речь продолжается."),
    Setting("TTS_ENGINE", "tts_engine", "enum", "tts", "restart",
            label="TTS engine",
            help="Text-to-speech backend. espeak = lightweight/offline; pyttsx3 = system voices.",
            options=("espeak", "pyttsx3")),
    Setting("EMBEDDING_MODEL", "embedding_model", "str", "embedding", "restart",
            label="Embedding model",
            help="HuggingFace sentence-transformers model id used to embed text into vectors.",
            help_url="https://huggingface.co/models?library=sentence-transformers&pipeline_tag=feature-extraction&sort=trending"),
    Setting("EMBEDDING_DEVICE", "embedding_device", "enum", "embedding", "restart",
            label="Embedding device",
            help="Compute device for the embedding model (cpu/cuda/mps).",
            options=("cpu", "cuda", "mps")),
    Setting("LLM_BACKEND", "llm_backend", "enum", "llm", "restart",
            label="LLM backend",
            help="Which LLM runtime to use. llamacpp = local GGUF in-process; "
                 "ollama = external Ollama server.",
            options=("ollama", "llamacpp")),
    Setting("LLM_MODEL_REPO", "llm_model_repo", "str", "llm", "restart",
            label="Model repo (GGUF)",
            help="HuggingFace GGUF repo id to download the LLM from (llamacpp backend).",
            help_url="https://huggingface.co/models?library=gguf&pipeline_tag=text-generation&sort=trending"),
    Setting("LLM_MODEL_FILE", "llm_model_file", "str", "llm", "restart",
            label="Model file (.gguf)",
            help="Specific .gguf filename within the repo to load (llamacpp backend)."),
    Setting("OLLAMA_URL", "ollama_url", "str", "llm", "restart",
            label="Ollama URL",
            help="Base URL of the Ollama server to call (ollama backend)."),
    Setting("OLLAMA_MODEL", "ollama_model", "str", "llm", "restart",
            label="Ollama model",
            help="Ollama model name/tag to run (ollama backend).",
            help_url="https://ollama.com/library"),
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
        if s.help:
            item["help"] = s.help
        if s.options is not None:
            item["options"] = list(s.options)
        if s.help_url:
            item["helpUrl"] = s.help_url
        out.append(item)
    return out
