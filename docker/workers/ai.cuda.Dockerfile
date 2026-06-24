ARG AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:latest
FROM ${AI_BASE_IMAGE}

USER root

# Подключаем официальный репозиторий Nvidia и устанавливаем CUDA 12.4 + cuDNN 9
RUN curl -fSsL https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.1-1_all.deb -o /tmp/cuda-keyring.deb \
    && dpkg -i /tmp/cuda-keyring.deb \
    && rm /tmp/cuda-keyring.deb \
    && apt-get update && apt-get install -y --no-install-recommends \
        cuda-cudart-12-4 \
        cuda-cublas-12-4 \
        libcudnn9-cuda-12 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Переменные окружения для корректной работы Docker с NVIDIA контейнерами
ENV NVIDIA_VISIBLE_DEVICES=all \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility \
    LD_LIBRARY_PATH=/usr/local/cuda-12.4/lib64:${LD_LIBRARY_PATH}

# Настройки для работы с GPU по умолчанию
ENV WORKER_MODULE=workers.ai.worker \
    WHISPER_MODEL=base \
    WHISPER_DEVICE=cuda \
    WHISPER_COMPUTE_TYPE=float16 \
    EMBEDDING_DEVICE=cuda

USER app

HEALTHCHECK --interval=60s --timeout=15s --start-period=60s --retries=3 \
    CMD python3 -c "import faster_whisper; print('ok')" || exit 1

CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
