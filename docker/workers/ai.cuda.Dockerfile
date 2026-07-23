# syntax=docker/dockerfile:1.7
# AI worker — CUDA/GPU flavor. Self-contained runnable image.
# Pulls the published code artifact from Harbor via COPY --from; installs its own
# Python + CUDA ML stack on top of the nvidia/cuda cuDNN runtime base.
# Python is installed EXACTLY ONCE (via apt on the nvidia/cuda Ubuntu base).
# LOCAL build on the GPU host — NOT published to Harbor.
#
# Build args:
#   AI_BASE_IMAGE    published code artifact (default: harbor.xakki.ru/convertor/worker-ai-base:latest)
#   CUDA_DEVEL_IMAGE CUDA devel image used to compile llama-cpp-python (default: nvidia/cuda:12.8.0-devel-ubuntu24.04)
#   CUDA_ARCH        GPU compute capability as integer (e.g. 86 for RTX 3080/3090).
#                    Wired into CMAKE_CUDA_ARCHITECTURES and TORCH_CUDA_ARCH_LIST.
#   TORCH_CUDA_ARCH  TORCH_CUDA_ARCH_LIST dotted form (e.g. "8.6" when CUDA_ARCH=86).
#                    Derived automatically in the Makefile via sed.
#   WITH_LLAMACPP    0 (default) | 1 — compile and install llama-cpp-python with CUDA.
#                    The llama.cpp code path (providers/llm.py) is always present (lazy import);
#                    the binary is gated by this flag to keep the default image smaller.
#
# Usage (via Makefile):
#   make build-ai-cuda                          # RTX 3080/3090, no llama.cpp binary
#   make build-ai-cuda CUDA_ARCH=75             # RTX 20xx / T4
#   make build-ai-cuda CUDA_ARCH=86 WITH_LLAMACPP=1   # with embedded llama.cpp GGUF
#
# Note: even when WITH_LLAMACPP=0 the devel stage pulls nvidia/cuda:*-devel (large
# image) but skips compilation entirely; the image is cached after the first pull.

# Global ARGs — must be declared before any FROM that references them
ARG AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:latest
ARG CUDA_DEVEL_IMAGE=nvidia/cuda:12.8.0-devel-ubuntu24.04

# ── Stage 1: optional llama.cpp CUDA compilation ─────────────────────────────
FROM ${CUDA_DEVEL_IMAGE} AS llamacpp-build

ARG WITH_LLAMACPP=0
ARG CUDA_ARCH=86

RUN mkdir -p /build/wheels && \
    if [ "$WITH_LLAMACPP" = "1" ]; then \
        apt-get update && \
        apt-get install -y --no-install-recommends \
            python3-full python3-pip build-essential cmake ninja-build && \
        rm -rf /var/lib/apt/lists/* && \
        CMAKE_ARGS="-DGGML_CUDA=on -DCMAKE_CUDA_ARCHITECTURES=${CUDA_ARCH}" \
        pip3 wheel --no-cache-dir --no-deps -w /build/wheels llama-cpp-python; \
    fi

# ── Stage 2: code artifact from Harbor ───────────────────────────────────────
FROM ${AI_BASE_IMAGE} AS aibase

# ── Stage 3: runtime — nvidia cuDNN + Python from apt ────────────────────────
FROM nvidia/cuda:12.8.0-cudnn-runtime-ubuntu24.04

ARG CUDA_ARCH=86
ARG WITH_LLAMACPP=0
# TORCH_CUDA_ARCH_LIST dotted form, e.g. "8.6" for CUDA_ARCH=86.
# Set automatically by Makefile; override manually if needed.
ARG TORCH_CUDA_ARCH=8.6

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface \
    PATH=/opt/venv/bin:$PATH \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    NVIDIA_VISIBLE_DEVICES=all \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
        python3-pip \
        tini \
        ffmpeg \
        espeak-ng \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# App user at a stable, host-matchable UID/GID (default 1000 so host bind mounts and
# the /data volume stay writable). Configurable via build args, e.g.
# `make build-ai-cuda APP_UID=$(id -u)`. The Ubuntu 24.04 CUDA base ships a default
# `ubuntu` user at UID 1000, so free any pre-existing occupant of the target uid/gid
# before creating `app` — keeps the RUN idempotent across Ubuntu and Debian bases.
ARG APP_UID=1000
ARG APP_GID=1000
RUN set -eux; \
    if u="$(getent passwd "${APP_UID}" | cut -d: -f1)"; [ -n "$u" ]; then userdel -r "$u" 2>/dev/null || true; fi; \
    if g="$(getent group "${APP_GID}" | cut -d: -f1)"; [ -n "$g" ]; then groupdel "$g" 2>/dev/null || true; fi; \
    groupadd -g "${APP_GID}" app; \
    useradd -m -u "${APP_UID}" -g "${APP_GID}" app; \
    mkdir -p /work /data /home/app/.cache/huggingface; \
    chown -R app:app /work /data /home/app

RUN python3 -m venv /opt/venv && \
    /opt/venv/bin/pip install --upgrade pip setuptools wheel

# Pull code + requirements from the Harbor code artifact
COPY --from=aibase /app /app

# CUDA-enabled PyTorch — bundles CUDA runtime; no separate nvidia/cuda runtime layer needed
RUN pip install --no-cache-dir torch \
    --index-url https://download.pytorch.org/whl/cu128

RUN TORCH_CUDA_ARCH_LIST="${TORCH_CUDA_ARCH}" \
    pip install --no-cache-dir \
    -r /app/requirements-ai-base.txt \
    -r /app/requirements-ai-ml.txt

# Install llama-cpp-python wheel if compiled in Stage 1 (empty dir is harmless)
COPY --from=llamacpp-build /build/wheels/ /tmp/llama_wheels/
RUN set -- /tmp/llama_wheels/*.whl; [ -f "$1" ] && pip install --no-cache-dir "$@" || true

WORKDIR /app

# Запекаем WORKER_TYPE в образ — этот образ ВСЕГДА ai-воркер, передавать флаг
# руками (bare `docker run`) не нужно; при желании всё ещё переопределим -e/compose.
ENV WORKER_TYPE=ai

# WHISPER_DEVICE/WHISPER_COMPUTE_TYPE больше НЕ запекаются — автоопределяются в
# workers/ai/config.py (_autodetect_device): cuda+float16, если torch видит GPU, иначе
# cpu+int8. Запечённый ENV замаскировал бы автодетект; переопределяются через -e/compose.

# Import-based healthcheck — образ самодостаточен вне compose (параметры согласованы
# с worker-ai в docker-compose.yml). Обязательно python3 (не python).
HEALTHCHECK --interval=60s --timeout=15s --start-period=60s --retries=3 \
    CMD python3 -c "import faster_whisper, webrtcvad, workers.common" || exit 1

USER app
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "-m", "workers.ai"]
