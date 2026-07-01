"""Text→text LLM — LOCAL inference only (Ollama HTTP | embedded llama.cpp).

No hosted API (OpenAI/Gemini/Claude removed in ai-worker-refactor-core). Two pluggable
backends selected by `LLM_BACKEND`:

  - ollama   (default): HTTP to a self-hosted Ollama server; models swap without
             rebuilding the image.
  - llamacpp: embedded `llama-cpp-python` over GGUF weights from a volume.

`llama_cpp` is imported LAZILY inside the llamacpp path only — importing this module
never requires the binary, so the ollama path has zero dependency on it.
"""

from __future__ import annotations

import asyncio

import httpx

from workers.ai.config import Config

# Ollama generation can take far longer than httpx's 5s default — use a generous
# read timeout but keep connect short so an unreachable server fails fast.
_OLLAMA_TIMEOUT = httpx.Timeout(connect=10.0, read=600.0, write=30.0, pool=10.0)


class OllamaProvider:
    """Calls a self-hosted Ollama server's /api/generate endpoint."""

    def __init__(
        self,
        url: str,
        model: str,
        max_tokens: int,
        temperature: float,
        system_prompt: str = "",
    ) -> None:
        self.url = url.rstrip("/")
        self.model = model
        self.max_tokens = max_tokens
        self.temperature = temperature
        self.system_prompt = system_prompt

    async def generate(self, prompt: str) -> str:
        payload: dict = {
            "model": self.model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "num_predict": self.max_tokens,
                "temperature": self.temperature,
            },
        }
        if self.system_prompt:
            payload["system"] = self.system_prompt

        async with httpx.AsyncClient(timeout=_OLLAMA_TIMEOUT) as client:
            resp = await client.post(f"{self.url}/api/generate", json=payload)
            resp.raise_for_status()
            data = resp.json()
        return str(data.get("response", "")).strip()


class LlamaCppProvider:
    """Embedded llama.cpp over GGUF weights. `llama_cpp` imported lazily on first use.

    Weights come from EITHER a local file (`model_path`) OR a HuggingFace GGUF repo
    (`model_repo` + `model_file`); the latter is downloaded into the HF cache on first
    use via `Llama.from_pretrained`. `model_repo` takes precedence when set.
    """

    def __init__(
        self,
        model_path: str,
        max_tokens: int,
        temperature: float,
        system_prompt: str = "",
        model_repo: str = "",
        model_file: str = "",
    ) -> None:
        self.model_path = model_path
        self.model_repo = model_repo
        self.model_file = model_file
        self.max_tokens = max_tokens
        self.temperature = temperature
        self.system_prompt = system_prompt
        self._llm = None  # lazily constructed Llama instance

    def _generate_sync(self, prompt: str) -> str:
        from llama_cpp import Llama  # lazy — only when the llamacpp backend runs

        if self._llm is None:
            if self.model_repo:
                self._llm = Llama.from_pretrained(
                    repo_id=self.model_repo,
                    filename=self.model_file,
                    n_ctx=4096,
                    verbose=False,
                )
            else:
                self._llm = Llama(model_path=self.model_path)

        messages = []
        if self.system_prompt:
            messages.append({"role": "system", "content": self.system_prompt})
        messages.append({"role": "user", "content": prompt})

        result = self._llm.create_chat_completion(
            messages=messages,
            max_tokens=self.max_tokens,
            temperature=self.temperature,
        )
        return str(result["choices"][0]["message"]["content"]).strip()

    async def generate(self, prompt: str) -> str:
        return await asyncio.to_thread(self._generate_sync, prompt)


def make_llm_provider(cfg: Config):
    """Build the LLM provider selected by cfg.llm_backend (ollama | llamacpp)."""
    if cfg.llm_backend == "ollama":
        return OllamaProvider(
            url=cfg.ollama_url,
            model=cfg.ollama_model,
            max_tokens=cfg.llm_max_tokens,
            temperature=cfg.llm_temperature,
            system_prompt=cfg.llm_system_prompt,
        )
    if cfg.llm_backend == "llamacpp":
        return LlamaCppProvider(
            model_path=cfg.llm_model_path,
            max_tokens=cfg.llm_max_tokens,
            temperature=cfg.llm_temperature,
            system_prompt=cfg.llm_system_prompt,
            model_repo=cfg.llm_model_repo,
            model_file=cfg.llm_model_file,
        )
    raise ValueError(f"unknown LLM_BACKEND {cfg.llm_backend!r} (expected ollama|llamacpp)")
