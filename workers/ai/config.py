"""Single source of configuration for the AI worker.

ALL `os.getenv` reads live here. Other modules import `load_config()` and read
typed fields off the returned dataclass — no scattered env access elsewhere.

Import is side-effect-free: nothing is read or validated at module load time.
Call `load_config()` (optionally `.validate()`) from the entry point. This keeps
`import workers.ai.config` safe in bare environments (tests, drift-scan subprocess).

External-API provider keys (OPENAI/GEMINI/CLAUDE, AI_STT_PROVIDER, AI_TTS_PROVIDER)
are intentionally absent — the worker runs local inference only.
LLM fields are intentionally absent — deferred to ai-worker-llm-text2text.
"""

from __future__ import annotations

import os
import tempfile
from dataclasses import dataclass, field
from pathlib import Path


def _getenv_int(name: str, default: int) -> int:
    raw = os.getenv(name)
    if raw is None or raw == "":
        return default
    try:
        return int(raw)
    except ValueError:
        raise ValueError(f"env {name}={raw!r} is not a valid integer")


def _getenv_bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


@dataclass(frozen=True)
class Config:
    # --- pull-API ---
    api_base_url: str
    worker_api_token: str
    worker_type: str
    poll_interval: int
    pull_enabled: bool
    work_dir: Path

    # --- STT (faster-whisper, local only) ---
    whisper_model: str
    whisper_device: str
    whisper_compute_type: str

    # --- streaming STT ---
    stream_window_sec: int
    stream_overlap_sec: int

    # --- TTS (espeak-ng / pyttsx3, local only) ---
    tts_engine: str

    # --- embedding (sentence-transformers, local only) ---
    embedding_model: str
    embedding_device: str

    @property
    def api_base(self) -> str:
        """API root with trailing slash stripped; all paths built as f'{api_base}/api/v1/...'."""
        return self.api_base_url.rstrip("/")

    def validate(self) -> None:
        """Raise ValueError on a config that cannot run a real poll loop.

        Only enforced when pull is actually enabled — a token-less idle worker
        (PULL_ENABLED=false) is a valid local-dev state and must not raise.
        """
        if self.pull_enabled and not self.worker_api_token:
            raise ValueError(
                "PULL_ENABLED=true but WORKER_API_TOKEN is empty — "
                "the worker cannot authenticate to the pull-API"
            )


def load_config() -> Config:
    """Build a Config from the environment. Pure read — does not validate or raise
    on a missing token (call .validate() at the point you actually need to claim)."""
    whisper_device = os.getenv("WHISPER_DEVICE", "cpu")
    return Config(
        api_base_url=os.getenv("API_BASE_URL", "http://localhost:8080"),
        worker_api_token=os.getenv("WORKER_API_TOKEN", ""),
        worker_type=os.getenv("WORKER_TYPE", "ai"),
        poll_interval=_getenv_int("POLL_INTERVAL", 10),
        pull_enabled=_getenv_bool("PULL_ENABLED", False),
        work_dir=Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve(),
        whisper_model=os.getenv("WHISPER_MODEL", "base"),
        whisper_device=whisper_device,
        whisper_compute_type=os.getenv("WHISPER_COMPUTE_TYPE", "int8"),
        stream_window_sec=_getenv_int("STREAM_WINDOW_SEC", 20),
        stream_overlap_sec=_getenv_int("STREAM_OVERLAP_SEC", 2),
        tts_engine=os.getenv("TTS_ENGINE", "espeak"),
        embedding_model=os.getenv("EMBEDDING_MODEL", "BAAI/bge-m3"),
        embedding_device=os.getenv("EMBEDDING_DEVICE", whisper_device),
    )
