"""Единственный источник конфигурации AI-воркера.

Все `os.getenv` читаются здесь. Остальные модули импортируют `load_config()` и
читают типизированные поля. Транспортная конфигурация (WORKER_ID, GATEWAY_WS_URL,
WORKER_API_TOKEN, WS-тюнинги, WORK_DIR) принадлежит `WsClientConfig.from_env()`
(workers/common/ws_client.py) и здесь НЕ дублируется.

Импорт не имеет побочных эффектов: ничего не читается и не валидируется на уровне
модуля. Вызывай `load_config()` (опционально `.validate()`) из точки входа — это
безопасно в тестах и drift-scan сабпроцессе.
"""

from __future__ import annotations

import os
import tempfile
from dataclasses import dataclass
from pathlib import Path

from workers.common.env import getenv_float, getenv_int

LLM_BACKENDS = ("ollama", "llamacpp")


def _autodetect_device() -> str:
    """Автоопределение устройства для Whisper/embedding: "cuda", если torch видит GPU,
    иначе "cpu". Ленивый импорт torch (тяжёлая зависимость, не нужна на CPU-путях, где
    torch может отсутствовать/быть битым) — любая ошибка импорта/детекта безопасно
    трактуется как отсутствие GPU."""
    try:
        import torch

        return "cuda" if torch.cuda.is_available() else "cpu"
    except Exception:  # noqa: BLE001 — любой сбой импорта/детекта → безопасный fallback
        return "cpu"


@dataclass(frozen=True)
class Config:
    # Рабочий каталог: временные файлы конвертации. Единственный источник WORK_DIR —
    # передаётся в WsClientConfig.from_env(work_dir=) явно; env не читается дважды.
    work_dir: Path

    # --- STT (faster-whisper, только локально) ---
    whisper_model: str
    whisper_device: str
    whisper_compute_type: str

    # --- потоковый STT ---
    stream_window_sec: int
    stream_overlap_sec: int
    vad_aggressiveness: int
    vad_silence_frames: int
    stream_segment_max_sec: int

    # --- TTS (espeak-ng / pyttsx3, только локально) ---
    tts_engine: str

    # --- embedding (sentence-transformers, только локально) ---
    embedding_model: str
    embedding_device: str

    # --- LLM text→text (только локально: ollama HTTP | встроенный llama.cpp) ---
    llm_backend: str
    ollama_url: str
    ollama_model: str
    llm_model_path: str
    llm_model_repo: str
    llm_model_file: str
    llm_max_tokens: int
    llm_temperature: float
    llm_system_prompt: str

    def validate(self) -> None:
        """Проверить AI-конфигурацию перед стартом.

        Транспортные параметры (GATEWAY_WS_URL, WORKER_API_TOKEN и пр.) валидирует
        WsClientConfig.validate() — здесь они не проверяются.
        """
        if self.llm_backend not in LLM_BACKENDS:
            raise ValueError(
                f"LLM_BACKEND={self.llm_backend!r} invalid — "
                f"must be one of {LLM_BACKENDS}"
            )
        if self.llm_backend == "llamacpp" and not (
            self.llm_model_path or (self.llm_model_repo and self.llm_model_file)
        ):
            raise ValueError(
                "LLM_BACKEND=llamacpp requires LLM_MODEL_PATH (local GGUF) or "
                "LLM_MODEL_REPO+LLM_MODEL_FILE (HuggingFace GGUF repo)"
            )


def load_config() -> Config:
    """Собрать Config из окружения. Чистое чтение — не валидирует и не поднимает исключений
    при отсутствующих ключах (вызывай .validate() там, где действительно нужно стартовать)."""
    whisper_device = os.getenv("WHISPER_DEVICE", "").strip() or _autodetect_device()
    whisper_compute_type = os.getenv("WHISPER_COMPUTE_TYPE", "").strip() or (
        "float16" if whisper_device == "cuda" else "int8"
    )
    return Config(
        work_dir=Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve(),
        whisper_model=os.getenv("WHISPER_MODEL", "base"),
        whisper_device=whisper_device,
        whisper_compute_type=whisper_compute_type,
        stream_window_sec=getenv_int("STREAM_WINDOW_SEC", 20),
        stream_overlap_sec=getenv_int("STREAM_OVERLAP_SEC", 2),
        vad_aggressiveness=getenv_int("VAD_AGGRESSIVENESS", 2),
        vad_silence_frames=getenv_int("VAD_SILENCE_FRAMES", 10),
        stream_segment_max_sec=getenv_int("STREAM_SEGMENT_MAX_SEC", 30),
        tts_engine=os.getenv("TTS_ENGINE", "espeak"),
        embedding_model=os.getenv("EMBEDDING_MODEL", "Qwen/Qwen3-Embedding-0.6B"),
        embedding_device=os.getenv("EMBEDDING_DEVICE", whisper_device),
        llm_backend=os.getenv("LLM_BACKEND", "llamacpp").strip().lower(),
        ollama_url=os.getenv("OLLAMA_URL", "http://localhost:11434"),
        ollama_model=os.getenv("OLLAMA_MODEL", "llama3.2"),
        llm_model_path=os.getenv("LLM_MODEL_PATH", ""),
        llm_model_repo=os.getenv("LLM_MODEL_REPO", "Qwen/Qwen2.5-0.5B-Instruct-GGUF"),
        llm_model_file=os.getenv("LLM_MODEL_FILE", "qwen2.5-0.5b-instruct-q4_k_m.gguf"),
        llm_max_tokens=getenv_int("LLM_MAX_TOKENS", 1024),
        llm_temperature=getenv_float("LLM_TEMPERATURE", 0.7),
        llm_system_prompt=os.getenv("LLM_SYSTEM_PROMPT", ""),
    )
