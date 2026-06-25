# About
API service for converting various files to text, with Markdown support

# TODO List

- [ ] Docker compose environment
- [ ] Telegram auth


# AI Worker — GPU setup

The AI worker (`docker/workers/ai.cuda.Dockerfile`) is built locally on the GPU host.
Only the light base image (`ai-base.Dockerfile`) is published to Harbor.

## Choosing CUDA_ARCH for your NVIDIA GPU

Find your compute capability at build time:
```
nvidia-smi --query-gpu=compute_cap --format=csv,noheader
# e.g. "8.6" → strip the dot → CUDA_ARCH=86
```

| GPU series / card              | Architecture  | `CUDA_ARCH` |
|-------------------------------|---------------|-------------|
| GTX 10xx (1080/1070/1060)     | Pascal        | `61`        |
| RTX 20xx, T4                  | Turing        | `75`        |
| RTX 30xx (3060/3080/3090)     | Ampere        | `86`        |
| A100                          | Ampere        | `80`        |
| RTX 40xx (4070/4080/4090)     | Ada Lovelace  | `89`        |
| H100                          | Hopper        | `90`        |

## Build commands

```bash
# 1. Build + push the light base to Harbor (run on saFin or any builder with Harbor access):
make build-ai-base
make push-ai-base

# 2. Build the CUDA working image locally on the GPU host:
make build-ai-cuda CUDA_ARCH=86             # RTX 3080/3090
make build-ai-cuda CUDA_ARCH=75             # RTX 20xx / T4
make build-ai-cuda CUDA_ARCH=86 WITH_LLAMACPP=1  # with embedded llama.cpp binary

# 3. Build the CPU working image (for CI or CPU-only runs):
make build-ai-cpu
```

## Model sharing (avoid re-downloading weights)

Pass already-downloaded HuggingFace weights from the host:
```
-v ~/.cache/huggingface:/home/app/.cache/huggingface
```
For Ollama: use `-e OLLAMA_URL=http://host.docker.internal:11434` to point at host Ollama,
or `-v ~/.ollama:/root/.ollama` to share Ollama model weights directly.

---

# Service: libreoffice

Image: harbor.xakki.ru/library/libreoffice

Source: https://gitlab.com/Xakki/dockers-images

Этот код представляет собой асинхронный веб-сервер на aiohttp, предназначенный для конвертации документов с использованием внешней утилиты LibreOffice (soffice).

Сервер предоставляет 4 API-маршрута для двух различных сценариев:

Multipart Upload: Клиент загружает файл (.doc и т.п.) в теле HTTP-запроса. Сервер конвертирует его и немедленно отправляет сконвертированный файл (e.g., .docx или .txt) обратно в теле ответа.

Shared File: Клиент отправляет JSON-запрос, указывая имя файла, который уже существует на сервере в каталоге /shared-files/. Сервер конвертирует его и возвращает JSON-ответ с путем к новому, сконвертированному файлу.