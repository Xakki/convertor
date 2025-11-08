FROM linuxserver/libreoffice:latest

ARG PYTHONUNBUFFERED=1

ENV REFRESHED_AT="2025-11-07" \
    PYTHONUNBUFFERED=${PYTHONUNBUFFERED} \
    PORT=80

RUN apk add --no-cache \
      python3 py3-pip \
      htop \
      tzdata icu-data-full \
      ttf-dejavu ttf-liberation ttf-droid \
    && pip3 install --no-cache-dir aiohttp \
    && fc-cache -f

# Рабочая директория и файлы приложения
WORKDIR /app
COPY app /app

# Том для файлов
VOLUME ["/shared-files"]

# Запуск
CMD ["python3", "/app/main.py"]